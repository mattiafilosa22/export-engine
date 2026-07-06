<?php

namespace Tests\Feature\Export;

use App\Jobs\GenerateExportJob;
use App\Models\Event;
use App\Models\Export;
use App\Models\Player;
use App\Models\Version;
use Illuminate\Support\Facades\Storage;

/**
 * Acceptance test: the API accepts the exact payload documented in the traccia
 * and produces the expected sheets.
 */
class TracciaExportTest extends ExportTestCase
{
    public function test_it_accepts_the_traccia_payload_and_produces_the_expected_sheets(): void
    {
        Storage::fake('local');
        config(['queue.default' => 'database']);

        $version = Version::factory()->create();
        // Two Italian players (kept) + one English (filtered out); desc by registered_at.
        $p1 = Player::factory()->resolvable($version, 'a@example.com')
            ->create(['language' => 'it', 'registered_at' => '2026-01-10 00:00:00', 'total_score' => 100]);
        $p2 = Player::factory()->resolvable($version, 'b@example.com')
            ->create(['language' => 'it', 'registered_at' => '2026-01-20 00:00:00', 'total_score' => 200]);
        Player::factory()->resolvable($version, 'c@example.com')
            ->create(['language' => 'en', 'registered_at' => '2026-01-15 00:00:00', 'total_score' => 300]);
        // events_summary segment (game_completed, it): 2 distinct players.
        Event::factory()->forPlayer($p1)->withPayload('it', 'linkedin', 10)
            ->create(['type' => 'game_completed', 'occurred_at' => '2026-01-15 10:00:00']);
        Event::factory()->forPlayer($p2)->withPayload('it', 'linkedin', 10)
            ->create(['type' => 'game_completed', 'occurred_at' => '2026-01-16 10:00:00']);

        $response = $this->postJson("/api/v1/versions/{$version->uuid}/exports", [
            'format' => 'xlsx',
            'date_from' => '2026-01-01',
            'date_to' => '2026-01-31',
            'sheets' => [
                [
                    'name' => 'players',
                    'columns' => ['player_id', 'email', 'registered_at', 'total_score'],
                    'filters' => ['language' => 'it'],
                    'sort' => ['registered_at:desc'],
                ],
                [
                    'name' => 'events_summary',
                    'group_by' => ['type', 'payload.language'],
                    'metrics' => ['count', 'unique_players'],
                ],
            ],
        ]);

        $response->assertStatus(202)->assertJsonPath('data.status', Export::STATUS_PENDING);

        $export = Export::where('version_id', $version->id)->latest('id')->firstOrFail();
        GenerateExportJob::dispatch($export->uuid);
        $this->work();

        $export->refresh();
        $this->assertSame(Export::STATUS_COMPLETED, $export->status);

        $sheets = $this->readSheets($export);
        $this->assertSame(['players', 'events_summary'], array_keys($sheets));

        // players: header + only the two Italian players, ordered by registered_at desc.
        $this->assertSame(['player_id', 'email', 'registered_at', 'total_score'], $sheets['players'][0]);
        $this->assertCount(3, $sheets['players']);
        $this->assertSame('b@example.com', $sheets['players'][1][1]);
        $this->assertSame('a@example.com', $sheets['players'][2][1]);

        // events_summary: grouped by type+language with count and unique_players.
        $this->assertSame(['type', 'language', 'count', 'unique_players'], $sheets['events_summary'][0]);
        $segment = $this->firstDataRow($sheets['events_summary'], ['game_completed', 'it']);
        $this->assertNotNull($segment);
        $this->assertEquals(2, $segment[2]);   // count
        $this->assertEquals(2, $segment[3]);   // unique_players
    }
}
