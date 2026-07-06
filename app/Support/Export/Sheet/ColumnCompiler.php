<?php

namespace App\Support\Export\Sheet;

use App\Support\Export\Spec\SheetColumn;
use InvalidArgumentException;

/**
 * Compiles a sheet column (or a field alias) to a safe SQL expression using the
 * source's field whitelist. Only whitelisted identifiers are ever produced; the
 * caller aliases the expression to a generated name, so no user label reaches SQL.
 */
final class ColumnCompiler
{
    /** @var array<string, string> alias => real column */
    private $fields;

    /**
     * @param array<string, string> $fields
     */
    public function __construct(array $fields)
    {
        $this->fields = $fields;
    }

    /**
     * The SQL expression for a column: a whitelisted real column, or an aggregate.
     */
    public function expression(SheetColumn $column): string
    {
        return $column->isAggregate()
            ? $this->aggregate($column)
            : $this->realField($column->field());
    }

    /**
     * Resolves a public alias to its real (whitelisted) column, or throws.
     */
    public function realField(?string $alias): string
    {
        if ($alias === null || ! isset($this->fields[$alias])) {
            throw new InvalidArgumentException('Unknown field alias: ' . (string) $alias);
        }

        return $this->fields[$alias];
    }

    private function aggregate(SheetColumn $column): string
    {
        $fn = $column->fn();
        $field = $column->field();

        switch ($fn) {
            case 'count':
                return 'COUNT(*)';
            case 'count_distinct':
                return 'COUNT(DISTINCT ' . $this->realField($field) . ')';
            case 'avg':
                return 'AVG(' . $this->realField($field) . ')';
            case 'sum':
                return 'SUM(' . $this->realField($field) . ')';
            case 'min':
                return 'MIN(' . $this->realField($field) . ')';
            case 'max':
                return 'MAX(' . $this->realField($field) . ')';
        }

        throw new InvalidArgumentException("Unsupported aggregate: {$fn}");
    }
}
