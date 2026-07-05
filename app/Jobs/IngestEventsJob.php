<?php

namespace App\Jobs;

use App\Models\Event;
use App\Models\Import;
use App\Support\Ingestion\PlayerResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Psr\Log\LoggerInterface;

/**
 * Ingests an events batch (append, idempotent): resolves each row's player,
 * then inserts via insertOrIgnore keyed on (version_id, dedup_key). Keyless
 * rows always append; keyed rows dedup. Rows whose player is unresolvable are
 * counted `failed` without blocking the batch.
 */
class IngestEventsJob extends AbstractIngestJob
{
    /**
     * @return array{processed:int, inserted:int, duplicates:int, failed:int}
     */
    protected function process(Import $import, LoggerInterface $logger): array
    {
        $versionId = (int) $import->version_id;
        $resolver = app(PlayerResolver::class);
        $processed = 0;
        $inserted = 0;
        $duplicates = 0;
        $failed = 0;

        foreach (array_chunk($import->payload, $this->chunkSize()) as $chunk) {
            $counts = DB::transaction(function () use ($versionId, $chunk, $resolver) {
                return $this->ingestChunk($versionId, $chunk, $resolver);
            });

            $processed += count($chunk);
            $inserted += $counts['inserted'];
            $duplicates += $counts['duplicates'];
            $failed += $counts['failed'];

            if ($counts['failed'] > 0) {
                $logger->warning('import.events.unresolved', [
                    'import_id' => $import->uuid,
                    'version_id' => $versionId,
                    'unresolved' => $counts['failed'],
                ]);
            }
        }

        return [
            'processed' => $processed,
            'inserted' => $inserted,
            'duplicates' => $duplicates,
            'failed' => $failed,
        ];
    }

    /**
     * Ingests one chunk inside a transaction, returning precise counts.
     *
     * @param array<int, array<string, mixed>> $chunk
     * @return array{inserted:int, duplicates:int, failed:int}
     */
    private function ingestChunk(int $versionId, array $chunk, PlayerResolver $resolver): array
    {
        $now = Carbon::now()->toDateTimeString();

        $emails = [];
        foreach ($chunk as $row) {
            $emails[] = (string) $row['player_email'];
        }
        $playerByEmail = $resolver->resolve($versionId, $emails);

        $eventRows = [];
        $failed = 0;
        foreach ($chunk as $row) {
            $email = (string) $row['player_email'];
            if (! isset($playerByEmail[$email])) {
                // Unresolvable player: never create implicit players, never block.
                $failed++;
                continue;
            }

            $eventRows[] = [
                'version_id' => $versionId,
                'player_id' => $playerByEmail[$email],
                'type' => (string) $row['type'],
                'occurred_at' => $this->toUtc($row['occurred_at']),
                'payload' => (string) json_encode($row['payload'] ?? []),
                'dedup_key' => $this->normalizeDedupKey($row),
                'created_at' => $now,
            ];
        }

        if ($eventRows === []) {
            return ['inserted' => 0, 'duplicates' => 0, 'failed' => $failed];
        }

        // insertOrIgnore reliably reports the rows actually inserted (unlike
        // upsert): keyed duplicates on (version_id, dedup_key) are silently
        // skipped, while keyless rows (NULL) always append.
        $affected = Event::insertOrIgnore($eventRows);

        return [
            'inserted' => $affected,
            'duplicates' => count($eventRows) - $affected,
            'failed' => $failed,
        ];
    }

    /**
     * Missing or blank key => NULL: MySQL treats NULLs as distinct in the
     * unique index, so the row always appends. A real key still dedups.
     *
     * @param array<string, mixed> $row
     */
    private function normalizeDedupKey(array $row): ?string
    {
        $key = isset($row['dedup_key']) ? trim((string) $row['dedup_key']) : '';

        return $key === '' ? null : $key;
    }

    /**
     * Normalizes a domain timestamp to UTC.
     *
     * @param mixed $value
     */
    private function toUtc($value): string
    {
        return Carbon::parse($value)->utc()->toDateTimeString();
    }
}
