<?php

namespace App\Support\Export\Sheet;

use App\Support\Export\Query\FilterApplier;
use App\Support\Export\Spec\SheetColumn;
use App\Support\Export\Spec\SheetSpec;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;

/**
 * The single, configurable sheet engine: given a validated SheetSpec and the
 * source whitelist from config, it builds one query (columns/filters/group_by/
 * sort) and streams the rows. No predefined sheets — the client composes each.
 *
 * Only whitelisted column identifiers reach SQL; filter values are bound. User
 * labels never touch SQL: every projection is aliased to a generated `cN` name
 * and mapped back to the label only in the output rows.
 */
class GenericSheetBuilder implements Sheet
{
    private const KEYSET_ID = 'id';

    /** @var SheetSpec */
    private $spec;

    /** @var int */
    private $versionId;

    /** @var FilterApplier */
    private $filters;

    /** @var array<string, mixed> */
    private $source;

    /** @var ColumnCompiler */
    private $compiler;

    public function __construct(SheetSpec $spec, int $versionId, FilterApplier $filters)
    {
        $this->spec = $spec;
        $this->versionId = $versionId;
        $this->filters = $filters;
        $this->source = (array) config('gamindo.export.sources.' . $spec->source());
        $this->compiler = new ColumnCompiler($this->source['fields'] ?? []);
    }

    public function name(): string
    {
        return $this->spec->name();
    }

    /**
     * @return array<int, string>
     */
    public function header(): array
    {
        return array_map(static function (SheetColumn $column): string {
            return $column->label();
        }, $this->spec->columns());
    }

    /**
     * @return iterable<int, array<int, scalar|null>>
     */
    public function rows(): iterable
    {
        $columns = $this->spec->columns();
        $query = $this->baseQuery();

        foreach ($columns as $index => $column) {
            $query->addSelect($this->projection($column, $index));
        }
        $this->applyGroupBy($query);
        $this->applySort($query);

        foreach ($this->read($query) as $record) {
            yield $this->mapRow($record, $columns);
        }
    }

    private function baseQuery(): Builder
    {
        /** @var class-string<\Illuminate\Database\Eloquent\Model> $model */
        $model = $this->source['model'];
        $query = $model::query();

        $this->applyJoins($query);
        $query->where($this->versionColumn(), $this->versionId);
        $this->filters->apply($query, $this->source['filters'] ?? [], $this->spec->filters());

        return $query;
    }

    /**
     * Applies the source's config-declared joins. Tables/aliases/columns are
     * config data (never user input), so join identifiers are trusted.
     */
    private function applyJoins(Builder $query): void
    {
        foreach ($this->source['joins'] ?? [] as $join) {
            $conditions = $join['on'];
            $method = ($join['type'] ?? 'inner') === 'left' ? 'leftJoin' : 'join';
            $table = $join['table'] . ' as ' . $join['alias'];

            $query->{$method}($table, function (JoinClause $j) use ($conditions): void {
                foreach ($conditions as $on) {
                    $j->on($on[0], $on[1], $on[2]);
                }
            });
        }
    }

    private function versionColumn(): string
    {
        return isset($this->source['version_column']) ? (string) $this->source['version_column'] : 'version_id';
    }

    private function keyColumn(): string
    {
        return isset($this->source['key']) ? (string) $this->source['key'] : self::KEYSET_ID;
    }

    private function hasJoins(): bool
    {
        return ! empty($this->source['joins']);
    }

    /**
     * Builds a safe `expr as cN` projection: identifiers come from the whitelist,
     * the alias is generated, so the user label never appears in SQL.
     */
    private function projection(SheetColumn $column, int $index): \Illuminate\Database\Query\Expression
    {
        $alias = 'c' . $index;

        return $this->raw($this->compiler->expression($column) . ' as ' . $alias);
    }

    private function applyGroupBy(Builder $query): void
    {
        foreach ($this->spec->groupBy() as $alias) {
            $query->groupBy($this->compiler->realField($alias));
        }
    }

    private function applySort(Builder $query): void
    {
        foreach ($this->spec->sort() as $entry) {
            $query->orderBy($this->compiler->realField($entry['column']), $entry['direction']);
        }
    }

    /**
     * Read strategy, chosen explicitly for memory:
     * - join sources always stream via cursor: keyset on `id` is ambiguous across
     *   joined tables; a detail join sheet gets a qualified `key` tiebreaker;
     * - the common large detail export (no join) is UNSORTED -> keyset (lazyById),
     *   constant memory, never OFFSET;
     * - aggregated/grouped sheets return small result sets -> cursor;
     * - a client-requested sort on a detail sheet -> cursor with an id tiebreaker
     *   for determinism. Honouring an arbitrary sort with keyset would need a
     *   composite (sort, id) cursor (out of scope); very large sorted detail
     *   exports should be narrowed with filters.
     *
     * @return iterable<int, \Illuminate\Database\Eloquent\Model>
     */
    private function read(Builder $query): iterable
    {
        $aggregated = $this->spec->hasAggregates() || $this->spec->groupBy() !== [];

        if ($this->hasJoins()) {
            if (! $aggregated) {
                $query->orderBy($this->keyColumn());
            }
            return $query->cursor();
        }

        if ($aggregated) {
            return $query->cursor();
        }

        if ($this->spec->sort() === []) {
            $query->addSelect(self::KEYSET_ID);
            return $query->lazyById((int) config('gamindo.export.keyset_chunk'));
        }

        return $query->orderBy(self::KEYSET_ID)->cursor();
    }

    /**
     * @param array<int, SheetColumn> $columns
     * @return array<int, scalar|null>
     */
    private function mapRow(object $record, array $columns): array
    {
        $row = [];
        foreach ($columns as $index => $column) {
            $row[] = $this->castValue($column, $record->{'c' . $index});
        }

        return $row;
    }

    /**
     * Projected values arrive as scalars from the driver (raw `cN` aliases are
     * not model-cast). Counts/sums are normalized to int, averages rounded;
     * plain fields and min/max pass through with their native driver type.
     *
     * @param mixed $value
     * @return scalar|null
     */
    private function castValue(SheetColumn $column, $value)
    {
        if (! $column->isAggregate()) {
            return $value;
        }

        switch ($column->fn()) {
            case 'count':
            case 'count_distinct':
            case 'sum':
                return $value === null ? null : (int) $value;
            case 'avg':
                return $value === null ? null : round((float) $value, 2);
        }

        return $value;
    }

    private function raw(string $expression): \Illuminate\Database\Query\Expression
    {
        return \Illuminate\Support\Facades\DB::raw($expression);
    }
}
