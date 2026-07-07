<?php

namespace App\Actions\Ingestion;

use App\Jobs\IngestAnswersJob;
use App\Jobs\IngestEventsJob;
use App\Jobs\IngestPlayersJob;
use App\Jobs\IngestRewardsJob;
use App\Jobs\IngestTransactionsJob;
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
    /** @var array<string, class-string> import type => job class */
    private const JOBS = [
        Import::TYPE_PLAYERS => IngestPlayersJob::class,
        Import::TYPE_EVENTS => IngestEventsJob::class,
        Import::TYPE_TRANSACTIONS => IngestTransactionsJob::class,
        Import::TYPE_ANSWERS => IngestAnswersJob::class,
        Import::TYPE_REWARDS => IngestRewardsJob::class,
    ];

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

        self::JOBS[$type]::dispatch($import->uuid)->afterCommit();

        return $import;
    }

    private function guardType(string $type): void
    {
        if (! isset(self::JOBS[$type])) {
            throw new InvalidArgumentException("Unsupported import type [{$type}].");
        }
    }
}
