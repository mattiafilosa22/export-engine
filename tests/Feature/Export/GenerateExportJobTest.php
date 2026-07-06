<?php

namespace Tests\Feature\Export;

use App\Jobs\GenerateExportJob;
use App\Models\Event;
use App\Models\Export;
use App\Models\Version;
use App\Support\Export\XlsxExportWriter;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class GenerateExportJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_handle_marks_the_export_failed_and_rethrows_on_generation_error(): void
    {
        Storage::fake('local');
        $version = Version::factory()->create();
        Event::factory()->count(3)->create(['version_id' => $version->id]);
        $export = Export::factory()->create([
            'version_id' => $version->id,
            'status' => Export::STATUS_PENDING,
        ]);

        // Dummy writer that fails on write: exercises the catch branch of handle().
        $this->app->instance(XlsxExportWriter::class, new class extends XlsxExportWriter {
            public function write(string $absolutePath, iterable $sheets): int
            {
                throw new RuntimeException('writer boom');
            }
        });

        $job = new GenerateExportJob($export->uuid);

        $rethrown = null;
        try {
            $this->app->call([$job, 'handle']);
            $this->fail('The generation exception should have been re-thrown.');
        } catch (RuntimeException $e) {
            $rethrown = $e;
        }

        $this->assertNotNull($rethrown, 'Exception must bubble up so the queue records failed_jobs.');
        $this->assertSame('writer boom', $rethrown->getMessage());

        $export->refresh();
        $this->assertSame(Export::STATUS_FAILED, $export->status);
        $this->assertSame('writer boom', $export->error_message);
        $this->assertNull($export->file_path);
    }

    public function test_failed_hook_marks_a_processing_export_as_failed(): void
    {
        $export = Export::factory()->processing()->create();

        (new GenerateExportJob($export->uuid))->failed(new Exception('job exhausted retries'));

        $export->refresh();
        $this->assertSame(Export::STATUS_FAILED, $export->status);
        $this->assertSame('job exhausted retries', $export->error_message);
    }

    public function test_failed_hook_does_not_overwrite_a_terminal_export(): void
    {
        $export = Export::factory()->completed()->create();

        (new GenerateExportJob($export->uuid))->failed(new Exception('late failure'));

        $export->refresh();
        $this->assertSame(Export::STATUS_COMPLETED, $export->status);
        $this->assertNull($export->error_message);
    }
}
