<?php

namespace App\Support\Export\Sheet;

use App\Models\Export;
use App\Models\Import;
use Illuminate\Support\Carbon;

/**
 * Builds the opt-in narrative sheets (README, KPIs, Configurazione_Richiesta,
 * Data_Quality) from data the export already produced (sheet row counts, its own
 * params) plus the version's tracked ingestion history. No query-configurable
 * source: these are report sheets, not data sheets, so they stay in-memory.
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
            $rows[] = [$key, is_scalar($value) ? $value : json_encode($value)];
        }

        return new InMemorySheet('Configurazione_Richiesta', ['Parameter', 'Value'], $rows);
    }

    private function dataQuality(Export $export): Sheet
    {
        $rows = [];
        $imports = Import::where('version_id', $export->version_id)->orderBy('id')->get();
        foreach ($imports as $import) {
            $rows[] = [
                $import->uuid,
                $import->type,
                $import->status,
                (int) $import->processed_rows,
                (int) $import->inserted,
                (int) $import->duplicates,
                (int) $import->failed,
            ];
        }

        $header = ['Import ID', 'Type', 'Status', 'Processed', 'Inserted', 'Duplicates', 'Failed'];

        return new InMemorySheet('Data_Quality', $header, $rows);
    }
}
