<?php

namespace App\Support\Export\Sheet;

use App\Models\Export;
use Illuminate\Support\Carbon;

/**
 * Builds the opt-in narrative sheets (README, KPIs, Configurazione_Richiesta,
 * Data_Quality) from data the export already produced (sheet row counts, its own
 * params) plus data-quality checks run against the version. No
 * query-configurable source: these are report sheets, not data sheets, so
 * they stay in-memory.
 */
class SummarySheetBuilder
{
    /**
     * @param array<string, int> $sheetCounts sheet name => row count, for the real
     *     (query) sheets already built for this export
     * @return array{readme: Sheet, kpis: Sheet, configuration: Sheet, dataQuality: Sheet}
     */
    public function build(Export $export, array $sheetCounts): array
    {
        return [
            'readme' => $this->readme($export, $sheetCounts),
            'kpis' => $this->kpis($export, $sheetCounts),
            'configuration' => $this->configuration($export),
            'dataQuality' => $this->dataQuality($export),
        ];
    }

    /**
     * @param array<string, int> $sheetCounts
     */
    private function readme(Export $export, array $sheetCounts): Sheet
    {
        $rows = [
            ['Export ID', $export->uuid],
            ['Version ID', $export->version_id],
            ['Generated at', Carbon::now()->toIso8601String()],
            ['Format', $export->format],
            ['Sheets included', implode(', ', array_keys($sheetCounts))],
        ];

        return new InMemorySheet('README', ['Label', 'Value'], $rows);
    }

    /**
     * @param array<string, int> $sheetCounts
     */
    private function kpis(Export $export, array $sheetCounts): Sheet
    {
        $rows = [];
        $total = 0;
        foreach ($sheetCounts as $name => $count) {
            $rows[] = [$name, $count];
            $total += $count;
        }
        $rows[] = ['Total', $total];
        $rows[] = ['Format', $export->format];
        $rows[] = ['Generated at', Carbon::now()->toIso8601String()];

        return new InMemorySheet('KPIs', ['Metric', 'Value'], $rows);
    }

    private function configuration(Export $export): Sheet
    {
        $rows = [];
        foreach ((array) $export->params as $key => $value) {
            if ($key === 'sheets' && is_array($value)) {
                $rows = array_merge($rows, $this->sheetConfigRows($value));
                continue;
            }
            $rows[] = [$key, is_scalar($value) ? $value : json_encode($value)];
        }

        return new InMemorySheet('Configurazione_Richiesta', ['Parameter', 'Value'], $rows);
    }

    /**
     * Parses the `sheets` param into one row per known sheet-spec field (the
     * same fields StoreExportRequest already validates), instead of a single
     * unreadable JSON blob.
     *
     * @param array<int, mixed> $sheets
     * @return array<int, array{0: string, 1: scalar}>
     */
    private function sheetConfigRows(array $sheets): array
    {
        $rows = [];
        foreach ($sheets as $index => $sheet) {
            if (! is_array($sheet)) {
                continue;
            }

            $label = 'Sheet ' . ($index + 1) . (isset($sheet['name']) ? " ({$sheet['name']})" : '');
            foreach (['source', 'columns', 'group_by', 'metrics', 'sort', 'filters'] as $field) {
                if (isset($sheet[$field])) {
                    $rows[] = ["{$label} - {$field}", $this->formatSheetValue($sheet[$field])];
                }
            }
        }

        return $rows;
    }

    /**
     * Renders a sheet-spec field value on one line: scalars as-is, lists as a
     * comma-joined string, associative arrays as "key: value" pairs. Anything
     * still nested (e.g. an aggregate column object) falls back to JSON for
     * just that one item — never the whole spec.
     *
     * @param mixed $value
     * @return scalar
     */
    private function formatSheetValue($value)
    {
        if (is_scalar($value)) {
            return $value;
        }
        if (! is_array($value)) {
            return json_encode($value);
        }

        $parts = [];
        foreach ($value as $key => $item) {
            $rendered = is_scalar($item) ? (string) $item : json_encode($item);
            $parts[] = is_int($key) ? $rendered : "{$key}: {$rendered}";
        }

        return implode(', ', $parts);
    }

    private function dataQuality(Export $export): Sheet
    {
        $checks = (new DataQualityChecker())->run((int) $export->version_id);

        $rows = [];
        foreach ($checks as $check) {
            $rows[] = [$check['check'], $check['severity'], $check['occurrences'], $check['description']];
        }

        $header = ['Check', 'Severity', 'Occurrences', 'Description'];

        return new InMemorySheet('Data_Quality', $header, $rows);
    }
}
