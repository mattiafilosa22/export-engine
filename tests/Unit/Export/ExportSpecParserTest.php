<?php

namespace Tests\Unit\Export;

use App\Models\Export;
use App\Support\Export\ExportSpecParser;
use App\Support\Export\Spec\SheetColumn;
use Tests\TestCase;

class ExportSpecParserTest extends TestCase
{
    public function test_empty_params_fall_back_to_a_default_events_sheet(): void
    {
        $sheets = (new ExportSpecParser())->parse($this->exportWithParams([]))->sheets();

        $this->assertCount(1, $sheets);
        $this->assertSame('events', $sheets[0]->source());
        $this->assertSame(
            ['id', 'type', 'occurred_at', 'language', 'score'],
            $this->labels($sheets[0]->columns())
        );
        $this->assertSame([], $sheets[0]->groupBy());
    }

    public function test_it_parses_a_configured_sheet_and_normalizes_columns(): void
    {
        $export = $this->exportWithParams(['sheets' => [[
            'source' => 'events',
            'name' => 'My Sheet',
            'columns' => [
                'type',
                ['field' => 'score', 'as' => 'the_score'],
                ['fn' => 'avg', 'field' => 'score', 'as' => 'avg_score'],
                ['fn' => 'count', 'as' => 'events_count'],
            ],
            'group_by' => ['type'],
            'filters' => ['language' => ['it']],
            'sort' => [['column' => 'score', 'direction' => 'DESC']],
        ]]]);

        $sheet = (new ExportSpecParser())->parse($export)->sheets()[0];
        $columns = $sheet->columns();

        $this->assertSame('My Sheet', $sheet->name());
        $this->assertSame(['type', 'the_score', 'avg_score', 'events_count'], $this->labels($columns));

        $this->assertPlain($columns[0], 'type');
        $this->assertPlain($columns[1], 'score');
        $this->assertAggregate($columns[2], 'avg', 'score');
        $this->assertAggregate($columns[3], 'count', null);

        $this->assertSame(['type'], $sheet->groupBy());
        $this->assertSame(['language' => ['it']], $sheet->filters());
        $this->assertSame([['column' => 'score', 'direction' => 'desc']], $sheet->sort());
    }

    public function test_it_maps_a_traccia_summary_sheet_by_name_metrics_and_group_by(): void
    {
        $export = $this->exportWithParams([
            'date_from' => '2026-01-01',
            'date_to' => '2026-01-31',
            'sheets' => [[
                'name' => 'events_summary',
                'group_by' => ['type', 'payload.language'],
                'metrics' => ['count', 'unique_players'],
            ]],
        ]);

        $sheet = (new ExportSpecParser())->parse($export)->sheets()[0];

        // name maps to a source; dot-notation stripped; group_by dims become plain columns.
        $this->assertSame('events', $sheet->source());
        $this->assertSame(['type', 'language'], $sheet->groupBy());
        $this->assertSame(['type', 'language', 'count', 'unique_players'], $this->labels($sheet->columns()));
        $this->assertPlain($sheet->columns()[1], 'language');
        $this->assertAggregate($sheet->columns()[2], 'count', null);
        $this->assertAggregate($sheet->columns()[3], 'count_distinct', 'player_id');

        // Top-level date range becomes per-sheet event filters.
        $this->assertSame(['occurred_from' => '2026-01-01', 'occurred_to' => '2026-01-31'], $sheet->filters());
    }

    public function test_it_defaults_the_source_and_auto_names_a_sheet(): void
    {
        $export = new Export();
        $export->created_at = '2026-07-06 09:00:00';
        $export->params = ['sheets' => [['columns' => ['id', 'type']]]];

        $sheet = (new ExportSpecParser())->parse($export)->sheets()[0];

        $this->assertSame('events', $sheet->source());
        $this->assertSame('2026-07-06 — Foglio 1', $sheet->name());
    }

    public function test_it_parses_string_sort_entries_and_strips_dot_notation(): void
    {
        $export = $this->exportWithParams(['sheets' => [[
            'source' => 'events',
            'columns' => ['id'],
            'sort' => ['occurred_at:desc', 'payload.score', 'id'],
        ]]]);

        $sheet = (new ExportSpecParser())->parse($export)->sheets()[0];

        $this->assertSame([
            ['column' => 'occurred_at', 'direction' => 'desc'],
            ['column' => 'score', 'direction' => 'asc'],
            ['column' => 'id', 'direction' => 'asc'],
        ], $sheet->sort());
    }

    /**
     * @param array<string, mixed> $params
     */
    private function exportWithParams(array $params): Export
    {
        $export = new Export();
        $export->params = $params;

        return $export;
    }

    /**
     * @param array<int, SheetColumn> $columns
     * @return array<int, string>
     */
    private function labels(array $columns): array
    {
        $labels = [];
        foreach ($columns as $column) {
            $labels[] = $column->label();
        }

        return $labels;
    }

    private function assertPlain(SheetColumn $column, string $field): void
    {
        $this->assertFalse($column->isAggregate());
        $this->assertSame($field, $column->field());
        $this->assertNull($column->fn());
    }

    private function assertAggregate(SheetColumn $column, string $fn, ?string $field): void
    {
        $this->assertTrue($column->isAggregate());
        $this->assertSame($fn, $column->fn());
        $this->assertSame($field, $column->field());
    }
}
