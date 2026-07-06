<?php

namespace App\Support\Ingestion;

use Illuminate\Support\Facades\DB;

/**
 * Writes the typed records (answers/transactions/rewards) correlated to a set of
 * freshly-inserted events, each linked to its real event_id. Owns the whole
 * typed-record concern: version-scoped reference loading (FK existence),
 * per-event mapping (via TypedRecordMapper) and the per-table insert strategy
 * (dedup vs plain). Returns the events it had to skip so the caller can log
 * them with its own correlation id; never fails the batch.
 */
class TypedRecordWriter
{
    /** @var TypedRecordMapper */
    private $mapper;

    /** @var array<string, mixed> */
    private $records;

    /** @var array<string, string> */
    private $referenceTables;

    /**
     * @param array<string, mixed>|null $records the ingestion.typed_records map
     * @param array<string, string>|null $referenceTables ref name => table
     */
    public function __construct(TypedRecordMapper $mapper, ?array $records = null, ?array $referenceTables = null)
    {
        $this->mapper = $mapper;
        $this->records = $records ?? (array) config('gamindo.ingestion.typed_records');
        $this->referenceTables = $referenceTables ?? (array) config('gamindo.ingestion.reference_tables');
    }

    /**
     * @param array<int, array<string, mixed>> $events new events in insertion
     *     order; each has player_id, type, occurred_at, payload
     * @param int $firstId auto-increment id of the first inserted event
     * @return array<int, array{event_id:int, type:string, reason:string|null}> skipped
     */
    public function write(int $versionId, array $events, int $firstId, string $now): array
    {
        $references = $this->loadReferences($versionId, $events);
        $byTable = [];
        $skipped = [];

        foreach ($events as $offset => $event) {
            $eventId = $firstId + $offset;
            $base = [
                'version_id' => $versionId,
                'player_id' => $event['player_id'],
                'event_id' => $eventId,
                'occurred_at' => $event['occurred_at'],
                'created_at' => $now,
            ];

            $reason = null;
            $mapped = $this->mapper->map($event['type'], $event['payload'], $base, $references, $reason);

            if ($mapped !== null) {
                $byTable[$mapped['table']][] = $mapped['row'];
                continue;
            }

            if ($this->mapper->handles($event['type'])) {
                $skipped[] = ['event_id' => $eventId, 'type' => $event['type'], 'reason' => $reason];
            }
        }

        $this->insert($byTable);

        return $skipped;
    }

    /**
     * Loads the valid foreign ids (per ref name) referenced by the events, scoped
     * to the version — so an unknown id is skipped by the mapper, not fatal.
     *
     * @param array<int, array<string, mixed>> $events
     */
    private function loadReferences(int $versionId, array $events): ReferenceSet
    {
        $needed = [];
        foreach ($events as $event) {
            $spec = $this->records[$event['type']] ?? null;
            if ($spec === null) {
                continue;
            }
            foreach ($spec['fields'] as $key => $field) {
                if (isset($field['ref']) && isset($event['payload'][$key])) {
                    $needed[$field['ref']][] = (int) $event['payload'][$key];
                }
            }
        }

        $sets = [];
        foreach ($needed as $ref => $ids) {
            $valid = DB::table($this->referenceTables[$ref])
                ->where('version_id', $versionId)
                ->whereIn('id', array_values(array_unique($ids)))
                ->pluck('id');
            $flags = [];
            foreach ($valid as $id) {
                $flags[(int) $id] = true;
            }
            $sets[$ref] = $flags;
        }

        return new ReferenceSet($sets);
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $byTable
     */
    private function insert(array $byTable): void
    {
        $dedupTables = $this->dedupTables();

        foreach ($byTable as $table => $rows) {
            if ($rows === []) {
                continue;
            }
            if (isset($dedupTables[$table])) {
                DB::table($table)->insertOrIgnore($rows);
            } else {
                DB::table($table)->insert($rows);
            }
        }
    }

    /**
     * Tables whose typed records dedup on their unique key (insertOrIgnore).
     *
     * @return array<string, bool>
     */
    private function dedupTables(): array
    {
        $tables = [];
        foreach ($this->records as $spec) {
            if (! empty($spec['dedup'])) {
                $tables[$spec['table']] = true;
            }
        }

        return $tables;
    }
}
