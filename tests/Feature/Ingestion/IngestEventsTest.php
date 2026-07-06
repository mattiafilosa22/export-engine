<?php

namespace Tests\Feature\Ingestion;

use App\Jobs\IngestEventsJob;
use App\Models\Import;
use App\Models\Player;
use App\Models\Version;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

class IngestEventsTest extends IngestionTestCase
{
    public function test_it_accepts_the_batch_and_returns_202_with_a_pending_import(): void
    {
        Queue::fake();
        $version = Version::factory()->create();

        $response = $this->postJson("/api/v1/versions/{$version->uuid}/events", [
            'events' => [$this->eventRow('evt-1', 1)],
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('data.type', Import::TYPE_EVENTS)
            ->assertJsonPath('data.status', Import::STATUS_PENDING)
            ->assertJsonPath('data.total_rows', 1);

        Queue::assertPushed(IngestEventsJob::class);
    }

    public function test_the_worker_appends_events_with_dedup_key(): void
    {
        $version = Version::factory()->create();
        $a = Player::factory()->resolvable($version, 'a@example.com')->create();
        $b = Player::factory()->resolvable($version, 'b@example.com')->create();

        $import = $this->queueEventsImport($version, [
            $this->eventRow('evt-1', (int) $a->id),
            $this->eventRow('evt-2', (int) $b->id),
        ]);

        $this->work();

        $import->refresh();
        $this->assertSame(Import::STATUS_COMPLETED, $import->status);
        $this->assertSame(2, $import->inserted);
        $this->assertSame(0, $import->duplicates);
        $this->assertSame(0, $import->failed);

        $this->assertDatabaseCount('events', 2);
        $this->assertDatabaseHas('events', [
            'version_id' => $version->id,
            'player_id' => $a->id,
            'dedup_key' => 'evt-1',
        ]);
    }

    public function test_a_resent_batch_does_not_duplicate_events_and_counts_duplicates(): void
    {
        $version = Version::factory()->create();
        $a = Player::factory()->resolvable($version, 'a@example.com')->create();
        $rows = [
            $this->eventRow('evt-1', (int) $a->id),
            $this->eventRow('evt-2', (int) $a->id),
        ];

        $this->queueEventsImport($version, $rows);
        $this->work();

        $second = $this->queueEventsImport($version, $rows);
        $this->work();

        $second->refresh();
        $this->assertSame(0, $second->inserted);
        $this->assertSame(2, $second->duplicates);
        $this->assertDatabaseCount('events', 2);
    }

    public function test_unresolvable_player_rows_are_counted_failed_without_blocking_the_batch(): void
    {
        $version = Version::factory()->create();
        $a = Player::factory()->resolvable($version, 'a@example.com')->create();

        $import = $this->queueEventsImport($version, [
            $this->eventRow('evt-1', (int) $a->id),
            $this->eventRow('evt-2', 999999), // no such player in the version
        ]);

        $this->work();

        $import->refresh();
        $this->assertSame(Import::STATUS_COMPLETED, $import->status);
        $this->assertSame(1, $import->inserted);
        $this->assertSame(1, $import->failed);
        $this->assertDatabaseCount('events', 1);
    }

    public function test_it_rejects_a_batch_over_the_limit_with_413(): void
    {
        config(['gamindo.ingestion.max_batch_rows' => 2]);
        Queue::fake();
        $version = Version::factory()->create();

        $events = array_map(function (int $i): array {
            return $this->eventRow("evt-{$i}", 1);
        }, range(1, 3));

        $response = $this->postJson("/api/v1/versions/{$version->uuid}/events", ['events' => $events]);

        $response->assertStatus(413);
        Queue::assertNothingPushed();
    }

    public function test_it_returns_422_when_a_row_is_missing_the_player_id(): void
    {
        Queue::fake();
        $version = Version::factory()->create();

        $row = $this->eventRow('evt-1', 1);
        unset($row['player_id']);

        $response = $this->postJson("/api/v1/versions/{$version->uuid}/events", ['events' => [$row]]);

        $response->assertStatus(422);
        Queue::assertNothingPushed();
    }

    public function test_a_player_email_only_row_still_resolves_as_a_fallback(): void
    {
        $version = Version::factory()->create();
        $a = Player::factory()->resolvable($version, 'a@example.com')->create();

        // player_id present but unknown; player_email is the valid fallback.
        $row = $this->eventRow('evt-1', 999999);
        $row['player_email'] = 'a@example.com';

        $import = $this->queueEventsImport($version, [$row]);
        $this->work();

        $import->refresh();
        $this->assertSame(1, $import->inserted);
        $this->assertSame(0, $import->failed);
        $this->assertDatabaseHas('events', ['version_id' => $version->id, 'player_id' => $a->id]);
    }

    public function test_events_without_a_dedup_key_are_always_appended(): void
    {
        $version = Version::factory()->create();
        $a = Player::factory()->resolvable($version, 'a@example.com')->create();
        $rows = [
            $this->eventRow(null, (int) $a->id),
            $this->eventRow(null, (int) $a->id),
        ];

        $first = $this->queueEventsImport($version, $rows);
        $this->work();

        $second = $this->queueEventsImport($version, $rows);
        $this->work();

        $first->refresh();
        $second->refresh();
        $this->assertSame(Import::STATUS_COMPLETED, $first->status);
        $this->assertSame(Import::STATUS_COMPLETED, $second->status);
        $this->assertSame(2, $second->inserted);
        $this->assertSame(0, $second->duplicates);
        $this->assertSame(0, $second->failed);
        $this->assertDatabaseCount('events', 4);
    }

    public function test_events_with_a_blank_dedup_key_are_always_appended(): void
    {
        $version = Version::factory()->create();
        $a = Player::factory()->resolvable($version, 'a@example.com')->create();
        // Empty string and whitespace-only both normalize to NULL via trim.
        $rows = [
            $this->eventRowWithRawDedupKey('', (int) $a->id),
            $this->eventRowWithRawDedupKey('   ', (int) $a->id),
        ];

        $this->queueEventsImport($version, $rows);
        $this->work();

        $second = $this->queueEventsImport($version, $rows);
        $this->work();

        $second->refresh();
        $this->assertSame(2, $second->inserted);
        $this->assertSame(0, $second->duplicates);
        $this->assertDatabaseCount('events', 4);
    }

    public function test_a_mixed_batch_dedups_keyed_rows_and_appends_keyless_ones(): void
    {
        $version = Version::factory()->create();
        $a = Player::factory()->resolvable($version, 'a@example.com')->create();
        $rows = [
            $this->eventRow('evt-1', (int) $a->id),
            $this->eventRow(null, (int) $a->id),
        ];

        $this->queueEventsImport($version, $rows);
        $this->work();

        $second = $this->queueEventsImport($version, $rows);
        $this->work();

        $second->refresh();
        $this->assertSame(1, $second->inserted);
        $this->assertSame(1, $second->duplicates);
        $this->assertDatabaseCount('events', 3);
    }

    public function test_it_returns_404_for_an_unknown_version(): void
    {
        $response = $this->postJson('/api/v1/versions/' . Str::uuid() . '/events', [
            'events' => [$this->eventRow('evt-1', 1)],
        ]);

        $response->assertStatus(404);
    }

    /**
     * Queues an events import through the shared async pipeline helper.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    private function queueEventsImport(Version $version, array $rows): Import
    {
        return $this->queueImport($version, Import::TYPE_EVENTS, $rows);
    }

    /**
     * @return array<string, mixed>
     */
    private function eventRow(?string $dedupKey, int $playerId): array
    {
        $row = [
            'player_id' => $playerId,
            'type' => 'game_completed',
            'occurred_at' => '2026-01-15T10:00:00Z',
            'payload' => ['score' => 42],
        ];

        if ($dedupKey !== null) {
            $row['dedup_key'] = $dedupKey;
        }

        return $row;
    }

    /**
     * Builds a row that always carries the raw dedup_key, including empty or
     * whitespace-only values (distinct from omitting the key entirely).
     *
     * @return array<string, mixed>
     */
    private function eventRowWithRawDedupKey(string $dedupKey, int $playerId): array
    {
        $row = $this->eventRow(null, $playerId);
        $row['dedup_key'] = $dedupKey;

        return $row;
    }
}
