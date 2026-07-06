<?php

namespace App\Support\Ingestion;

/**
 * Turns an ingested event into its typed record (answer/transaction/reward) from
 * the `ingestion.typed_records` config map. Pure and config-driven: applies fixed
 * `statics`, then maps payload `fields` (required / one_of / numeric / foreign
 * `ref`) onto columns, mapping the event's occurred_at onto the declared column.
 * Returns null (with a reason) when the event carries no mapping or the payload
 * lacks/violates the expected fields — the job skips it without failing the batch.
 */
class TypedRecordMapper
{
    /** @var array<string, mixed> */
    private $config;

    /**
     * @param array<string, mixed>|null $config the ingestion.typed_records map
     */
    public function __construct(?array $config = null)
    {
        $this->config = $config ?? (array) config('gamindo.ingestion.typed_records');
    }

    /**
     * Whether this event type is configured to also produce a typed record.
     */
    public function handles(string $type): bool
    {
        return isset($this->config[$type]);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $base version_id, player_id, event_id, occurred_at, created_at
     * @param string|null $reason set to the skip reason when null is returned
     * @return array{table: string, row: array<string, mixed>}|null
     */
    public function map(string $type, array $payload, array $base, ReferenceSet $refs, ?string &$reason = null): ?array
    {
        $spec = $this->config[$type] ?? null;
        if ($spec === null) {
            $reason = 'no mapping';
            return null;
        }

        $row = [
            'version_id' => $base['version_id'],
            'player_id' => $base['player_id'],
            'event_id' => $base['event_id'],
            'created_at' => $base['created_at'],
        ];

        if (isset($spec['occurred_at'])) {
            $row[$spec['occurred_at']] = $base['occurred_at'];
        }

        foreach ($spec['statics'] ?? [] as $column => $value) {
            $row[$column] = $value;
        }

        foreach ($spec['fields'] as $key => $field) {
            if (! $this->present($payload, $key)) {
                if (! empty($field['required'])) {
                    $reason = "missing field '{$key}'";
                    return null;
                }
                continue;
            }

            $value = $payload[$key];

            if (isset($field['one_of']) && ! in_array($value, $field['one_of'], true)) {
                $reason = "invalid value for '{$key}'";
                return null;
            }
            if (! empty($field['numeric']) && ! is_numeric($value)) {
                $reason = "non-numeric '{$key}'";
                return null;
            }
            if (isset($field['ref']) && ! $refs->has($field['ref'], (int) $value)) {
                $reason = "unknown {$field['ref']} '{$value}'";
                return null;
            }

            $row[$field['column']] = $value;
        }

        if (isset($spec['require_any']) && ! $this->anyPresent($spec, $row)) {
            $reason = 'require_any not satisfied';
            return null;
        }

        return ['table' => $spec['table'], 'row' => $row];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function present(array $payload, string $key): bool
    {
        return array_key_exists($key, $payload) && $payload[$key] !== null && $payload[$key] !== '';
    }

    /**
     * @param array<string, mixed> $spec
     * @param array<string, mixed> $row
     */
    private function anyPresent(array $spec, array $row): bool
    {
        foreach ($spec['require_any'] as $key) {
            $column = $spec['fields'][$key]['column'];
            if (isset($row[$column]) && $row[$column] !== null && $row[$column] !== '') {
                return true;
            }
        }

        return false;
    }
}
