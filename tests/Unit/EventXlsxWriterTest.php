<?php

namespace Tests\Unit;

use App\Support\Export\EventXlsxWriter;
use PHPUnit\Framework\TestCase;

class EventXlsxWriterTest extends TestCase
{
    public function test_it_writes_a_valid_xlsx_and_returns_the_data_row_count(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx_') . '.xlsx';

        try {
            $rows = [
                [1, 'game_completed', '2026-01-01 10:00:00', 'it', 100],
                [2, 'answer_submitted', '2026-01-01 11:00:00', 'en', null],
            ];

            $written = (new EventXlsxWriter())->write($path, $rows, ['id', 'type', 'occurred_at', 'language', 'score']);

            $this->assertSame(2, $written);
            $this->assertFileExists($path);
            $this->assertGreaterThan(0, filesize($path));
            $this->assertTrue($this->isValidXlsx($path), 'File should contain xl/worksheets/sheet1.xml');
        } finally {
            @unlink($path);
        }
    }

    private function isValidXlsx(string $path): bool
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return false;
        }

        $found = $zip->locateName('xl/worksheets/sheet1.xml') !== false;
        $zip->close();

        return $found;
    }
}
