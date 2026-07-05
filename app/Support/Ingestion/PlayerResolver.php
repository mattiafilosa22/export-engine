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
}
