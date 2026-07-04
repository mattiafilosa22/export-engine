<?php

namespace App\Support\Export;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\Common\Creator\WriterEntityFactory;

/**
 * Thin wrapper over OpenSpout 3.x: streams an XLSX at constant memory.
 * Isolates the library API from the rest of the app (single point to touch
 * when the writer changes). Does not materialize rows: consumes the iterable.
 */
class EventXlsxWriter
{
    /**
     * Writes header + rows to file and returns the number of data rows written.
     *
     * @param iterable<array<int, scalar|null>> $rows
     * @param array<int, string> $header
     */
    public function write(string $absolutePath, iterable $rows, array $header): int
    {
        $writer = WriterEntityFactory::createXLSXWriter();
        $writer->openToFile($absolutePath);

        try {
            $writer->addRow(WriterEntityFactory::createRowFromArray($header));

            $count = 0;
            foreach ($rows as $row) {
                $writer->addRow(WriterEntityFactory::createRowFromArray($this->normalize($row)));
                $count++;
            }
        } finally {
            $writer->close();
        }

        return $count;
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
