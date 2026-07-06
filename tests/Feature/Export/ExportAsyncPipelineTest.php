<?php

namespace Tests\Feature\Export;

use App\Jobs\GenerateExportJob;
use App\Models\Event;
use App\Models\Export;
use App\Models\Version;
use Illuminate\Support\Facades\Storage;

/**
 * End-to-end test of the pipeline on the real `database` queue (not sync/fake):
 * the job is serialized into the `jobs` table, a worker processes it and
 * produces the XLSX file, bringing the export to `completed`.
 *
 * Note: the job is queued directly here. The Action's `afterCommit` dispatch,
 * under RefreshDatabase, would stay pending (the test transaction never
 * commits): that path is covered separately via Queue::fake in CreateExportTest.
 */
class ExportAsyncPipelineTest extends ExportTestCase
{
    public function test_database_queue_worker_generates_the_xlsx_file(): void
    {
        config(['queue.default' => 'database']);
        Storage::fake('local');

        $version = Version::factory()->create();
        Event::factory()->count(5)->create(['version_id' => $version->id]);
        $export = Export::factory()->create([
            'version_id' => $version->id,
            'status' => Export::STATUS_PENDING,
        ]);

        GenerateExportJob::dispatch($export->uuid);

        // The job lives in the database queue, the export is still pending.
        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseHas('exports', [
            'uuid' => $export->uuid,
            'status' => Export::STATUS_PENDING,
        ]);

        $this->work();

        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseHas('exports', [
            'uuid' => $export->uuid,
            'status' => Export::STATUS_COMPLETED,
        ]);

        $export->refresh();
        $this->assertSame(5, $export->total_rows);
        $this->assertNotNull($export->file_path);
        $this->assertTrue(
            Storage::disk('local')->exists($export->file_path),
            'The export file should exist on disk.'
        );
        $this->assertGreaterThan(0, $export->file_size);
    }
}
