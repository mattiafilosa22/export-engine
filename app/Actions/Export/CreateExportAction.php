<?php

namespace App\Actions\Export;

use App\Jobs\GenerateExportJob;
use App\Models\Export;
use App\Models\Version;

/**
 * Creates the export request (`pending` status) and queues the generation job.
 * Dispatch is `afterCommit` with the uuid (string): avoids the worker/commit race
 * and forces the worker to always reread fresh state from the DB.
 */
class CreateExportAction
{
    /**
     * @param array<string, mixed> $params
     */
    public function execute(Version $version, array $params, string $format = Export::FORMAT_XLSX): Export
    {
        $export = Export::create([
            'version_id' => $version->id,
            'params' => $params,
            'format' => $format,
            'status' => Export::STATUS_PENDING,
        ]);

        GenerateExportJob::dispatch($export->uuid)->afterCommit();

        return $export;
    }
}
