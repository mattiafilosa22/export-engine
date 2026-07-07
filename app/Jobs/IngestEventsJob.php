<?php

namespace App\Jobs;

use App\Models\Event;
use App\Models\Import;
use App\Support\Ingestion\PlayerResolver;
use App\Support\Ingestion\RowFieldNormalizer;
use App\Support\Ingestion\TypedRecordWriter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Psr\Log\LoggerInterface;

/**
 * Ingests an events batch (append, idempotent). Resolves each row's player
 * (player_id, or player_email as a fallback), inserts the genuinely new events
 * (deduped on (version_id, dedup_key)), and — for those inserted events only —
 * delegates to TypedRecordWriter to create the correlated typed record
 * (answers/transactions/rewards) linked to the real event_id. Rows with an
 * unresolvable player are counted `failed` without blocking the batch.
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
        $normalizer = app(RowFieldNormalizer::class);
        $writer = app(TypedRecordWriter::class);

        $ingestChunk = function (array $chunk) use ($versionId, $resolver, $normalizer, $writer, $logger) {
            $counts = $this->ingestChunk($versionId, $chunk, $resolver, $normalizer, $writer, $logger);

            if ($counts['failed'] > 0) {
                $logger->warning('import.events.unresolved', [
                    'import_id' => $this->importUuid,
                    'version_id' => $versionId,
                    'unresolved' => $counts['failed'],
                ]);
            }

            return $counts;
        };

        return $this->processInChunks($import, $ingestChunk);
    }

    /**
     * Ingests one chunk inside a transaction: resolves players, inserts the new
     * events, and links their typed records. Returns precise counts.
     *
     * @param array<int, array<string, mixed>> $chunk
     * @return array{inserted:int, duplicates:int, failed:int}
     */
    private function ingestChunk(
        int $versionId,
        array $chunk,
        PlayerResolver $resolver,
        RowFieldNormalizer $normalizer,
        TypedRecordWriter $writer,
        LoggerInterface $logger
    ): array {
        $now = Carbon::now()->toDateTimeString();

        [$candidates, $failed] = $this->resolveCandidates($versionId, $chunk, $resolver, $normalizer);
        [$new, $duplicates] = $this->newEvents($versionId, $candidates);

        if ($new === []) {
            return ['inserted' => 0, 'duplicates' => $duplicates, 'failed' => $failed];
        }

        // Plain insert (not insertOrIgnore) so the auto-increment ids are
        // contiguous: each typed record links to firstId + offset. Contiguity of a
        // single bulk INSERT under concurrent writers relies on
        // innodb_autoinc_lock_mode=1 (pinned in docker-compose.yml / CI; MySQL 8
        // defaults to 2/interleaved).
        Event::insert($this->eventRows($versionId, $new, $now));
        $firstId = (int) DB::getPdo()->lastInsertId();

        foreach ($writer->write($versionId, $new, $firstId, $now) as $skip) {
            $logger->warning('import.events.typed_skipped', [
                'import_id' => $this->importUuid,
                'version_id' => $versionId,
                'event_id' => $skip['event_id'],
                'type' => $skip['type'],
                'reason' => $skip['reason'],
            ]);
        }

        return ['inserted' => count($new), 'duplicates' => $duplicates, 'failed' => $failed];
    }

    /**
     * Resolves each row to a player id (player_id primary, player_email fallback),
     * normalizing the payload. Unresolvable rows are dropped and counted.
     *
     * @param array<int, array<string, mixed>> $chunk
     * @return array{0: array<int, array<string, mixed>>, 1: int}
     */
    private function resolveCandidates(
        int $versionId,
        array $chunk,
        PlayerResolver $resolver,
        RowFieldNormalizer $normalizer
    ): array {
        [$validIds, $playerByEmail] = $resolver->candidatesFor($versionId, $chunk);

        $candidates = [];
        $failed = 0;
        foreach ($chunk as $row) {
            $playerId = $resolver->resolveRow($row, $validIds, $playerByEmail);
            if ($playerId === null) {
                // Never create implicit players, never block the batch.
                $failed++;
                continue;
            }

            $candidates[] = [
                'player_id' => $playerId,
                'type' => (string) $row['type'],
                'occurred_at' => $normalizer->toUtc($row['occurred_at']),
                'payload' => is_array($row['payload'] ?? null) ? $row['payload'] : [],
                'dedup_key' => $normalizer->normalizeDedupKey($row),
            ];
        }

        return [$candidates, $failed];
    }

    /**
     * Splits candidates into the genuinely new events and a duplicate count:
     * keyed rows dedup against the DB and within the chunk; keyless rows (NULL
     * dedup_key) always append.
     *
     * @param array<int, array<string, mixed>> $candidates
     * @return array{0: array<int, array<string, mixed>>, 1: int}
     */
    private function newEvents(int $versionId, array $candidates): array
    {
        $existing = $this->existingKeys($versionId, $candidates);
        $new = [];
        $seen = [];
        $duplicates = 0;
        foreach ($candidates as $candidate) {
            $key = $candidate['dedup_key'];
            if ($key !== null) {
                if (isset($existing[$key]) || isset($seen[$key])) {
                    $duplicates++;
                    continue;
                }
                $seen[$key] = true;
            }
            $new[] = $candidate;
        }

        return [$new, $duplicates];
    }

    /**
     * DB pre-existence of the chunk's dedup keys within the version.
     *
     * @param array<int, array<string, mixed>> $candidates
     * @return array<string, bool> existing dedup_key => true
     */
    private function existingKeys(int $versionId, array $candidates): array
    {
        $keys = [];
        foreach ($candidates as $candidate) {
            if ($candidate['dedup_key'] !== null) {
                $keys[] = $candidate['dedup_key'];
            }
        }
        if ($keys === []) {
            return [];
        }

        $rows = Event::where('version_id', $versionId)
            ->whereIn('dedup_key', array_values(array_unique($keys)))
            ->pluck('dedup_key');

        $existing = [];
        foreach ($rows as $key) {
            $existing[(string) $key] = true;
        }

        return $existing;
    }

    /**
     * @param array<int, array<string, mixed>> $new
     * @return array<int, array<string, mixed>>
     */
    private function eventRows(int $versionId, array $new, string $now): array
    {
        $rows = [];
        foreach ($new as $candidate) {
            $rows[] = [
                'version_id' => $versionId,
                'player_id' => $candidate['player_id'],
                'type' => $candidate['type'],
                'occurred_at' => $candidate['occurred_at'],
                'payload' => (string) json_encode($candidate['payload']),
                'dedup_key' => $candidate['dedup_key'],
                'created_at' => $now,
            ];
        }

        return $rows;
    }
}
