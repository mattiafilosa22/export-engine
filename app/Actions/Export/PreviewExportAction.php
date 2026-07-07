<?php

namespace App\Actions\Export;

use App\Models\Version;
use App\Support\Export\ExportSpecParser;
use App\Support\Export\Query\FilterApplier;
use App\Support\Export\Sheet\GenericSheetBuilder;
use Illuminate\Support\Carbon;

/**
 * Builds a synchronous, bounded preview of an export (no job, no XLSX): reuses the
 * export engine (parser + sheet builder) and returns at most preview_rows rows per
 * sheet, flagging truncation. A quick look before the heavy async export.
 */
class PreviewExportAction
{
    /** @var ExportSpecParser */
    private $parser;

    /** @var FilterApplier */
    private $filters;

    public function __construct(ExportSpecParser $parser, FilterApplier $filters)
    {
        $this->parser = $parser;
        $this->filters = $filters;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function execute(Version $version, array $params): array
    {
        $limit = (int) config('gamindo.export.preview_rows', 100);
        $date = Carbon::now()->format('Y-m-d');
        $versionId = (int) $version->id;

        $sheets = [];
        foreach ($this->parser->parseParams($params, $date)->sheets() as $spec) {
            $builder = new GenericSheetBuilder($spec, $versionId, $this->filters);
            $sheets[] = $this->previewSheet($builder, $limit);
        }

        return ['sheets' => $sheets];
    }

    /**
     * Reads up to $limit rows; reading one more would only prove truncation, so
     * the loop breaks the moment it sees row $limit+1.
     *
     * @return array<string, mixed>
     */
    private function previewSheet(GenericSheetBuilder $builder, int $limit): array
    {
        $header = $builder->header();
        $rows = [];
        $truncated = false;

        foreach ($builder->rows() as $row) {
            if (count($rows) >= $limit) {
                $truncated = true;
                break;
            }
            $rows[] = $this->associate($header, (array) $row);
        }

        return [
            'name' => $builder->name(),
            'header' => $header,
            'rows' => $rows,
            'truncated' => $truncated,
        ];
    }

    /**
     * Maps a positional row to a header-keyed object for a readable JSON preview.
     *
     * @param array<int, string> $header
     * @param array<int, scalar|null> $row
     * @return array<string, scalar|null>|array<int, scalar|null>
     */
    private function associate(array $header, array $row)
    {
        if (count($header) !== count($row)) {
            return $row; // defensive: shape mismatch → keep positional
        }

        return array_combine($header, $row);
    }
}
