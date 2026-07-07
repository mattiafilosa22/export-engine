<?php

namespace App\Support\Export;

use App\Models\Export;
use App\Support\Export\Spec\ExportSpec;
use App\Support\Export\Spec\SheetColumn;
use App\Support\Export\Spec\SheetSpec;

/**
 * Interprets an export's already-validated `params` (the traccia canonical
 * format) into typed SheetSpecs. There are no predefined sheets: the client
 * composes each one. This layer only shapes validated input and applies sensible
 * defaults (source from name/default, auto sheet name, metrics -> aggregate
 * columns, "payload." dot-notation, "col:dir" sort strings, top-level date range
 * -> per-sheet occurred_from/to on event sources). Whitelist enforcement is upstream.
 */
class ExportSpecParser
{
    private const DATE_FROM = 'occurred_from';
    private const DATE_TO = 'occurred_to';

    public function parse(Export $export): ExportSpec
    {
        $exportDate = $export->created_at !== null ? $export->created_at->format('Y-m-d') : 'export';

        return $this->parseParams((array) $export->params, $exportDate);
    }

    /**
     * Parses already-validated params (canonical format) into typed SheetSpecs,
     * decoupled from a persisted Export so the preview endpoint can reuse it.
     *
     * @param array<string, mixed> $params
     */
    public function parseParams(array $params, string $exportDate): ExportSpec
    {
        $sheets = isset($params['sheets']) && is_array($params['sheets']) ? $params['sheets'] : [];
        $dateFrom = isset($params['date_from']) ? (string) $params['date_from'] : null;
        $dateTo = isset($params['date_to']) ? (string) $params['date_to'] : null;

        if ($sheets === []) {
            return new ExportSpec([$this->defaultSheet()]);
        }

        $specs = [];
        foreach ($sheets as $index => $sheet) {
            $specs[] = $this->parseSheet((array) $sheet, (int) $index, $exportDate, $dateFrom, $dateTo);
        }

        return new ExportSpec($specs);
    }

    /**
     * @param array<string, mixed> $sheet
     */
    private function parseSheet(array $sheet, int $index, string $exportDate, ?string $from, ?string $to): SheetSpec
    {
        $source = CanonicalInput::resolveSource($sheet);
        $name = isset($sheet['name']) ? (string) $sheet['name'] : $this->autoName($exportDate, $index);

        return new SheetSpec(
            $name,
            $source,
            $this->buildColumns($sheet, $source),
            $this->buildFilters($sheet, $source, $from, $to),
            $this->buildGroupBy($sheet),
            $this->parseSort(isset($sheet['sort']) && is_array($sheet['sort']) ? $sheet['sort'] : [])
        );
    }

    /**
     * Output columns = plain columns + metric aggregates. Plain columns are the
     * explicit `columns`, else (when aggregating) the group_by dimensions, else
     * the source default columns.
     *
     * @param array<string, mixed> $sheet
     * @return array<int, SheetColumn>
     */
    private function buildColumns(array $sheet, string $source): array
    {
        $metrics = isset($sheet['metrics']) && is_array($sheet['metrics']) ? $sheet['metrics'] : [];
        $groupBy = $this->buildGroupBy($sheet);

        if (isset($sheet['columns']) && is_array($sheet['columns']) && $sheet['columns'] !== []) {
            $plain = $this->parseColumns($sheet['columns']);
        } elseif ($metrics !== [] || $groupBy !== []) {
            $plain = $this->plainColumns($groupBy);
        } else {
            $plain = $this->parseColumns((array) config("gamindo.export.sources.{$source}.default_columns", []));
        }

        return array_merge($plain, $this->metricColumns($metrics));
    }

    /**
     * @param array<int, mixed> $columns
     * @return array<int, SheetColumn>
     */
    private function parseColumns(array $columns): array
    {
        $parsed = [];
        foreach ($columns as $column) {
            $parsed[] = $this->parseColumn($column);
        }

        return $parsed;
    }

    /**
     * @param mixed $column
     */
    private function parseColumn($column): SheetColumn
    {
        if (! is_array($column)) {
            $field = CanonicalInput::alias((string) $column);
            return SheetColumn::plain($field, $field);
        }

        $field = isset($column['field']) ? CanonicalInput::alias((string) $column['field']) : null;

        if (isset($column['fn'])) {
            $fn = (string) $column['fn'];
            $label = isset($column['as']) ? (string) $column['as'] : $fn . '_' . ($field ?? 'all');
            return SheetColumn::aggregate($field, $fn, $label);
        }

        $field = $field ?? '';
        return SheetColumn::plain($field, isset($column['as']) ? (string) $column['as'] : $field);
    }

    /**
     * @param array<int, string> $aliases
     * @return array<int, SheetColumn>
     */
    private function plainColumns(array $aliases): array
    {
        $columns = [];
        foreach ($aliases as $alias) {
            $columns[] = SheetColumn::plain($alias, $alias);
        }

        return $columns;
    }

    /**
     * @param array<int, mixed> $metrics
     * @return array<int, SheetColumn>
     */
    private function metricColumns(array $metrics): array
    {
        $map = (array) config('gamindo.export.metric_aggregates', []);
        $columns = [];
        foreach ($metrics as $metric) {
            $metric = (string) $metric;
            if (isset($map[$metric]['fn'])) {
                $field = isset($map[$metric]['field']) ? (string) $map[$metric]['field'] : null;
                $columns[] = SheetColumn::aggregate($field, (string) $map[$metric]['fn'], $metric);
                continue;
            }

            // Unknown metric: emit as a plain field so upstream validation flags it.
            $columns[] = SheetColumn::plain($metric, $metric);
        }

        return $columns;
    }

    /**
     * Sheet filters + top-level date range mapped to occurred_from/to when the
     * source supports them (event-based sources).
     *
     * @param array<string, mixed> $sheet
     * @return array<string, mixed>
     */
    private function buildFilters(array $sheet, string $source, ?string $from, ?string $to): array
    {
        $filters = [];
        if (isset($sheet['filters']) && is_array($sheet['filters'])) {
            foreach ($sheet['filters'] as $key => $value) {
                $filters[CanonicalInput::alias((string) $key)] = $value;
            }
        }

        $sourceFilters = (array) config("gamindo.export.sources.{$source}.filters", []);
        if ($from !== null && isset($sourceFilters[self::DATE_FROM]) && ! isset($filters[self::DATE_FROM])) {
            $filters[self::DATE_FROM] = $from;
        }
        if ($to !== null && isset($sourceFilters[self::DATE_TO]) && ! isset($filters[self::DATE_TO])) {
            $filters[self::DATE_TO] = $to;
        }

        return $filters;
    }

    /**
     * @param array<string, mixed> $sheet
     * @return array<int, string>
     */
    private function buildGroupBy(array $sheet): array
    {
        if (! isset($sheet['group_by']) || ! is_array($sheet['group_by'])) {
            return [];
        }

        $aliases = [];
        foreach ($sheet['group_by'] as $alias) {
            $aliases[] = CanonicalInput::alias((string) $alias);
        }

        return $aliases;
    }

    /**
     * Accepts "col:dir" strings and {column, direction} objects.
     *
     * @param array<int, mixed> $sort
     * @return array<int, array{column: string, direction: string}>
     */
    private function parseSort(array $sort): array
    {
        $normalized = [];
        foreach ($sort as $entry) {
            $parsed = CanonicalInput::sortEntry($entry);
            if ($parsed['column'] === '') {
                continue; // malformed object without a column
            }

            $normalized[] = [
                'column' => $parsed['column'],
                'direction' => $parsed['direction'] === 'desc' ? 'desc' : 'asc',
            ];
        }

        return $normalized;
    }

    private function autoName(string $exportDate, int $index): string
    {
        return $exportDate . ' — Foglio ' . ($index + 1);
    }

    private function defaultSheet(): SheetSpec
    {
        $defaults = (array) config('gamindo.export.sources.' . CanonicalInput::DEFAULT_SOURCE . '.default_columns', []);

        return new SheetSpec(
            ucfirst(CanonicalInput::DEFAULT_SOURCE),
            CanonicalInput::DEFAULT_SOURCE,
            $this->parseColumns($defaults)
        );
    }
}
