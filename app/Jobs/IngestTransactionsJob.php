<?php

namespace App\Jobs;

use App\Models\Import;
use App\Models\Transaction;
use App\Support\Ingestion\PlayerResolver;
use App\Support\Ingestion\RowFieldNormalizer;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;

/**
 * Ingests a transactions batch (append, idempotent). Direct alternative to the
 * event-driven `transaction` event type: rows inserted here have `event_id =
 * NULL`. Resolves each row's player (player_id, or player_email as a fallback),
 * deduped on (version_id, dedup_key) via insertOrIgnore — keyless rows (NULL
 * dedup_key) always append. Rows with an unresolvable player are counted
 * `failed` without blocking the batch.
 */
class IngestTransactionsJob extends AbstractIngestJob
{
    /**
     * @return array{processed:int, inserted:int, duplicates:int, failed:int}
     */
    protected function process(Import $import, LoggerInterface $logger): array
    {
        $versionId = (int) $import->version_id;
        $resolver = app(PlayerResolver::class);
        $normalizer = app(RowFieldNormalizer::class);

        return $this->processInChunks($import, function (array $chunk) use ($versionId, $resolver, $normalizer) {
            return $this->ingestChunk($versionId, $chunk, $resolver, $normalizer);
        });
    }

    /**
     * @param array<int, array<string, mixed>> $chunk
     * @return array{inserted:int, duplicates:int, failed:int}
     */
    private function ingestChunk(
        int $versionId,
        array $chunk,
        PlayerResolver $resolver,
        RowFieldNormalizer $normalizer
    ): array {
        $now = Carbon::now()->toDateTimeString();
        [$validIds, $playerByEmail] = $resolver->candidatesFor($versionId, $chunk);

        $rows = [];
        $failed = 0;
        foreach ($chunk as $row) {
            $playerId = $resolver->resolveRow($row, $validIds, $playerByEmail);
            if ($playerId === null) {
                $failed++;
                continue;
            }

            $rows[] = [
                'version_id' => $versionId,
                'player_id' => $playerId,
                'type' => (string) $row['type'],
                'amount' => $row['amount'],
                'currency' => (string) $row['currency'],
                'status' => isset($row['status']) ? (string) $row['status'] : Transaction::STATUS_COMPLETED,
                'external_ref' => $row['external_ref'] ?? null,
                'occurred_at' => $normalizer->toUtc($row['occurred_at']),
                'dedup_key' => $normalizer->normalizeDedupKey($row),
                'created_at' => $now,
            ];
        }

        if ($rows === []) {
            return ['inserted' => 0, 'duplicates' => 0, 'failed' => $failed];
        }

        $affected = Transaction::insertOrIgnore($rows);

        return [
            'inserted' => $affected,
            'duplicates' => count($rows) - $affected,
            'failed' => $failed,
        ];
    }
}
