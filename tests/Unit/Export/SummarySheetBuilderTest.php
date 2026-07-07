<?php

namespace Tests\Unit\Export;

use App\Models\Event;
use App\Models\Export;
use App\Models\Player;
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

    public function test_configuration_dumps_scalar_params_as_is(): void
    {
        $export = $this->export(['date_from' => '2026-01-01', 'include_summary' => true]);

        $sheet = (new SummarySheetBuilder())->build($export, [])['configuration'];

        $rows = (array) $sheet->rows();
        $this->assertContains(['date_from', '2026-01-01'], $rows);
        $this->assertContains(['include_summary', true], $rows);
    }

    public function test_configuration_parses_sheets_into_one_readable_row_per_field(): void
    {
        $export = $this->export(['sheets' => [
            [
                'name' => 'Players',
                'source' => 'players',
                'columns' => ['player_id', 'email'],
                'filters' => ['language' => 'it'],
                'sort' => ['total_score:desc'],
            ],
        ]]);

        $sheet = (new SummarySheetBuilder())->build($export, [])['configuration'];

        $rows = (array) $sheet->rows();
        $this->assertContains(['Sheet 1 (Players) - source', 'players'], $rows);
        $this->assertContains(['Sheet 1 (Players) - columns', 'player_id, email'], $rows);
        $this->assertContains(['Sheet 1 (Players) - filters', 'language: it'], $rows);
        $this->assertContains(['Sheet 1 (Players) - sort', 'total_score:desc'], $rows);
        // No raw JSON blob for the whole array left behind.
        $this->assertNotContains(['sheets', json_encode($export->params['sheets'])], $rows);
    }

    public function test_data_quality_lists_each_check_with_severity_and_occurrences(): void
    {
        $version = Version::factory()->create();
        $player = Player::factory()->create(['version_id' => $version->id]);
        Event::factory()->forPlayer($player)->create(['payload' => []]);
        $export = Export::factory()->create(['version_id' => $version->id, 'params' => []]);

        $sheet = (new SummarySheetBuilder())->build($export, [])['dataQuality'];

        $this->assertSame(['Check', 'Severity', 'Occurrences', 'Description'], $sheet->header());
        $rows = (array) $sheet->rows();
        $this->assertContains(['empty_payload', 'info', 1, 'Eventi con payload JSON vuoto.'], $rows);
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
