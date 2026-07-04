<?php

namespace App\Jobs;

use App\Models\Event;
use App\Models\Export;
use App\Support\Export\EventXlsxWriter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Heart of the async pipeline: reads a version's events via keyset and
 * streams the XLSX, updating the export's durable state.
 * Idempotent: an already-terminal export is not reprocessed.
 */
class GenerateExportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public $tries = 3;
    public $timeout = 90;

    private const EXPORT_DIR = 'exports';
    private const KEYSET_CHUNK = 1000;
    private const HEADER = ['id', 'type', 'occurred_at', 'language', 'score'];

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

    public function handle(LoggerInterface $logger, Filesystem $disk, EventXlsxWriter $writer): void
    {
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

        $context = ['export_id' => $export->uuid, 'version_id' => $export->version_id];
        $startedAt = microtime(true);

        $export->markProcessing();
        $logger->info('export.start', $context);

        try {
            $relativePath = $this->generateFile($export, $disk, $writer);
            $size = (int) $disk->size($relativePath);
            $export->markCompleted((int) $export->processed_rows, $relativePath, $size);

            $logger->info('export.completed', $context + [
                'rows' => $export->total_rows,
                'file_size' => $size,
                'duration_ms' => $this->elapsedMs($startedAt),
            ]);
        } catch (Throwable $e) {
            $export->markFailed($e->getMessage());
            $logger->error('export.failed', $context + [
                'exception' => $e->getMessage(),
                'duration_ms' => $this->elapsedMs($startedAt),
            ]);
            throw $e;
        }
    }

    /**
     * Generates the XLSX file and returns the relative path on disk.
     * Updates processed_rows/total_rows on the model with the number of rows written.
     */
    private function generateFile(Export $export, Filesystem $disk, EventXlsxWriter $writer): string
    {
        if (! $disk instanceof FilesystemAdapter) {
            throw new RuntimeException('Export disk must support absolute filesystem paths.');
        }

        $disk->makeDirectory(self::EXPORT_DIR);
        $relativePath = self::EXPORT_DIR . '/' . $export->uuid . '.xlsx';

        $rows = $writer->write($disk->path($relativePath), $this->rows($export), self::HEADER);

        $export->total_rows = $rows;
        $export->processed_rows = $rows;

        return $relativePath;
    }

    /**
     * Export rows via keyset pagination (constant memory): never OFFSET.
     *
     * @return \Generator<int, array<int, scalar|null>>
     */
    private function rows(Export $export): \Generator
    {
        $events = Event::forVersion((int) $export->version_id)
            ->orderBy('id')
            ->lazyById(self::KEYSET_CHUNK);

        foreach ($events as $event) {
            yield [
                $event->id,
                $event->type,
                optional($event->occurred_at)->toDateTimeString(),
                $event->payload_language,
                $event->payload_score,
            ];
        }
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
