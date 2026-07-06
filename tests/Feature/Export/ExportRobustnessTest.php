<?php

namespace Tests\Feature\Export;

use App\Jobs\GenerateExportJob;
use App\Models\Event;
use App\Models\Export;
use App\Models\Player;
use App\Models\Version;
use App\Support\Export\ExportState;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Mockery;

class ExportRobustnessTest extends ExportTestCase
{
    public function test_the_lock_prevents_a_second_worker_from_processing_the_same_export(): void
    {
        Storage::fake('local');
        $version = Version::factory()->create();
        Event::factory()->count(2)->create(['version_id' => $version->id]);
        $export = Export::factory()->create([
            'version_id' => $version->id,
            'status' => Export::STATUS_PENDING,
            'params' => [],
        ]);

        // Simulate another worker already holding the export lock.
        $lock = Cache::lock($this->app->make(ExportState::class)->lockKey($export->uuid), 120);
        $this->assertTrue($lock->get());

        try {
            $this->app->call([new GenerateExportJob($export->uuid), 'handle']);

            $export->refresh();
            $this->assertSame(Export::STATUS_PENDING, $export->status);
            $this->assertNull($export->file_path);
        } finally {
            $lock->release();
        }
    }

    public function test_a_lock_miss_requeues_the_job_instead_of_dropping_it(): void
    {
        Storage::fake('local');
        $version = Version::factory()->create();
        $export = Export::factory()->create([
            'version_id' => $version->id,
            'status' => Export::STATUS_PENDING,
            'params' => [],
        ]);

        // A pre-existing (possibly stale) lock is held.
        $lock = Cache::lock($this->app->make(ExportState::class)->lockKey($export->uuid), 120);
        $this->assertTrue($lock->get());

        try {
            $job = Mockery::mock(GenerateExportJob::class . '[release]', [$export->uuid]);
            $job->shouldAllowMockingProtectedMethods();
            $job->shouldReceive('release')->once(); // re-queued, not silently dropped

            $this->app->call([$job, 'handle']);

            // Export is left untouched for the retry, not abandoned as "done".
            $this->assertSame(Export::STATUS_PENDING, $export->refresh()->status);
        } finally {
            $lock->release();
        }
    }

    public function test_cancellation_is_honored_even_without_an_in_stream_checkpoint(): void
    {
        Storage::fake('local');
        $version = Version::factory()->create();
        // Fewer than the progress interval: no in-stream checkpoint fires.
        Event::factory()->count(3)->create(['version_id' => $version->id]);
        $export = Export::factory()->create([
            'version_id' => $version->id,
            'status' => Export::STATUS_PENDING,
            'params' => [],
        ]);

        $this->app->make(ExportState::class)->requestCancel($export->uuid);
        $this->app->call([new GenerateExportJob($export->uuid), 'handle']);

        $export->refresh();
        $this->assertSame(Export::STATUS_CANCELLED, $export->status);
        $this->assertFalse(Storage::disk('local')->exists("exports/{$export->uuid}.xlsx"));
    }

    public function test_cancelling_a_pending_export_always_raises_the_flag_too(): void
    {
        $export = Export::factory()->create(['status' => Export::STATUS_PENDING, 'params' => []]);

        $this->postJson("/api/v1/exports/{$export->uuid}/cancel")
            ->assertStatus(202)
            ->assertJsonPath('data.status', Export::STATUS_CANCELLED);

        // The flag is set even for the pending path, so a worker that raced into
        // `processing` still stops.
        $this->assertTrue($this->app->make(ExportState::class)->isCancelRequested($export->uuid));
    }

    public function test_cancelling_a_pending_export_marks_it_cancelled(): void
    {
        $export = Export::factory()->create(['status' => Export::STATUS_PENDING, 'params' => []]);

        $this->postJson("/api/v1/exports/{$export->uuid}/cancel")
            ->assertStatus(202)
            ->assertJsonPath('data.status', Export::STATUS_CANCELLED);

        $this->assertSame(Export::STATUS_CANCELLED, $export->refresh()->status);
    }

    public function test_cancelling_a_processing_export_stops_the_stream_and_deletes_the_file(): void
    {
        Storage::fake('local');
        $version = Version::factory()->create();
        $player = Player::factory()->create(['version_id' => $version->id]);
        // > 1000 rows so the first progress callback (which checks cancellation) fires.
        Event::factory()->bulkInsert((int) $version->id, (int) $player->id, 1500);
        $export = Export::factory()->create([
            'version_id' => $version->id,
            'status' => Export::STATUS_PENDING,
            'params' => [],
        ]);

        // Request cancellation before the worker reaches the first checkpoint.
        $this->app->make(ExportState::class)->requestCancel($export->uuid);

        $this->app->call([new GenerateExportJob($export->uuid), 'handle']);

        $export->refresh();
        $this->assertSame(Export::STATUS_CANCELLED, $export->status);
        $this->assertFalse(Storage::disk('local')->exists("exports/{$export->uuid}.xlsx"));
    }

    public function test_cancelling_a_completed_export_returns_409(): void
    {
        $export = Export::factory()->completed()->create();

        $this->postJson("/api/v1/exports/{$export->uuid}/cancel")->assertStatus(409);
    }

    public function test_show_overlays_the_live_progress_from_the_store(): void
    {
        $export = Export::factory()->processing()->create();
        $this->app->make(ExportState::class)->setProgress($export->uuid, 55);

        $this->getJson("/api/v1/exports/{$export->uuid}")
            ->assertStatus(200)
            ->assertJsonPath('data.progress', 55);
    }
}
