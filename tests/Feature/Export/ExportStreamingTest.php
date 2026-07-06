<?php

namespace Tests\Feature\Export;

use App\Jobs\GenerateExportJob;
use App\Models\Event;
use App\Models\Export;
use App\Models\Player;
use App\Models\Version;
use Illuminate\Support\Facades\Storage;

class ExportStreamingTest extends ExportTestCase
{
    public function test_a_completed_export_sets_progress_and_processed_rows(): void
    {
        Storage::fake('local');
        $version = Version::factory()->create();
        Event::factory()->count(3)->create(['version_id' => $version->id]);
        $export = Export::factory()->create([
            'version_id' => $version->id,
            'status' => Export::STATUS_PENDING,
            'params' => [],
        ]);

        $this->app->call([new GenerateExportJob($export->uuid), 'handle']);

        $export->refresh();
        $this->assertSame(Export::STATUS_COMPLETED, $export->status);
        $this->assertSame(3, $export->total_rows);
        $this->assertSame(3, $export->processed_rows);
        $this->assertSame(100, $export->progress);
    }

    public function test_generation_memory_does_not_scale_with_row_count(): void
    {
        Storage::fake('local');
        $version = Version::factory()->create();
        $player = Player::factory()->create(['version_id' => $version->id]);
        Event::factory()->bulkInsert((int) $version->id, (int) $player->id, 20000);

        $export = Export::factory()->create([
            'version_id' => $version->id,
            'status' => Export::STATUS_PENDING,
            'params' => [],
        ]);

        $before = memory_get_peak_usage(true);
        $this->app->call([new GenerateExportJob($export->uuid), 'handle']);
        $delta = memory_get_peak_usage(true) - $before;

        $export->refresh();
        $this->assertSame(Export::STATUS_COMPLETED, $export->status);
        $this->assertSame(20000, $export->total_rows);
        // Streaming: 20k rows must not add meaningful peak memory (coarse guard;
        // the definitive 500k proof is `php artisan gamindo:export-benchmark`).
        $this->assertLessThan(64 * 1048576, $delta, 'Export memory must not scale with row count.');
    }
}
