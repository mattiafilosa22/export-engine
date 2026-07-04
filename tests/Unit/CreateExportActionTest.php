<?php

namespace Tests\Unit;

use App\Actions\Export\CreateExportAction;
use App\Jobs\GenerateExportJob;
use App\Models\Export;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CreateExportActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_pending_export(): void
    {
        Queue::fake();
        $version = Version::factory()->create();

        $export = (new CreateExportAction())->execute($version, ['foo' => 'bar']);

        $this->assertSame(Export::STATUS_PENDING, $export->status);
        $this->assertSame(Export::FORMAT_XLSX, $export->format);
        $this->assertSame($version->id, $export->version_id);
        $this->assertSame(['foo' => 'bar'], $export->params);
        $this->assertDatabaseHas('exports', [
            'uuid' => $export->uuid,
            'version_id' => $version->id,
            'status' => Export::STATUS_PENDING,
        ]);
    }

    public function test_it_queues_the_generation_job_with_the_export_uuid(): void
    {
        Queue::fake();
        $version = Version::factory()->create();

        $export = (new CreateExportAction())->execute($version, []);

        Queue::assertPushed(
            GenerateExportJob::class,
            function (GenerateExportJob $job) use ($export): bool {
                return $job->exportUuid() === $export->uuid;
            }
        );
    }
}
