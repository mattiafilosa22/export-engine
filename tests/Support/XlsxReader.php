<?php

namespace Tests\Support;

use OpenSpout\Reader\Common\Creator\ReaderEntityFactory;

/**
 * Test helper: reads an XLSX back into a map of sheet name => rows (each row an
 * array of cell values). Used to assert exported sheet contents.
 */
class XlsxReader
{
    /**
     * @return array<string, array<int, array<int, mixed>>>
     */
    public static function read(string $path): array
    {
        $reader = ReaderEntityFactory::createXLSXReader();
        $reader->open($path);

        $sheets = [];
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $rows = [];
                foreach ($sheet->getRowIterator() as $row) {
                    $rows[] = $row->toArray();
                }
                $sheets[$sheet->getName()] = $rows;
            }
        } finally {
            $reader->close();
        }

        return $sheets;
    }
}
