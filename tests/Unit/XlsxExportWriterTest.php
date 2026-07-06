<?php

namespace Tests\Unit;

use App\Support\Export\Sheet\InMemorySheet;
use App\Support\Export\XlsxExportWriter;
use PHPUnit\Framework\TestCase;
use Tests\Support\XlsxReader;

class XlsxExportWriterTest extends TestCase
{
    public function test_it_writes_multiple_named_sheets_and_returns_the_total_row_count(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx_') . '.xlsx';

        try {
            $written = (new XlsxExportWriter())->write($path, [
                new InMemorySheet('Summary', ['type', 'count'], [['game_completed', 3], ['answer_submitted', 5]]),
                new InMemorySheet('Detail', ['id', 'language'], [[1, 'it']]),
            ]);

            // 2 + 1 data rows across the two sheets.
            $this->assertSame(3, $written);

            $sheets = XlsxReader::read($path);
            $this->assertSame(['Summary', 'Detail'], array_keys($sheets));
            $this->assertSame(['type', 'count'], $sheets['Summary'][0]);
            $this->assertEquals(['game_completed', 3], $sheets['Summary'][1]);
            $this->assertSame(['id', 'language'], $sheets['Detail'][0]);
            $this->assertEquals([1, 'it'], $sheets['Detail'][1]);
        } finally {
            @unlink($path);
        }
    }

    public function test_it_disambiguates_duplicate_sheet_names(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx_') . '.xlsx';

        try {
            (new XlsxExportWriter())->write($path, [
                new InMemorySheet('Events', ['id'], [[1]]),
                new InMemorySheet('Events', ['id'], [[2]]),
            ]);

            $names = array_keys(XlsxReader::read($path));
            $this->assertSame(['Events', 'Events (2)'], $names);
        } finally {
            @unlink($path);
        }
    }
}
