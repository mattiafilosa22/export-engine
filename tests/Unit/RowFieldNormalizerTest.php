<?php

namespace Tests\Unit;

use App\Support\Ingestion\RowFieldNormalizer;
use Tests\TestCase;

class RowFieldNormalizerTest extends TestCase
{
    public function test_to_utc_normalizes_a_domain_timestamp(): void
    {
        $normalized = (new RowFieldNormalizer())->toUtc('2026-01-15T10:00:00+02:00');

        $this->assertSame('2026-01-15 08:00:00', $normalized);
    }

    public function test_normalize_dedup_key_keeps_a_real_key(): void
    {
        $key = (new RowFieldNormalizer())->normalizeDedupKey(['dedup_key' => 'evt-1']);

        $this->assertSame('evt-1', $key);
    }

    /**
     * @dataProvider blankDedupKeyProvider
     */
    public function test_normalize_dedup_key_returns_null_for_a_missing_or_blank_key(array $row): void
    {
        $this->assertNull((new RowFieldNormalizer())->normalizeDedupKey($row));
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public function blankDedupKeyProvider(): array
    {
        return [
            'missing' => [[]],
            'empty string' => [['dedup_key' => '']],
            'whitespace only' => [['dedup_key' => '   ']],
        ];
    }
}
