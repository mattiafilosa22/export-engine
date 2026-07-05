<?php

namespace App\Jobs;

use App\Models\Import;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Template for the async ingestion lifecycle, mirroring GenerateExportJob:
 * reload the import from its uuid, guard against reprocessing, run the
 * type-specific chunked work, and update the durable state with counters.
 * Correlation id = import uuid, propagated on every log line.
 */
abstract class AbstractIngestJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public $tries = 3;
    public $timeout = 300;

    /** @var string */
    protected $importUuid;

    public function __construct(string $importUuid)
    {
        $this->importUuid = $importUuid;
    }

    public function importUuid(): string
    {
        return $this->importUuid;
    }

    public function handle(LoggerInterface $logger): void
    {
        // Fresh reload of the state from the uuid received in the constructor.
        $import = Import::where('uuid', $this->importUuid)->first();

        if ($import === null) {
            $logger->warning('import.missing', ['import_id' => $this->importUuid]);
            return;
        }

        // Idempotency: an import already in a terminal state is not reprocessed.
        if ($import->isTerminal()) {
            $logger->info('import.skip', ['import_id' => $import->uuid, 'status' => $import->status]);
            return;
        }

        $context = ['import_id' => $import->uuid, 'version_id' => $import->version_id, 'type' => $import->type];
        $startedAt = microtime(true);

        $import->markProcessing();
        $logger->info('import.start', $context + ['total_rows' => $import->total_rows]);

        try {
            $counts = $this->process($import, $logger);
            $import->markCompleted(
                $counts['processed'],
                $counts['inserted'],
                $counts['duplicates'],
                $counts['failed']
            );

            $logger->info('import.completed', $context + [
                'processed' => $counts['processed'],
                'inserted' => $counts['inserted'],
                'duplicates' => $counts['duplicates'],
                'failed' => $counts['failed'],
                'duration_ms' => $this->elapsedMs($startedAt),
            ]);
        } catch (Throwable $e) {
            $import->markFailed($e->getMessage());
            $logger->error('import.failed', $context + [
                'exception' => $e->getMessage(),
                'duration_ms' => $this->elapsedMs($startedAt),
            ]);
            throw $e;
        }
    }

    public function failed(Throwable $e): void
    {
        $import = Import::where('uuid', $this->importUuid)->first();

        if ($import === null || $import->isTerminal()) {
            return;
        }

        $import->markFailed($e->getMessage());
    }

    /**
     * Runs the type-specific chunked ingestion.
     *
     * @return array{processed:int, inserted:int, duplicates:int, failed:int}
     */
    abstract protected function process(Import $import, LoggerInterface $logger): array;

    protected function chunkSize(): int
    {
        // Never 0: array_chunk($rows, 0) returns null in PHP 7.3 → silent no-op
        // (import wrongly marked completed with 0 rows).
        return max(1, (int) config('gamindo.ingestion.chunk_size'));
    }

    protected function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
