<?php

namespace Tests\Unit\Ingestion;

use App\Support\Ingestion\ReferenceSet;
use App\Support\Ingestion\TypedRecordMapper;
use PHPUnit\Framework\TestCase;

class TypedRecordMapperTest extends TestCase
{
    private const BASE = [
        'version_id' => 1,
        'player_id' => 2,
        'event_id' => 3,
        'occurred_at' => '2026-01-01 00:00:00',
        'created_at' => '2026-01-02 00:00:00',
    ];

    public function test_it_maps_a_valid_answer_with_statics_and_occurred_at(): void
    {
        $mapper = $this->mapper();
        $refs = new ReferenceSet(['question' => [10 => true], 'answer_option' => [100 => true]]);

        $result = $mapper->map('answer_submitted', ['question_id' => 10, 'answer_option_id' => 100], self::BASE, $refs);

        $this->assertNotNull($result);
        $this->assertSame('answers', $result['table']);
        $this->assertSame(3, $result['row']['event_id']);
        $this->assertSame(10, $result['row']['question_id']);
        $this->assertSame(100, $result['row']['answer_option_id']);
        $this->assertSame('2026-01-01 00:00:00', $result['row']['occurred_at']);
    }

    public function test_it_applies_static_defaults_the_payload_can_override(): void
    {
        $mapper = $this->mapper();
        $refs = new ReferenceSet([]);

        $purchase = ['type' => 'purchase', 'amount' => 9.99, 'currency' => 'EUR'];
        $default = $mapper->map('transaction', $purchase, self::BASE, $refs);
        $this->assertSame('completed', $default['row']['status']);

        $override = $mapper->map(
            'transaction',
            ['type' => 'purchase', 'amount' => 9.99, 'currency' => 'EUR', 'status' => 'pending'],
            self::BASE,
            $refs
        );
        $this->assertSame('pending', $override['row']['status']);
    }

    public function test_it_skips_when_a_required_field_is_missing(): void
    {
        $refs = new ReferenceSet(['answer_option' => [100 => true]]);

        $reason = null;
        $result = $this->mapper()->map('answer_submitted', ['answer_option_id' => 100], self::BASE, $refs, $reason);

        $this->assertNull($result);
        $this->assertStringContainsString('question_id', (string) $reason);
    }

    public function test_it_skips_when_require_any_is_not_satisfied(): void
    {
        $refs = new ReferenceSet(['question' => [10 => true]]);

        $reason = null;
        $result = $this->mapper()->map('answer_submitted', ['question_id' => 10], self::BASE, $refs, $reason);

        $this->assertNull($result);
        $this->assertStringContainsString('require_any', (string) $reason);
    }

    public function test_it_skips_on_an_unknown_reference(): void
    {
        $refs = new ReferenceSet(['question' => [10 => true], 'answer_option' => [100 => true]]);

        $payload = ['question_id' => 999, 'answer_option_id' => 100];
        $result = $this->mapper()->map('answer_submitted', $payload, self::BASE, $refs);

        $this->assertNull($result);
    }

    public function test_it_skips_on_a_value_outside_one_of(): void
    {
        $payload = ['type' => 'gift', 'amount' => 1, 'currency' => 'EUR'];
        $result = $this->mapper()->map('transaction', $payload, self::BASE, new ReferenceSet([]));

        $this->assertNull($result);
    }

    public function test_it_skips_on_a_non_numeric_value(): void
    {
        $payload = ['type' => 'purchase', 'amount' => 'lots', 'currency' => 'EUR'];
        $result = $this->mapper()->map('transaction', $payload, self::BASE, new ReferenceSet([]));

        $this->assertNull($result);
    }

    public function test_an_unmapped_type_is_not_handled(): void
    {
        $mapper = $this->mapper();

        $this->assertFalse($mapper->handles('game_completed'));
        $this->assertNull($mapper->map('game_completed', [], self::BASE, new ReferenceSet([])));
    }

    private function mapper(): TypedRecordMapper
    {
        return new TypedRecordMapper([
            'answer_submitted' => [
                'table' => 'answers',
                'dedup' => true,
                'occurred_at' => 'occurred_at',
                'fields' => [
                    'question_id' => ['column' => 'question_id', 'required' => true, 'ref' => 'question'],
                    'answer_option_id' => ['column' => 'answer_option_id', 'ref' => 'answer_option'],
                    'answer_text' => ['column' => 'answer_text'],
                ],
                'require_any' => ['answer_option_id', 'answer_text'],
            ],
            'transaction' => [
                'table' => 'transactions',
                'dedup' => false,
                'occurred_at' => 'occurred_at',
                'statics' => ['status' => 'completed'],
                'fields' => [
                    'type' => ['column' => 'type', 'required' => true, 'one_of' => ['purchase', 'spend', 'refund']],
                    'amount' => ['column' => 'amount', 'required' => true, 'numeric' => true],
                    'currency' => ['column' => 'currency', 'required' => true],
                    'status' => ['column' => 'status', 'one_of' => ['pending', 'completed', 'failed']],
                ],
            ],
        ]);
    }
}
