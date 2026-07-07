<?php

namespace App\Support\Ingestion;

use Illuminate\Support\Carbon;

/**
 * Pure field-normalization helpers shared by every ingestion job: a domain
 * timestamp to UTC, and the dedup_key blank/NULL contract (NULL never
 * collides in a UNIQUE index, so a keyless row always appends; a real key
 * still dedups).
 */
class RowFieldNormalizer
{
    /**
     * @param mixed $value
     */
    public function toUtc($value): string
    {
        return Carbon::parse($value)->utc()->toDateTimeString();
    }

    /**
     * @param array<string, mixed> $row
     */
    public function normalizeDedupKey(array $row): ?string
    {
        $key = isset($row['dedup_key']) ? trim((string) $row['dedup_key']) : '';

        return $key === '' ? null : $key;
    }
}
