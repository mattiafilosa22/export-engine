<?php

namespace App\Support\Export;

use App\Support\Export\Sheet\Sheet;
use OpenSpout\Writer\Common\Creator\WriterEntityFactory;

/**
 * Thin wrapper over OpenSpout 3.x: streams a multi-sheet XLSX at constant memory.
 * Isolates the library API from the rest of the app (single point to touch when
 * the writer changes). Does not materialize rows: consumes each sheet's iterable.
 */
class XlsxExportWriter
{
    private const MAX_SHEET_NAME = 31;

    /**
     * Writes every sheet (name, header, streamed rows) and returns the total
     * number of data rows written across all sheets.
     *
     * @param iterable<int, Sheet> $sheets
     */
    public function write(string $absolutePath, iterable $sheets): int
    {
        $writer = WriterEntityFactory::createXLSXWriter();
        $writer->openToFile($absolutePath);

        $usedNames = [];
        $count = 0;
        $first = true;

        try {
            foreach ($sheets as $sheet) {
                $current = $first ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
                $first = false;
                $current->setName($this->uniqueSheetName($sheet->name(), $usedNames));

                $writer->addRow(WriterEntityFactory::createRowFromArray($sheet->header()));
                foreach ($sheet->rows() as $row) {
                    $writer->addRow(WriterEntityFactory::createRowFromArray($this->normalize($row)));
                    $count++;
                }
            }
        } finally {
            $writer->close();
        }

        return $count;
    }

    /**
     * Sanitizes a sheet name to XLSX limits and guarantees uniqueness in the book.
     *
     * @param array<string, bool> $usedNames
     */
    private function uniqueSheetName(string $name, array &$usedNames): string
    {
        // XLSX forbids : \ / ? * [ ] in sheet names and caps them at 31 chars.
        $clean = trim((string) preg_replace('/[:\\\\\/?*\[\]]/', ' ', $name));
        $base = $clean === '' ? 'Sheet' : $this->truncate($clean, self::MAX_SHEET_NAME);

        $candidate = $base;
        $suffix = 2;
        while (isset($usedNames[$candidate])) {
            $tag = ' (' . $suffix . ')';
            $candidate = $this->truncate($base, self::MAX_SHEET_NAME - strlen($tag)) . $tag;
            $suffix++;
        }

        $usedNames[$candidate] = true;

        return $candidate;
    }

    private function truncate(string $value, int $length): string
    {
        return $length >= strlen($value) ? $value : substr($value, 0, $length);
    }

    /**
     * @param array<int, scalar|null> $row
     * @return array<int, scalar>
     */
    private function normalize(array $row): array
    {
        // OpenSpout writes empty cells for null; normalize to string for consistency.
        return array_map(static function ($value) {
            return $value ?? '';
        }, $row);
    }
}
