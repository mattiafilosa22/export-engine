<?php

namespace Tests\Feature\Ingestion;

use App\Jobs\IngestEventsJob;
use App\Jobs\IngestPlayersJob;
use App\Models\Import;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Shared scaffolding for the ingestion feature tests: async pipeline helpers
 * (queue a pending import, drain the worker). Domain fixtures live in the
 * factories (see Player::factory()->resolvable()).
 */
abstract class IngestionTestCase extends TestCase
{
    use RefreshDatabase;

    /**
     * Creates a pending import and queues its job on the database queue,
     * mirroring the async pipeline (afterCommit would not fire under RefreshDatabase).
     *
     * @param array<int, array<string, mixed>> $rows
     */
    protected function queueImport(Version $version, string $type, array $rows): Import
    {
        config(['queue.default' => 'database']);

        $import = Import::factory()->create([
            'version_id' => $version->id,
            'type' => $type,
            'status' => Import::STATUS_PENDING,
            'total_rows' => count($rows),
            'payload' => $rows,
        ]);

        $this->dispatchIngestJob($type, $import->uuid);

        return $import;
    }

    /**
     * Runs the queued jobs once, draining the database queue.
     */
    protected function work(): void
    {
        Artisan::call('queue:work', ['--once' => true, '--stop-when-empty' => true]);
    }

    private function dispatchIngestJob(string $type, string $importUuid): void
    {
        if ($type === Import::TYPE_EVENTS) {
            IngestEventsJob::dispatch($importUuid);

            return;
        }

        IngestPlayersJob::dispatch($importUuid);
    }
}
