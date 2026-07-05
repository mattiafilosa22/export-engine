<?php

namespace App\Jobs;

use App\Models\Import;
use App\Models\Player;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Psr\Log\LoggerInterface;

/**
 * Ingests a players batch: upsert users (by email) then players (by
 * version_id+user_id), in chunked transactions. `total_score` is never in the
 * updated columns — a re-send must not reset the denormalized aggregate.
 */
class IngestPlayersJob extends AbstractIngestJob
{
    /**
     * @return array{processed:int, inserted:int, duplicates:int, failed:int}
     */
    protected function process(Import $import, LoggerInterface $logger): array
    {
        $versionId = (int) $import->version_id;
        $processed = 0;
        $inserted = 0;
        $duplicates = 0;

        foreach (array_chunk($import->payload, $this->chunkSize()) as $chunk) {
            $counts = DB::transaction(function () use ($versionId, $chunk) {
                return $this->ingestChunk($versionId, $chunk);
            });

            $processed += count($chunk);
            $inserted += $counts['inserted'];
            $duplicates += $counts['duplicates'];
        }

        return [
            'processed' => $processed,
            'inserted' => $inserted,
            'duplicates' => $duplicates,
            'failed' => 0,
        ];
    }

    /**
     * Ingests one chunk inside a transaction, returning precise counts.
     *
     * @param array<int, array<string, mixed>> $chunk
     * @return array{inserted:int, duplicates:int}
     */
    private function ingestChunk(int $versionId, array $chunk): array
    {
        $now = Carbon::now()->toDateTimeString();

        $this->upsertUsers($chunk, $now);
        $userIdByEmail = $this->mapEmailsToUserIds($chunk);
        $userIds = array_values(array_unique($userIdByEmail));

        // Pre-existence query (inside the transaction): upsert's affected-rows
        // count is unreliable, so inserted/duplicates are computed here.
        $existing = Player::forVersion($versionId)
            ->whereIn('user_id', $userIds)
            ->pluck('user_id')
            ->all();
        $existingCount = count(array_unique($existing));

        $this->upsertPlayers($versionId, $chunk, $userIdByEmail, $now);

        return [
            'inserted' => count($userIds) - $existingCount,
            'duplicates' => $existingCount,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $chunk
     */
    private function upsertUsers(array $chunk, string $now): void
    {
        $rows = [];
        foreach ($chunk as $row) {
            $email = (string) $row['email'];
            $rows[$email] = [
                'email' => $email,
                'external_id' => $row['external_id'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // Upsert via query builder does not fire model events → timestamps by hand.
        User::upsert(array_values($rows), ['email'], ['external_id', 'updated_at']);
    }

    /**
     * @param array<int, array<string, mixed>> $chunk
     * @return array<string, int> email => user_id
     */
    private function mapEmailsToUserIds(array $chunk): array
    {
        $emails = [];
        foreach ($chunk as $row) {
            $emails[] = (string) $row['email'];
        }

        /** @var array<string, int> $map */
        $map = User::whereIn('email', array_values(array_unique($emails)))
            ->pluck('id', 'email')
            ->all();

        return $map;
    }

    /**
     * @param array<int, array<string, mixed>> $chunk
     * @param array<string, int> $userIdByEmail
     */
    private function upsertPlayers(int $versionId, array $chunk, array $userIdByEmail, string $now): void
    {
        $rows = [];
        foreach ($chunk as $row) {
            $userId = $userIdByEmail[(string) $row['email']];
            $rows[$userId] = [
                'version_id' => $versionId,
                'user_id' => $userId,
                'registered_at' => $this->toUtc($row['registered_at'] ?? null),
                'language' => $row['language'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // total_score is intentionally NOT updated: a re-send must not reset the
        // denormalized aggregate; new rows take the DB default (0).
        Player::upsert(
            array_values($rows),
            ['version_id', 'user_id'],
            ['registered_at', 'language', 'updated_at']
        );
    }

    /**
     * Normalizes a domain timestamp to UTC, or null.
     *
     * @param mixed $value
     */
    private function toUtc($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse($value)->utc()->toDateTimeString();
    }
}
