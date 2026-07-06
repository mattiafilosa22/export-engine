<?php

namespace App\Support\Ingestion;

use Illuminate\Support\Facades\DB;

/**
 * Resolves player emails to their player id within a version, joining
 * players to their user identity. Emails without a matching player in the
 * version are simply absent from the returned map (caller counts them failed).
 */
class PlayerResolver
{
    /**
     * @param array<int, string> $emails
     * @return array<string, int> email => player_id
     */
    public function resolve(int $versionId, array $emails): array
    {
        if ($emails === []) {
            return [];
        }

        $rows = DB::table('players')
            ->join('users', 'users.id', '=', 'players.user_id')
            ->where('players.version_id', $versionId)
            ->whereIn('users.email', array_values(array_unique($emails)))
            ->pluck('players.id', 'users.email');

        /** @var array<string, int> $map */
        $map = [];
        foreach ($rows as $email => $playerId) {
            $map[(string) $email] = (int) $playerId;
        }

        return $map;
    }

    /**
     * Returns the subset of the given player ids that actually belong to the
     * version (guards the events.player_id FK: unknown ids are skipped, never
     * inserted). Keyed by id for O(1) membership.
     *
     * @param array<int, int> $ids
     * @return array<int, bool> valid player_id => true
     */
    public function existingIds(int $versionId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $rows = DB::table('players')
            ->where('version_id', $versionId)
            ->whereIn('id', array_values(array_unique($ids)))
            ->pluck('id');

        $valid = [];
        foreach ($rows as $id) {
            $valid[(int) $id] = true;
        }

        return $valid;
    }
}
