<?php

namespace Tests\Feature\Ingestion;

use App\Jobs\IngestTransactionsJob;
use App\Models\Import;
use App\Models\Player;
use App\Models\Transaction;
use App\Models\Version;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

class IngestTransactionsTest extends IngestionTestCase
{
    public function test_it_accepts_the_batch_and_returns_202_with_a_pending_import(): void
    {
        Queue::fake();
        $version = Version::factory()->create();

        $response = $this->postJson("/api/v1/versions/{$version->uuid}/transactions", [
            'transactions' => [$this->row('txn-1', 1)],
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('data.type', Import::TYPE_TRANSACTIONS)
            ->assertJsonPath('data.status', Import::STATUS_PENDING)
            ->assertJsonPath('data.total_rows', 1);

        Queue::assertPushed(IngestTransactionsJob::class);
    }

    public function test_the_worker_appends_transactions_with_no_linked_event(): void
    {
        $version = Version::factory()->create();
        $player = Player::factory()->resolvable($version, 'a@example.com')->create();

        $import = $this->queueImport($version, Import::TYPE_TRANSACTIONS, [
            $this->row('txn-1', (int) $player->id),
        ]);
        $this->work();

        $import->refresh();
        $this->assertSame(Import::STATUS_COMPLETED, $import->status);
        $this->assertSame(1, $import->inserted);

        $this->assertDatabaseHas('transactions', [
            'version_id' => $version->id,
            'player_id' => $player->id,
            'dedup_key' => 'txn-1',
            'type' => Transaction::TYPE_PURCHASE,
            'event_id' => null,
        ]);
    }

    public function test_a_resent_batch_does_not_duplicate_transactions(): void
    {
        $version = Version::factory()->create();
        $player = Player::factory()->resolvable($version, 'a@example.com')->create();
        $rows = [$this->row('txn-1', (int) $player->id)];

        $this->queueImport($version, Import::TYPE_TRANSACTIONS, $rows);
        $this->work();

        $second = $this->queueImport($version, Import::TYPE_TRANSACTIONS, $rows);
        $this->work();

        $second->refresh();
        $this->assertSame(0, $second->inserted);
        $this->assertSame(1, $second->duplicates);
        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_keyless_transactions_are_always_appended(): void
    {
        $version = Version::factory()->create();
        $player = Player::factory()->resolvable($version, 'a@example.com')->create();
        $rows = [$this->row(null, (int) $player->id)];

        $this->queueImport($version, Import::TYPE_TRANSACTIONS, $rows);
        $this->work();
        $second = $this->queueImport($version, Import::TYPE_TRANSACTIONS, $rows);
        $this->work();

        $second->refresh();
        $this->assertSame(1, $second->inserted);
        $this->assertDatabaseCount('transactions', 2);
    }

    public function test_unresolvable_player_rows_are_counted_failed_without_blocking_the_batch(): void
    {
        $version = Version::factory()->create();
        $player = Player::factory()->resolvable($version, 'a@example.com')->create();

        $import = $this->queueImport($version, Import::TYPE_TRANSACTIONS, [
            $this->row('txn-1', (int) $player->id),
            $this->row('txn-2', 999999),
        ]);
        $this->work();

        $import->refresh();
        $this->assertSame(1, $import->inserted);
        $this->assertSame(1, $import->failed);
        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_a_player_email_only_row_still_resolves_as_a_fallback(): void
    {
        $version = Version::factory()->create();
        $player = Player::factory()->resolvable($version, 'a@example.com')->create();

        $row = $this->row('txn-1', 999999);
        $row['player_email'] = 'a@example.com';

        $import = $this->queueImport($version, Import::TYPE_TRANSACTIONS, [$row]);
        $this->work();

        $import->refresh();
        $this->assertSame(1, $import->inserted);
        $this->assertDatabaseHas('transactions', ['version_id' => $version->id, 'player_id' => $player->id]);
    }

    public function test_it_rejects_a_batch_over_the_limit_with_413(): void
    {
        config(['gamindo.ingestion.max_batch_rows' => 2]);
        Queue::fake();
        $version = Version::factory()->create();

        $rows = array_map(function (int $i): array {
            return $this->row("txn-{$i}", 1);
        }, range(1, 3));

        $this->postJson("/api/v1/versions/{$version->uuid}/transactions", ['transactions' => $rows])
            ->assertStatus(413);
        Queue::assertNothingPushed();
    }

    public function test_it_returns_422_when_the_type_is_invalid(): void
    {
        Queue::fake();
        $version = Version::factory()->create();

        $row = $this->row('txn-1', 1);
        $row['type'] = 'not_a_type';

        $this->postJson("/api/v1/versions/{$version->uuid}/transactions", ['transactions' => [$row]])
            ->assertStatus(422);
        Queue::assertNothingPushed();
    }

    public function test_it_returns_404_for_an_unknown_version(): void
    {
        $this->postJson('/api/v1/versions/' . Str::uuid() . '/transactions', [
            'transactions' => [$this->row('txn-1', 1)],
        ])->assertStatus(404);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(?string $dedupKey, int $playerId): array
    {
        $row = [
            'player_id' => $playerId,
            'type' => Transaction::TYPE_PURCHASE,
            'amount' => 9.99,
            'currency' => 'EUR',
            'occurred_at' => '2026-01-15T10:00:00Z',
        ];

        if ($dedupKey !== null) {
            $row['dedup_key'] = $dedupKey;
        }

        return $row;
    }
}
