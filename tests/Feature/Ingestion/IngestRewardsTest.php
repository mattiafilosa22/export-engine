<?php

namespace Tests\Feature\Ingestion;

use App\Jobs\IngestRewardsJob;
use App\Models\Import;
use App\Models\Player;
use App\Models\Reward;
use App\Models\Version;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

class IngestRewardsTest extends IngestionTestCase
{
    public function test_it_accepts_the_batch_and_returns_202_with_a_pending_import(): void
    {
        Queue::fake();
        $version = Version::factory()->create();

        $response = $this->postJson("/api/v1/versions/{$version->uuid}/rewards", [
            'rewards' => [$this->row('rwd-1', 1)],
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('data.type', Import::TYPE_REWARDS)
            ->assertJsonPath('data.status', Import::STATUS_PENDING);

        Queue::assertPushed(IngestRewardsJob::class);
    }

    public function test_the_worker_appends_rewards_with_no_linked_event(): void
    {
        $version = Version::factory()->create();
        $player = Player::factory()->resolvable($version, 'a@example.com')->create();

        $import = $this->queueImport($version, Import::TYPE_REWARDS, [$this->row('rwd-1', (int) $player->id)]);
        $this->work();

        $import->refresh();
        $this->assertSame(Import::STATUS_COMPLETED, $import->status);
        $this->assertSame(1, $import->inserted);

        $this->assertDatabaseHas('rewards', [
            'version_id' => $version->id,
            'player_id' => $player->id,
            'dedup_key' => 'rwd-1',
            'status' => Reward::STATUS_GRANTED,
            'event_id' => null,
        ]);
    }

    public function test_a_resent_batch_does_not_duplicate_rewards(): void
    {
        $version = Version::factory()->create();
        $player = Player::factory()->resolvable($version, 'a@example.com')->create();
        $rows = [$this->row('rwd-1', (int) $player->id)];

        $this->queueImport($version, Import::TYPE_REWARDS, $rows);
        $this->work();

        $second = $this->queueImport($version, Import::TYPE_REWARDS, $rows);
        $this->work();

        $second->refresh();
        $this->assertSame(0, $second->inserted);
        $this->assertSame(1, $second->duplicates);
        $this->assertDatabaseCount('rewards', 1);
    }

    public function test_unresolvable_player_rows_are_counted_failed_without_blocking_the_batch(): void
    {
        $version = Version::factory()->create();
        $player = Player::factory()->resolvable($version, 'a@example.com')->create();

        $import = $this->queueImport($version, Import::TYPE_REWARDS, [
            $this->row('rwd-1', (int) $player->id),
            $this->row('rwd-2', 999999),
        ]);
        $this->work();

        $import->refresh();
        $this->assertSame(1, $import->inserted);
        $this->assertSame(1, $import->failed);
    }

    public function test_it_rejects_a_batch_over_the_limit_with_413(): void
    {
        config(['gamindo.ingestion.max_batch_rows' => 2]);
        Queue::fake();
        $version = Version::factory()->create();

        $rows = array_map(function (int $i): array {
            return $this->row("rwd-{$i}", 1);
        }, range(1, 3));

        $this->postJson("/api/v1/versions/{$version->uuid}/rewards", ['rewards' => $rows])
            ->assertStatus(413);
        Queue::assertNothingPushed();
    }

    public function test_it_returns_422_when_reward_type_is_missing(): void
    {
        Queue::fake();
        $version = Version::factory()->create();

        $row = $this->row('rwd-1', 1);
        unset($row['reward_type']);

        $this->postJson("/api/v1/versions/{$version->uuid}/rewards", ['rewards' => [$row]])
            ->assertStatus(422);
        Queue::assertNothingPushed();
    }

    public function test_it_returns_404_for_an_unknown_version(): void
    {
        $this->postJson('/api/v1/versions/' . Str::uuid() . '/rewards', [
            'rewards' => [$this->row('rwd-1', 1)],
        ])->assertStatus(404);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(?string $dedupKey, int $playerId): array
    {
        $row = [
            'player_id' => $playerId,
            'reward_type' => 'coupon',
            'reward_code' => 'XMAS10',
            'granted_at' => '2026-01-15T10:00:00Z',
        ];

        if ($dedupKey !== null) {
            $row['dedup_key'] = $dedupKey;
        }

        return $row;
    }
}
