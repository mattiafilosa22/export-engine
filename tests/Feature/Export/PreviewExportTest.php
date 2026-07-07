<?php

namespace Tests\Feature\Export;

use App\Models\Event;
use App\Models\Player;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PreviewExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_caps_the_preview_at_100_rows_and_flags_truncation(): void
    {
        $version = Version::factory()->create();
        $player = Player::factory()->create(['version_id' => $version->id]);
        Event::factory()->bulkInsert((int) $version->id, (int) $player->id, 150);

        $response = $this->postJson("/api/v1/versions/{$version->uuid}/exports/preview", [
            'sheets' => [['source' => 'events']],
        ]);

        $response->assertStatus(200);
        $sheet = $response->json('data.sheets.0');
        $this->assertCount(100, $sheet['rows']);
        $this->assertTrue($sheet['truncated']);
        $this->assertNotEmpty($sheet['header']);
    }

    public function test_it_returns_all_rows_and_no_truncation_below_the_cap(): void
    {
        $version = Version::factory()->create();
        $player = Player::factory()->create(['version_id' => $version->id]);
        Event::factory()->bulkInsert((int) $version->id, (int) $player->id, 5);

        $response = $this->postJson("/api/v1/versions/{$version->uuid}/exports/preview", [
            'sheets' => [['source' => 'events']],
        ]);

        $response->assertStatus(200);
        $sheet = $response->json('data.sheets.0');
        $this->assertCount(5, $sheet['rows']);
        $this->assertFalse($sheet['truncated']);
        // Rows are mapped header => value (not positional).
        $this->assertSame($sheet['header'], array_keys($sheet['rows'][0]));
    }

    public function test_it_reuses_the_export_validation_and_rejects_an_invalid_spec(): void
    {
        $version = Version::factory()->create();

        $this->postJson("/api/v1/versions/{$version->uuid}/exports/preview", [
            'sheets' => [['source' => 'not_a_source']],
        ])->assertStatus(422);
    }
}
