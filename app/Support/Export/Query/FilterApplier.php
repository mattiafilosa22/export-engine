<?php

namespace App\Support\Export\Query;

use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * Generic, reusable filter layer. Applies only whitelisted aliases to a query;
 * each whitelist entry names the real column and an operator. Column identifiers
 * come from config (trusted); filter values are always bound. Aliases absent
 * from the whitelist are ignored (already rejected upstream at validation time).
 */
class FilterApplier
{
    private const OP_IN = 'in';
    private const OP_EQ = 'eq';
    private const OP_GTE = 'gte';
    private const OP_LTE = 'lte';

    /**
     * @param array<string, array{column: string, op: string}> $whitelist
     * @param array<string, mixed> $filters
     */
    public function apply(Builder $query, array $whitelist, array $filters): Builder
    {
        foreach ($filters as $alias => $value) {
            if (! isset($whitelist[$alias])) {
                continue;
            }

            $this->applyOne($query, $whitelist[$alias]['column'], $whitelist[$alias]['op'], $value);
        }

        return $query;
    }

    /**
     * @param mixed $value
     */
    private function applyOne(Builder $query, string $column, string $op, $value): void
    {
        switch ($op) {
            case self::OP_IN:
                $query->whereIn($column, is_array($value) ? array_values($value) : [$value]);
                return;
            case self::OP_EQ:
                $query->where($column, '=', $value);
                return;
            case self::OP_GTE:
                $query->where($column, '>=', $value);
                return;
            case self::OP_LTE:
                $query->where($column, '<=', $value);
                return;
        }

        throw new InvalidArgumentException("Unsupported filter operator: {$op}");
    }
}
