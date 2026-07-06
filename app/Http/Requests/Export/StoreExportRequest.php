<?php

namespace App\Http\Requests\Export;

use App\Models\Export;
use App\Support\Export\CanonicalInput;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Boundary validation of the export request in the traccia canonical format
 * (top-level format/date_from/date_to/sheets). Validation is permissive: nothing
 * per-sheet is required (sensible defaults live in ExportSpecParser); only the
 * validity of provided values is enforced against the source whitelists (plus
 * GROUP BY coherence), so no user string reaches SQL as an identifier.
 */
class StoreExportRequest extends FormRequest
{
    private const COUNT_STAR = '*';
    private const DATE_FILTERS = ['occurred_from', 'occurred_to'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Accepts the canonical top-level shape by wrapping `sheets`/`date_from`/
     * `date_to` under `params` (a structural move, not a semantic translation).
     */
    protected function prepareForValidation(): void
    {
        if (is_array($this->input('params'))) {
            return;
        }

        $sheets = $this->input('sheets');
        if (! is_array($sheets)) {
            return;
        }

        $params = ['sheets' => $sheets];
        if ($this->input('date_from') !== null) {
            $params['date_from'] = $this->input('date_from');
        }
        if ($this->input('date_to') !== null) {
            $params['date_to'] = $this->input('date_to');
        }

        $this->merge(['params' => $params]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'format' => ['sometimes', 'string', Rule::in([Export::FORMAT_XLSX])],
            'params' => ['sometimes', 'array'],
            'params.date_from' => ['sometimes', 'nullable', 'date'],
            'params.date_to' => ['sometimes', 'nullable', 'date'],
            'params.sheets' => ['sometimes', 'array', 'max:' . $this->maxSheets()],
            'params.sheets.*.name' => ['sometimes', 'string', 'max:31'],
            'params.sheets.*.source' => ['sometimes', 'string', Rule::in($this->sourceKeys())],
            'params.sheets.*.columns' => ['sometimes', 'array'],
            'params.sheets.*.metrics' => ['sometimes', 'array'],
            'params.sheets.*.group_by' => ['sometimes', 'array'],
            'params.sheets.*.sort' => ['sometimes', 'array'],
            'params.sheets.*.filters' => ['sometimes', 'array'],
        ];
    }

    /**
     * Source-dependent whitelist checks (columns/metrics/group_by/sort/filters).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $sheets = $this->input('params.sheets');
            if (! is_array($sheets)) {
                return;
            }

            foreach ($sheets as $index => $sheet) {
                if (is_array($sheet)) {
                    $this->validateSheet($validator, (int) $index, $sheet);
                }
            }
        });
    }

    /**
     * @param array<string, mixed> $sheet
     */
    private function validateSheet(Validator $validator, int $index, array $sheet): void
    {
        $source = config('gamindo.export.sources.' . CanonicalInput::resolveSource($sheet));
        if (! is_array($source)) {
            return;
        }

        $key = "params.sheets.{$index}";
        $fields = array_keys($source['fields'] ?? []);

        $this->validateColumns($validator, "{$key}.columns", $sheet['columns'] ?? [], $source);
        $this->validateMetrics($validator, "{$key}.metrics", $sheet['metrics'] ?? [], $source);
        $this->assertSubset($validator, "{$key}.group_by", $this->stripList($sheet['group_by'] ?? []), $fields);
        $this->validateGrouping($validator, "{$key}.columns", $sheet);
        $this->validateSort($validator, "{$key}.sort", $sheet['sort'] ?? [], $source['sort'] ?? []);
        $this->validateFilters($validator, "{$key}.filters", $sheet['filters'] ?? [], $source['filters'] ?? []);
    }

    /**
     * @param mixed $columns
     * @param array<string, mixed> $source
     */
    private function validateColumns(Validator $validator, string $key, $columns, array $source): void
    {
        if (! is_array($columns)) {
            return;
        }

        $fields = array_keys($source['fields'] ?? []);
        $aggregatable = $source['aggregatable'] ?? [];

        foreach ($columns as $column) {
            if (! is_array($column)) {
                $this->assertField($validator, $key, CanonicalInput::alias((string) $column), $fields);
                continue;
            }

            if (isset($column['fn'])) {
                $this->validateAggregate($validator, $key, $column, $aggregatable);
                continue;
            }

            $this->assertField($validator, $key, CanonicalInput::alias((string) ($column['field'] ?? '')), $fields);
        }
    }

    /**
     * @param mixed $metrics
     * @param array<string, mixed> $source
     */
    private function validateMetrics(Validator $validator, string $key, $metrics, array $source): void
    {
        if (! is_array($metrics)) {
            return;
        }

        $map = (array) config('gamindo.export.metric_aggregates', []);
        $aggregatable = $source['aggregatable'] ?? [];

        foreach ($metrics as $metric) {
            $metric = (string) $metric;
            if (! isset($map[$metric]['fn'])) {
                $validator->errors()->add($key, "Unsupported metric '{$metric}'.");
                continue;
            }

            $fn = (string) $map[$metric]['fn'];
            $field = isset($map[$metric]['field']) ? (string) $map[$metric]['field'] : self::COUNT_STAR;
            if (! isset($aggregatable[$fn]) || ! in_array($field, $aggregatable[$fn], true)) {
                $validator->errors()->add($key, "Metric '{$metric}' is not available on this source.");
            }
        }
    }

    /**
     * @param array<string, mixed> $column
     * @param array<string, array<int, string>> $aggregatable
     */
    private function validateAggregate(Validator $validator, string $key, array $column, array $aggregatable): void
    {
        $fn = (string) $column['fn'];
        if (! isset($aggregatable[$fn])) {
            $validator->errors()->add($key, "Unsupported aggregate '{$fn}'.");
            return;
        }

        $field = isset($column['field']) ? CanonicalInput::alias((string) $column['field']) : self::COUNT_STAR;
        if (! in_array($field, $aggregatable[$fn], true)) {
            $validator->errors()->add($key, "Aggregate '{$fn}' cannot be applied to '{$field}'.");
        }
    }

    /**
     * @param array<int, string> $allowed
     */
    private function assertField(Validator $validator, string $key, string $field, array $allowed): void
    {
        if (! in_array($field, $allowed, true)) {
            $validator->errors()->add($key, "Unsupported field '{$field}'.");
        }
    }

    /**
     * @param mixed $values
     * @param array<int, string> $allowed
     */
    private function assertSubset(Validator $validator, string $key, $values, array $allowed): void
    {
        if (! is_array($values)) {
            return;
        }

        foreach ($values as $value) {
            if (! in_array($value, $allowed, true)) {
                $validator->errors()->add($key, "Unsupported value '{$value}'.");
            }
        }
    }

    /**
     * ONLY_FULL_GROUP_BY coherence: when a sheet aggregates (metrics, group_by, or
     * an aggregate column), every explicit plain column must appear in group_by.
     *
     * @param array<string, mixed> $sheet
     */
    private function validateGrouping(Validator $validator, string $key, array $sheet): void
    {
        $columns = isset($sheet['columns']) && is_array($sheet['columns']) ? $sheet['columns'] : [];
        $groupBy = $this->stripList($sheet['group_by'] ?? []);
        $hasMetrics = isset($sheet['metrics']) && is_array($sheet['metrics']) && $sheet['metrics'] !== [];

        $plainFields = [];
        $hasAggregate = $hasMetrics;
        foreach ($columns as $column) {
            if (is_array($column) && isset($column['fn'])) {
                $hasAggregate = true;
                continue;
            }
            $field = is_array($column) ? (string) ($column['field'] ?? '') : (string) $column;
            $plainFields[] = CanonicalInput::alias($field);
        }

        if (! $hasAggregate && $groupBy === []) {
            return;
        }

        foreach ($plainFields as $field) {
            if (! in_array($field, $groupBy, true)) {
                $validator->errors()->add($key, "Column '{$field}' must be in group_by when the sheet aggregates.");
            }
        }
    }

    /**
     * @param mixed $sort
     * @param array<int, string> $allowedColumns
     */
    private function validateSort(Validator $validator, string $key, $sort, array $allowedColumns): void
    {
        if (! is_array($sort)) {
            return;
        }

        foreach ($sort as $entry) {
            $parsed = CanonicalInput::sortEntry($entry);

            if (! in_array($parsed['column'], $allowedColumns, true)) {
                $validator->errors()->add($key, "Unsupported sort column '{$parsed['column']}'.");
            }
            if (! in_array($parsed['direction'], ['asc', 'desc'], true)) {
                $validator->errors()->add($key, "Unsupported sort direction '{$parsed['direction']}'.");
            }
        }
    }

    /**
     * @param mixed $filters
     * @param array<string, array{column: string, op: string}> $allowed
     */
    private function validateFilters(Validator $validator, string $key, $filters, array $allowed): void
    {
        if (! is_array($filters)) {
            return;
        }

        foreach ($filters as $field => $value) {
            $field = CanonicalInput::alias((string) $field);
            if (! isset($allowed[$field])) {
                $validator->errors()->add($key, "Unsupported filter '{$field}'.");
                continue;
            }

            if (in_array($field, self::DATE_FILTERS, true) && strtotime((string) $value) === false) {
                $validator->errors()->add("{$key}.{$field}", "Filter '{$field}' must be a valid date.");
            }
        }
    }

    public function exportFormat(): string
    {
        return (string) $this->input('format', Export::FORMAT_XLSX);
    }

    /**
     * @return array<string, mixed>
     */
    public function exportParams(): array
    {
        return (array) $this->input('params', []);
    }

    /**
     * @param mixed $values
     * @return array<int, string>
     */
    private function stripList($values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $stripped = [];
        foreach ($values as $value) {
            $stripped[] = CanonicalInput::alias((string) $value);
        }

        return $stripped;
    }

    /**
     * @return array<int, string>
     */
    private function sourceKeys(): array
    {
        return array_keys((array) config('gamindo.export.sources', []));
    }

    private function maxSheets(): int
    {
        return (int) config('gamindo.export.max_sheets', 10);
    }
}
