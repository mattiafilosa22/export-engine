<?php

namespace App\Actions\Ingestion;

use App\Jobs\IngestEventsJob;
use App\Jobs\IngestPlayersJob;
use App\Models\Import;
use App\Models\Version;
use InvalidArgumentException;

/**
 * Creates the import request (`pending` status) and queues the type-specific
 * ingestion job. Dispatch is `afterCommit` with the uuid (string): avoids the
 * worker/commit race and forces the worker to reread fresh state from the DB.
 */
class CreateImportAction
{
    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public function execute(Version $version, string $type, array $rows): Import
    {
        $this->guardType($type);

        $import = Import::create([
            'version_id' => $version->id,
            'type' => $type,
            'status' => Import::STATUS_PENDING,
            'total_rows' => count($rows),
            'payload' => $rows,
        ]);

        $this->dispatchJob($type, $import->uuid);

        return $import;
    }

    private function guardType(string $type): void
    {
        if (! in_array($type, [Import::TYPE_PLAYERS, Import::TYPE_EVENTS], true)) {
            throw new InvalidArgumentException("Unsupported import type [{$type}].");
        }
    }

    private function dispatchJob(string $type, string $uuid): void
    {
        if ($type === Import::TYPE_PLAYERS) {
            IngestPlayersJob::dispatch($uuid)->afterCommit();
            return;
        }

        IngestEventsJob::dispatch($uuid)->afterCommit();
    }
}
