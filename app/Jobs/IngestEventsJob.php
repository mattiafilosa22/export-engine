<?php

namespace App\Jobs;

use App\Models\Event;
use App\Models\Import;
use App\Support\Ingestion\PlayerResolver;
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
        $writer = app(TypedRecordWriter::class);
        $processed = 0;
        $inserted = 0;
        $duplicates = 0;
        $failed = 0;

        foreach (array_chunk($import->payload, $this->chunkSize()) as $chunk) {
            $counts = DB::transaction(function () use ($versionId, $chunk, $resolver, $writer, $logger) {
                return $this->ingestChunk($versionId, $chunk, $resolver, $writer, $logger);
            });

            $processed += count($chunk);
            $inserted += $counts['inserted'];
            $duplicates += $counts['duplicates'];
            $failed += $counts['failed'];

            if ($counts['failed'] > 0) {
                $logger->warning('import.events.unresolved', [
                    'import_id' => $this->importUuid,
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
        TypedRecordWriter $writer,
        LoggerInterface $logger
    ): array {
        $now = Carbon::now()->toDateTimeString();

        [$candidates, $failed] = $this->resolveCandidates($versionId, $chunk, $resolver);
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
    private function resolveCandidates(int $versionId, array $chunk, PlayerResolver $resolver): array
    {
        $ids = [];
        $emails = [];
        foreach ($chunk as $row) {
            if (isset($row['player_id'])) {
                $ids[] = (int) $row['player_id'];
            }
            if (isset($row['player_email'])) {
                $emails[] = (string) $row['player_email'];
            }
        }
        $validIds = $resolver->existingIds($versionId, $ids);
        $playerByEmail = $resolver->resolve($versionId, $emails);

        $candidates = [];
        $failed = 0;
        foreach ($chunk as $row) {
            $playerId = $this->resolvePlayerId($row, $validIds, $playerByEmail);
            if ($playerId === null) {
                // Never create implicit players, never block the batch.
                $failed++;
                continue;
            }

            $candidates[] = [
                'player_id' => $playerId,
                'type' => (string) $row['type'],
                'occurred_at' => $this->toUtc($row['occurred_at']),
                'payload' => is_array($row['payload'] ?? null) ? $row['payload'] : [],
                'dedup_key' => $this->normalizeDedupKey($row),
            ];
        }

        return [$candidates, $failed];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, bool> $validIds
     * @param array<string, int> $playerByEmail
     */
    private function resolvePlayerId(array $row, array $validIds, array $playerByEmail): ?int
    {
        if (isset($row['player_id'])) {
            $id = (int) $row['player_id'];
            if (isset($validIds[$id])) {
                return $id;
            }
        }
        if (isset($row['player_email'])) {
            $email = (string) $row['player_email'];
            if (isset($playerByEmail[$email])) {
                return $playerByEmail[$email];
            }
        }

        return null;
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
