<?php

namespace Tests\Feature\Export;

use App\Models\Event;
use App\Models\Export;
use App\Models\Player;
use App\Models\Version;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ExportSummarySheetsTest extends ExportTestCase
{
    public function test_include_summary_adds_the_four_narrative_sheets(): void
    {
        Storage::fake('local');
        $version = Version::factory()->create();
        $player = Player::factory()->create(['version_id' => $version->id]);
        Event::factory()->forPlayer($player)->count(3)->create(['type' => 'opened']);

        $response = $this->postJson("/api/v1/versions/{$version->uuid}/exports", [
            'sheets' => [['name' => 'Events', 'source' => 'events']],
            'include_summary' => true,
        ]);
        $response->assertStatus(202);
        $this->work();

        $export = $version->exports()->firstOrFail();
        $this->assertSame(Export::STATUS_COMPLETED, $export->status);

        $sheets = $this->readSheets($export);
        $this->assertSame(
            ['README', 'KPIs', 'Configurazione_Richiesta', 'Events', 'Data_Quality'],
            array_keys($sheets)
        );
        $this->assertSame(['Label', 'Value'], $sheets['README'][0]);
        $this->assertSame(['Metric', 'Value'], $sheets['KPIs'][0]);
        $this->assertSame(['Parameter', 'Value'], $sheets['Configurazione_Richiesta'][0]);
    }

    public function test_it_omits_the_summary_sheets_by_default(): void
    {
        Storage::fake('local');
        $version = Version::factory()->create();
        $player = Player::factory()->create(['version_id' => $version->id]);
        Event::factory()->forPlayer($player)->count(3)->create();

        $this->postJson("/api/v1/versions/{$version->uuid}/exports", [
            'sheets' => [['name' => 'Events', 'source' => 'events']],
        ])->assertStatus(202);
        $this->work();

        $export = $version->exports()->firstOrFail();
        $sheets = $this->readSheets($export);

        $this->assertSame(['Events'], array_keys($sheets));
    }

    /**
     * Regression guard: include_summary must not re-run count() on the real
     * (query) sheets — the KPIs sheet reuses the counts already computed for the
     * progress total, it never re-queries them. Data_Quality's own 3 checks
     * (DataQualityChecker) each run their own count() — that's expected, not
     * a double-count of the real sheet.
     */
    public function test_include_summary_does_not_double_count_the_real_sheets(): void
    {
        Storage::fake('local');
        $version = Version::factory()->create();
        $player = Player::factory()->create(['version_id' => $version->id]);
        Event::factory()->forPlayer($player)->count(3)->create();

        $countQueries = 0;
        DB::listen(function ($query) use (&$countQueries) {
            if (stripos($query->sql, 'count(') !== false) {
                $countQueries++;
            }
        });

        $this->postJson("/api/v1/versions/{$version->uuid}/exports", [
            'sheets' => [['name' => 'Events', 'source' => 'events']],
            'include_summary' => true,
        ])->assertStatus(202);
        $this->work();

        // 1 COUNT for the real Events sheet + 3 for the Data_Quality checks.
        $this->assertSame(4, $countQueries);
    }
}
