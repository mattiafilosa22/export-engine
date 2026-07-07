<?php

namespace Tests\Unit\Export;

use App\Models\Export;
use App\Models\Import;
use App\Models\Version;
use App\Support\Export\Sheet\SummarySheetBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SummarySheetBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_readme_lists_export_metadata_and_included_sheets(): void
    {
        $export = $this->export(['sheets' => [['name' => 'Players']]]);

        $sheet = (new SummarySheetBuilder())->build($export, ['Players' => 5, 'Events' => 10])['readme'];

        $this->assertSame('README', $sheet->name());
        $rows = (array) $sheet->rows();
        $this->assertContains(['Export ID', $export->uuid], $rows);
        $this->assertContains(['Sheets included', 'Players, Events'], $rows);
    }

    public function test_kpis_lists_each_sheet_count_and_the_total(): void
    {
        $export = $this->export([]);

        $sheet = (new SummarySheetBuilder())->build($export, ['Players' => 5, 'Events' => 10])['kpis'];

        $rows = (array) $sheet->rows();
        $this->assertContains(['Players', 5], $rows);
        $this->assertContains(['Events', 10], $rows);
        $this->assertContains(['Total', 15], $rows);
    }

    public function test_configuration_dumps_scalar_params_as_is_and_arrays_as_json(): void
    {
        $export = $this->export(['date_from' => '2026-01-01', 'sheets' => [['name' => 'Players']]]);

        $sheet = (new SummarySheetBuilder())->build($export, [])['configuration'];

        $rows = (array) $sheet->rows();
        $this->assertContains(['date_from', '2026-01-01'], $rows);
        $this->assertContains(['sheets', json_encode([['name' => 'Players']])], $rows);
    }

    public function test_data_quality_lists_the_versions_imports(): void
    {
        $export = $this->export([]);
        Import::factory()->create([
            'version_id' => $export->version_id,
            'status' => Import::STATUS_COMPLETED,
            'processed_rows' => 10,
            'inserted' => 8,
            'duplicates' => 1,
            'failed' => 1,
        ]);

        $sheet = (new SummarySheetBuilder())->build($export, [])['dataQuality'];

        $rows = (array) $sheet->rows();
        $this->assertCount(1, $rows);
        $this->assertSame([Import::TYPE_PLAYERS, Import::STATUS_COMPLETED, 10, 8, 1, 1], array_slice($rows[0], 1));
    }

    public function test_data_quality_is_empty_when_the_version_has_no_imports(): void
    {
        $export = $this->export([]);

        $sheet = (new SummarySheetBuilder())->build($export, [])['dataQuality'];

        $this->assertSame(0, $sheet->count());
    }

    /**
     * @param array<string, mixed> $params
     */
    private function export(array $params): Export
    {
        $version = Version::factory()->create();

        return Export::factory()->create(['version_id' => $version->id, 'params' => $params]);
    }
}
