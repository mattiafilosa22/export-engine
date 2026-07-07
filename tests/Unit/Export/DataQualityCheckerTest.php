<?php

namespace Tests\Unit\Export;

use App\Models\Event;
use App\Models\Player;
use App\Models\Version;
use App\Support\Export\Sheet\DataQualityChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DataQualityCheckerTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_checks_are_zero_for_clean_data(): void
    {
        $version = Version::factory()->create();
        $player = Player::factory()->create(['version_id' => $version->id, 'registered_at' => now()->subDay()]);
        Event::factory()->forPlayer($player)->create(['occurred_at' => now()]);

        $results = (new DataQualityChecker())->run($version->id);

        $this->assertCount(3, $results);
        foreach ($results as $result) {
            $this->assertSame(0, $result['occurrences'], "{$result['check']} should be 0 on clean data");
        }
    }

    public function test_missing_language_counts_events_without_a_payload_language(): void
    {
        $version = Version::factory()->create();
        $player = Player::factory()->create(['version_id' => $version->id]);
        Event::factory()->forPlayer($player)->create(['payload' => ['score' => 10]]);

        $result = $this->checkNamed('missing_language', $version->id);

        $this->assertSame(1, $result['occurrences']);
        $this->assertSame(DataQualityChecker::SEVERITY_WARNING, $result['severity']);
    }

    public function test_empty_payload_counts_events_with_an_empty_json_payload(): void
    {
        $version = Version::factory()->create();
        $player = Player::factory()->create(['version_id' => $version->id]);
        Event::factory()->forPlayer($player)->create(['payload' => []]);

        $result = $this->checkNamed('empty_payload', $version->id);

        $this->assertSame(1, $result['occurrences']);
        $this->assertSame(DataQualityChecker::SEVERITY_INFO, $result['severity']);
    }

    public function test_invalid_event_order_counts_events_before_player_registration(): void
    {
        $version = Version::factory()->create();
        $player = Player::factory()->create(['version_id' => $version->id, 'registered_at' => now()]);
        Event::factory()->forPlayer($player)->create(['occurred_at' => now()->subDay()]);

        $result = $this->checkNamed('invalid_event_order', $version->id);

        $this->assertSame(1, $result['occurrences']);
        $this->assertSame(DataQualityChecker::SEVERITY_ERROR, $result['severity']);
    }

    /**
     * @return array{check: string, severity: string, occurrences: int, description: string}
     */
    private function checkNamed(string $check, int $versionId): array
    {
        $results = (new DataQualityChecker())->run($versionId);

        foreach ($results as $result) {
            if ($result['check'] === $check) {
                return $result;
            }
        }

        $this->fail("Check [{$check}] not found in results.");
    }
}
