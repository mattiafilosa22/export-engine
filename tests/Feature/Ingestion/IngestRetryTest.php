<?php

namespace Tests\Feature\Ingestion;

use App\Models\Import;
use App\Models\Player;
use App\Models\Version;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Tests\Support\RetryableIngestTestJob;

/**
 * Regression test for the retry lifecycle: after markFailed() a retried job
 * must reprocess (not be skipped as terminal) and, being idempotent, must not
 * duplicate rows. Guards against a too-broad isTerminal() killing the retries.
 */
class IngestRetryTest extends IngestionTestCase
{
    public function test_a_failed_job_is_reprocessed_on_retry_and_reaches_completed_without_duplicating(): void
    {
        $version = Version::factory()->create();
        $player = Player::factory()->resolvable($version, 'a@example.com')->create();

        $import = Import::factory()->events()->create([
            'version_id' => $version->id,
            'status' => Import::STATUS_PENDING,
            'total_rows' => 2,
            'payload' => [
                ['dedup_key' => 'evt-1', 'player_id' => $player->id],
                ['dedup_key' => 'evt-2', 'player_id' => $player->id],
            ],
        ]);

        $logger = app(LoggerInterface::class);

        // First attempt: inserts the rows, then throws (transient failure).
        try {
            (new RetryableIngestTestJob($import->uuid))->handle($logger);
            $this->fail('The first attempt was expected to throw.');
        } catch (RuntimeException $e) {
            // expected
        }

        $import->refresh();
        $this->assertSame(Import::STATUS_FAILED, $import->status);
        $this->assertSame(1, $import->attempts);
        $this->assertDatabaseCount('events', 2);

        // Retry: must NOT be skipped as terminal — it reprocesses to completed.
        (new RetryableIngestTestJob($import->uuid))->handle($logger);

        $import->refresh();
        $this->assertSame(Import::STATUS_COMPLETED, $import->status);
        $this->assertSame(2, $import->attempts);
        // Idempotent: the retry re-ran the inserts but produced no duplicates.
        $this->assertSame(0, $import->inserted);
        $this->assertSame(2, $import->duplicates);
        $this->assertDatabaseCount('events', 2);
    }
}
