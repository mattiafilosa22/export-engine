<?php

namespace App\Support\Export\Spec;

/**
 * Immutable, validated description of one configurable sheet: a whitelisted
 * source, the output columns (plain or aggregate), whitelisted filters, the
 * group-by field aliases and the sort. No predefined shape: the client composes it.
 */
final class SheetSpec
{
    /** @var string */
    private $name;

    /** @var string */
    private $source;

    /** @var array<int, SheetColumn> */
    private $columns;

    /** @var array<string, mixed> */
    private $filters;

    /** @var array<int, string> */
    private $groupBy;

    /** @var array<int, array{column: string, direction: string}> */
    private $sort;

    /**
     * @param array<int, SheetColumn> $columns
     * @param array<string, mixed> $filters
     * @param array<int, string> $groupBy
     * @param array<int, array{column: string, direction: string}> $sort
     */
    public function __construct(
        string $name,
        string $source,
        array $columns,
        array $filters = [],
        array $groupBy = [],
        array $sort = []
    ) {
        $this->name = $name;
        $this->source = $source;
        $this->columns = $columns;
        $this->filters = $filters;
        $this->groupBy = $groupBy;
        $this->sort = $sort;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function source(): string
    {
        return $this->source;
    }

    /**
     * @return array<int, SheetColumn>
     */
    public function columns(): array
    {
        return $this->columns;
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return $this->filters;
    }

    /**
     * @return array<int, string>
     */
    public function groupBy(): array
    {
        return $this->groupBy;
    }

    /**
     * @return array<int, array{column: string, direction: string}>
     */
    public function sort(): array
    {
        return $this->sort;
    }

    public function hasAggregates(): bool
    {
        foreach ($this->columns as $column) {
            if ($column->isAggregate()) {
                return true;
            }
        }

        return false;
    }
}
