<?php

namespace Tests\Unit\Export;

use App\Models\Event;
use App\Support\Export\Query\FilterApplier;
use Tests\TestCase;

class FilterApplierTest extends TestCase
{
    /** @var array<string, array{column: string, op: string}> */
    private $whitelist = [
        'type' => ['column' => 'type', 'op' => 'in'],
        'language' => ['column' => 'payload_language', 'op' => 'in'],
        'score' => ['column' => 'payload_score', 'op' => 'eq'],
        'occurred_from' => ['column' => 'occurred_at', 'op' => 'gte'],
        'occurred_to' => ['column' => 'occurred_at', 'op' => 'lte'],
    ];

    public function test_it_applies_whitelisted_filters_with_bound_values(): void
    {
        $query = Event::query();

        (new FilterApplier())->apply($query, $this->whitelist, [
            'type' => ['game_completed', 'answer_submitted'],
            'occurred_from' => '2026-01-01',
            'score' => 100,
        ]);

        $sql = $query->toSql();
        $this->assertStringContainsString('`type` in (?, ?)', $sql);
        $this->assertStringContainsString('`occurred_at` >= ?', $sql);
        $this->assertStringContainsString('`payload_score` = ?', $sql);
        $this->assertSame(['game_completed', 'answer_submitted', '2026-01-01', 100], $query->getBindings());
    }

    public function test_it_ignores_filters_not_in_the_whitelist(): void
    {
        $query = Event::query();

        (new FilterApplier())->apply($query, $this->whitelist, ['unknown' => 'x', 'type' => ['a']]);

        $this->assertStringNotContainsString('unknown', $query->toSql());
        $this->assertSame(['a'], $query->getBindings());
    }
}
