<?php

namespace App\Support\Export\Sheet;

use Illuminate\Support\Facades\DB;

/**
 * Runs data-quality checks against a version's events, for the Data_Quality
 * sheet. Checks are picked from what this schema can actually violate:
 * `events.player_id` has an FK RESTRICT to `players` (like the typed tables),
 * so an orphan event is structurally impossible — not worth a check. Invalid
 * chronology is still possible: the FK guarantees the player exists, not that
 * the event happened after they registered.
 */
class DataQualityChecker
{
    public const SEVERITY_ERROR = 'error';
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_INFO = 'info';

    /**
     * @return array<int, array{check: string, severity: string, occurrences: int, description: string}>
     */
    public function run(int $versionId): array
    {
        return [
            $this->missingLanguage($versionId),
            $this->emptyPayload($versionId),
            $this->invalidEventOrder($versionId),
        ];
    }

    /**
     * @return array{check: string, severity: string, occurrences: int, description: string}
     */
    private function missingLanguage(int $versionId): array
    {
        $count = DB::table('events')
            ->where('version_id', $versionId)
            ->whereNull('payload_language')
            ->count();

        return $this->result(
            'missing_language',
            self::SEVERITY_WARNING,
            $count,
            'Eventi senza payload.language.'
        );
    }

    /**
     * @return array{check: string, severity: string, occurrences: int, description: string}
     */
    private function emptyPayload(int $versionId): array
    {
        $count = DB::table('events')
            ->where('version_id', $versionId)
            ->whereRaw('json_length(payload) = 0')
            ->count();

        return $this->result(
            'empty_payload',
            self::SEVERITY_INFO,
            $count,
            'Eventi con payload JSON vuoto.'
        );
    }

    /**
     * Events that occurred before the player's own registration.
     *
     * @return array{check: string, severity: string, occurrences: int, description: string}
     */
    private function invalidEventOrder(int $versionId): array
    {
        $count = DB::table('events')
            ->join('players', function ($join) use ($versionId) {
                $join->on('players.id', '=', 'events.player_id')
                    ->where('players.version_id', $versionId);
            })
            ->where('events.version_id', $versionId)
            ->whereNotNull('players.registered_at')
            ->whereColumn('events.occurred_at', '<', 'players.registered_at')
            ->count();

        return $this->result(
            'invalid_event_order',
            self::SEVERITY_ERROR,
            $count,
            'Eventi avvenuti prima della registrazione del player.'
        );
    }

    /**
     * @return array{check: string, severity: string, occurrences: int, description: string}
     */
    private function result(string $check, string $severity, int $occurrences, string $description): array
    {
        return [
            'check' => $check,
            'severity' => $severity,
            'occurrences' => $occurrences,
            'description' => $description,
        ];
    }
}
