<?php

namespace Tests\Unit;

use App\Models\Export;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_auto_generates_a_uuid_on_create(): void
    {
        $version = Version::factory()->create();

        $export = Export::create([
            'version_id' => $version->id,
            'params' => [],
        ]);

        $this->assertNotEmpty($export->uuid);
    }

    public function test_mark_processing_sets_status_started_at_and_increments_attempts(): void
    {
        $export = Export::factory()->create(['status' => Export::STATUS_PENDING]);

        $export->markProcessing();

        $this->assertSame(Export::STATUS_PROCESSING, $export->status);
        $this->assertNotNull($export->started_at);
        $this->assertSame(1, $export->attempts);
    }

    public function test_mark_completed_sets_file_metadata_and_rows(): void
    {
        $export = Export::factory()->create(['status' => Export::STATUS_PROCESSING]);

        $export->markCompleted(42, 'exports/file.xlsx', 2048);

        $this->assertSame(Export::STATUS_COMPLETED, $export->status);
        $this->assertTrue($export->isCompleted());
        $this->assertSame(42, $export->total_rows);
        $this->assertSame(42, $export->processed_rows);
        $this->assertSame('exports/file.xlsx', $export->file_path);
        $this->assertSame(2048, $export->file_size);
        $this->assertNotNull($export->completed_at);
    }

    public function test_mark_failed_sets_status_and_error_message(): void
    {
        $export = Export::factory()->create(['status' => Export::STATUS_PROCESSING]);

        $export->markFailed('boom');

        $this->assertSame(Export::STATUS_FAILED, $export->status);
        $this->assertSame('boom', $export->error_message);
        $this->assertFalse($export->isCompleted());
    }

    public function test_for_version_scope_filters_by_version_id(): void
    {
        $version = Version::factory()->create();
        Export::factory()->create(['version_id' => $version->id]);
        Export::factory()->create();

        $this->assertCount(1, Export::forVersion($version->id)->get());
    }
}
