<?php

namespace App\Jobs;

use App\Exceptions\ExportCancelledException;
use App\Models\Export;
use App\Support\Export\ExportSpecParser;
use App\Support\Export\ExportState;
use App\Support\Export\ExportStorage;
use App\Support\Export\Query\FilterApplier;
use App\Support\Export\Sheet\GenericSheetBuilder;
use App\Support\Export\Sheet\Sheet;
use App\Support\Export\XlsxExportWriter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Heart of the async pipeline: parses the export's params into sheets and
 * streams a multi-sheet XLSX at constant memory, updating the export's durable
 * state. Idempotent: an already-terminal export is not reprocessed.
 */
class GenerateExportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public $tries = 3;
    public $timeout = 90;

    /** @var array<int, int> Space out retries (seconds) to let transient issues clear. */
    public $backoff = [10, 30];

    // Lock TTL (seconds): > $timeout so it auto-releases if the worker dies.
    private const LOCK_TTL = 120;

    /** @var string */
    private $exportUuid;

    public function __construct(string $exportUuid)
    {
        $this->exportUuid = $exportUuid;
    }

    public function exportUuid(): string
    {
        return $this->exportUuid;
    }

    public function handle(
        LoggerInterface $logger,
        XlsxExportWriter $writer,
        ExportSpecParser $parser,
        FilterApplier $filters,
        ExportState $state,
        ExportStorage $storage
    ): void {
        // Fresh reload of the state from the uuid received in the constructor.
        $export = Export::where('uuid', $this->exportUuid)->first();

        if ($export === null) {
            $logger->warning('export.missing', ['export_id' => $this->exportUuid]);
            return;
        }

        // Idempotency: an export already in a terminal state is not reprocessed.
        if ($this->isTerminal($export)) {
            $logger->info('export.skip', ['export_id' => $export->uuid, 'status' => $export->status]);
            return;
        }

        // One export = one worker: a retry overlapping a slow run can't reprocess it.
        $lock = Cache::lock($state->lockKey($this->exportUuid), self::LOCK_TTL);
        if (! $lock->get()) {
            // Held by another run — or stale after a killed worker. Re-queue past the
            // lock's max lifetime instead of dropping the job (which would abandon the
            // export in `processing`); the retry then reprocesses or skips if terminal.
            $logger->info('export.locked', ['export_id' => $export->uuid]);
            $this->release(self::LOCK_TTL);
            return;
        }

        $context = ['export_id' => $export->uuid, 'version_id' => $export->version_id];
        $startedAt = microtime(true);

        try {
            $export->markProcessing();
            $logger->info('export.start', $context);

            $this->generateFile($export, $writer, $parser, $filters, $state, $storage);

            // Final cancellation checkpoint: covers a small export that finished
            // without hitting an in-stream checkpoint.
            if ($state->isCancelRequested($export->uuid)) {
                throw new ExportCancelledException();
            }

            $size = $storage->size($export);
            $export->markCompleted((int) $export->processed_rows, $export->relativeFilePath(), $size);
            $state->forget($export->uuid);

            $logger->info('export.completed', $context + [
                'rows' => $export->total_rows,
                'file_size' => $size,
                'duration_ms' => $this->elapsedMs($startedAt),
            ]);
        } catch (ExportCancelledException $e) {
            $storage->delete($export);
            $export->markCancelled();
            $state->forget($export->uuid);
            $logger->info('export.cancelled', $context + ['duration_ms' => $this->elapsedMs($startedAt)]);
            // No rethrow: cancellation is terminal, the job must not retry.
        } catch (Throwable $e) {
            $export->markFailed($e->getMessage());
            $logger->error('export.failed', $context + [
                'exception' => $e->getMessage(),
                'duration_ms' => $this->elapsedMs($startedAt),
            ]);
            throw $e;
        } finally {
            $lock->release();
        }
    }

    /**
     * Streams the XLSX to disk. Counts the rows upfront to set total_rows and
     * enable a live percentage, then writes reporting progress as it goes.
     */
    private function generateFile(
        Export $export,
        XlsxExportWriter $writer,
        ExportSpecParser $parser,
        FilterApplier $filters,
        ExportState $state,
        ExportStorage $storage
    ): void {
        $sheets = $this->buildSheets($export, $parser, $filters);

        $total = 0;
        foreach ($sheets as $sheet) {
            $total += $sheet->count();
        }
        $export->total_rows = $total;
        $export->save();
        $state->setProgress($export->uuid, 0);

        $written = $writer->write(
            $storage->preparePath($export),
            $sheets,
            $this->progressCallback($export, $state, $total)
        );

        $export->processed_rows = $written;
    }

    /**
     * Turns the export's params into the ordered list of configurable sheets.
     *
     * @return array<int, Sheet>
     */
    private function buildSheets(Export $export, ExportSpecParser $parser, FilterApplier $filters): array
    {
        $versionId = (int) $export->version_id;

        $sheets = [];
        foreach ($parser->parse($export)->sheets() as $spec) {
            $sheets[] = new GenericSheetBuilder($spec, $versionId, $filters);
        }

        return $sheets;
    }

    /**
     * Per-chunk progress: aborts on a cancellation request, persists the running
     * row count durably (lightweight UPDATE by id, no model events) and writes the
     * live percentage to the volatile store.
     */
    private function progressCallback(Export $export, ExportState $state, int $total): callable
    {
        return function (int $written) use ($export, $state, $total): void {
            if ($state->isCancelRequested($export->uuid)) {
                throw new ExportCancelledException();
            }

            Export::where('id', $export->id)->update(['processed_rows' => $written]);

            $percent = $total > 0 ? (int) min(99, (int) floor($written / $total * 100)) : 0;
            $state->setProgress($export->uuid, $percent);
        };
    }

    public function failed(Throwable $e): void
    {
        $export = Export::where('uuid', $this->exportUuid)->first();

        if ($export === null || $this->isTerminal($export)) {
            return;
        }

        $export->markFailed($e->getMessage());
    }

    private function isTerminal(Export $export): bool
    {
        return in_array(
            $export->status,
            [Export::STATUS_COMPLETED, Export::STATUS_CANCELLED],
            true
        );
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
