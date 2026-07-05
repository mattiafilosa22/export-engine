<?php

namespace Tests\Feature\Ingestion;

use App\Jobs\IngestPlayersJob;
use App\Models\Import;
use App\Models\Player;
use App\Models\User;
use App\Models\Version;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

class IngestPlayersTest extends IngestionTestCase
{
    public function test_it_accepts_the_batch_and_returns_202_with_a_pending_import(): void
    {
        Queue::fake();
        $version = Version::factory()->create();

        $response = $this->postJson("/api/v1/versions/{$version->uuid}/players", [
            'players' => [
                ['email' => 'a@example.com', 'language' => 'it'],
                ['email' => 'b@example.com'],
            ],
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('data.type', Import::TYPE_PLAYERS)
            ->assertJsonPath('data.status', Import::STATUS_PENDING)
            ->assertJsonPath('data.total_rows', 2);
        $this->assertArrayNotHasKey('payload', $response->json('data'));

        $this->assertDatabaseHas('imports', [
            'version_id' => $version->id,
            'type' => Import::TYPE_PLAYERS,
            'status' => Import::STATUS_PENDING,
            'total_rows' => 2,
        ]);

        Queue::assertPushed(IngestPlayersJob::class);
    }

    public function test_the_worker_upserts_users_and_players_and_counts_inserted(): void
    {
        $version = Version::factory()->create();
        $import = $this->queuePlayersImport($version, [
            ['email' => 'a@example.com', 'language' => 'it'],
            ['email' => 'b@example.com', 'language' => 'en'],
            ['email' => 'c@example.com'],
        ]);

        $this->work();

        $import->refresh();
        $this->assertSame(Import::STATUS_COMPLETED, $import->status);
        $this->assertSame(3, $import->inserted);
        $this->assertSame(0, $import->duplicates);
        $this->assertSame(3, $import->processed_rows);

        $this->assertDatabaseCount('users', 3);
        $this->assertDatabaseCount('players', 3);
        $this->assertDatabaseHas('users', ['email' => 'a@example.com']);
    }

    public function test_a_resent_batch_does_not_duplicate_and_counts_duplicates(): void
    {
        $version = Version::factory()->create();
        $rows = [
            ['email' => 'a@example.com'],
            ['email' => 'b@example.com'],
        ];

        $this->queuePlayersImport($version, $rows);
        $this->work();

        $second = $this->queuePlayersImport($version, $rows);
        $this->work();

        $second->refresh();
        $this->assertSame(Import::STATUS_COMPLETED, $second->status);
        $this->assertSame(0, $second->inserted);
        $this->assertSame(2, $second->duplicates);

        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('players', 2);
    }

    public function test_a_reimport_does_not_reset_the_denormalized_total_score(): void
    {
        $version = Version::factory()->create();
        $user = User::factory()->create(['email' => 'a@example.com']);
        Player::factory()->create([
            'version_id' => $version->id,
            'user_id' => $user->id,
            'total_score' => 50,
        ]);

        // Re-importing the same player must not touch total_score (excluded from
        // the upsert's updated columns), only registered_at/language.
        $this->queuePlayersImport($version, [['email' => 'a@example.com', 'language' => 'es']]);
        $this->work();

        $this->assertDatabaseHas('players', [
            'version_id' => $version->id,
            'user_id' => $user->id,
            'total_score' => 50,
            'language' => 'es',
        ]);
    }

    public function test_it_rejects_a_batch_over_the_limit_with_413(): void
    {
        config(['gamindo.ingestion.max_batch_rows' => 3]);
        Queue::fake();
        $version = Version::factory()->create();

        $players = array_map(function (int $i): array {
            return ['email' => "user{$i}@example.com"];
        }, range(1, 4));

        $response = $this->postJson("/api/v1/versions/{$version->uuid}/players", ['players' => $players]);

        $response->assertStatus(413);
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('imports', 0);
    }

    public function test_it_returns_422_when_a_row_is_missing_the_email(): void
    {
        Queue::fake();
        $version = Version::factory()->create();

        $response = $this->postJson("/api/v1/versions/{$version->uuid}/players", [
            'players' => [['language' => 'it']],
        ]);

        $response->assertStatus(422);
        Queue::assertNothingPushed();
    }

    public function test_it_returns_404_for_an_unknown_version(): void
    {
        $response = $this->postJson('/api/v1/versions/' . Str::uuid() . '/players', [
            'players' => [['email' => 'a@example.com']],
        ]);

        $response->assertStatus(404);
    }

    /**
     * Queues a players import through the shared async pipeline helper.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    private function queuePlayersImport(Version $version, array $rows): Import
    {
        return $this->queueImport($version, Import::TYPE_PLAYERS, $rows);
    }
}
