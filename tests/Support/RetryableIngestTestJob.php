<?php

namespace Tests\Support;

use App\Jobs\AbstractIngestJob;
use App\Models\Event;
use App\Models\Import;
use Illuminate\Support\Facades\DB;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Instrumented ingestion job: performs an idempotent insertOrIgnore of its
 * payload, then throws on the first attempt only (attempts === 1). Exercises
 * the AbstractIngestJob retry lifecycle without touching the real jobs.
 */
class RetryableIngestTestJob extends AbstractIngestJob
{
    /**
     * @return array{processed:int, inserted:int, duplicates:int, failed:int}
     */
    protected function process(Import $import, LoggerInterface $logger): array
    {
        $inserted = 0;
        $duplicates = 0;

        foreach ($import->payload as $row) {
            $affected = DB::transaction(function () use ($import, $row): int {
                return Event::insertOrIgnore([[
                    'version_id' => $import->version_id,
                    'player_id' => $row['player_id'],
                    'type' => 'game_completed',
                    'occurred_at' => now(),
                    'payload' => '{}',
                    'dedup_key' => $row['dedup_key'],
                    'created_at' => now(),
                ]]);
            });
            $inserted += $affected;
            $duplicates += 1 - $affected;
        }

        // Fail on the first attempt only; the retry (attempts === 2) succeeds.
        if ((int) $import->attempts === 1) {
            throw new RuntimeException('transient failure on first attempt');
        }

        return [
            'processed' => count($import->payload),
            'inserted' => $inserted,
            'duplicates' => $duplicates,
            'failed' => 0,
        ];
    }
}
