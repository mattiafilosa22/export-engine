<?php

namespace Tests\Feature\Export;

use App\Models\Export;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\Support\XlsxReader;
use Tests\TestCase;

/**
 * Shared scaffolding for the export feature tests: async pipeline drain and
 * workbook reading/assertion helpers. Domain records come from the factories
 * (Player::factory()->resolvable(), Event::factory()->forPlayer()).
 */
abstract class ExportTestCase extends TestCase
{
    use RefreshDatabase;

    /**
     * Runs the queued jobs once, draining the database queue.
     */
    protected function work(): void
    {
        Artisan::call('queue:work', ['--once' => true, '--stop-when-empty' => true]);
    }

    /**
     * Reads the generated workbook (from the faked local disk) into name => rows.
     *
     * @return array<string, array<int, array<int, mixed>>>
     */
    protected function readSheets(Export $export): array
    {
        return XlsxReader::read(Storage::disk('local')->path($export->file_path));
    }

    /**
     * First data row (past the header) whose leading cells match $leading.
     *
     * @param array<int, array<int, mixed>> $sheet
     * @param array<int, mixed> $leading
     * @return array<int, mixed>|null
     */
    protected function firstDataRow(array $sheet, array $leading): ?array
    {
        return $this->matchRow(array_slice($sheet, 1), $leading);
    }

    /**
     * First row (no header assumed) whose leading cells match $leading.
     *
     * @param array<int, array<int, mixed>> $rows
     * @param array<int, mixed> $leading
     * @return array<int, mixed>|null
     */
    protected function matchRow(array $rows, array $leading): ?array
    {
        foreach ($rows as $row) {
            if (array_slice($row, 0, count($leading)) === $leading) {
                return $row;
            }
        }

        return null;
    }
}
