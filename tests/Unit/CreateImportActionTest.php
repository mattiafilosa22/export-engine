<?php

namespace Tests\Unit;

use App\Actions\Ingestion\CreateImportAction;
use App\Jobs\IngestEventsJob;
use App\Jobs\IngestPlayersJob;
use App\Models\Import;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Tests\TestCase;

class CreateImportActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_pending_players_import_and_queues_the_players_job(): void
    {
        Queue::fake();
        $version = Version::factory()->create();
        $rows = [['email' => 'a@example.com'], ['email' => 'b@example.com']];

        $import = (new CreateImportAction())->execute($version, Import::TYPE_PLAYERS, $rows);

        $this->assertSame(Import::STATUS_PENDING, $import->status);
        $this->assertSame(Import::TYPE_PLAYERS, $import->type);
        $this->assertSame($version->id, $import->version_id);
        $this->assertSame(2, $import->total_rows);
        $this->assertSame($rows, $import->payload);

        Queue::assertPushed(
            IngestPlayersJob::class,
            function (IngestPlayersJob $job) use ($import): bool {
                return $job->importUuid() === $import->uuid;
            }
        );
        Queue::assertNotPushed(IngestEventsJob::class);
    }

    public function test_it_queues_the_events_job_for_an_events_import(): void
    {
        Queue::fake();
        $version = Version::factory()->create();

        $import = (new CreateImportAction())->execute($version, Import::TYPE_EVENTS, [['dedup_key' => 'x']]);

        Queue::assertPushed(
            IngestEventsJob::class,
            function (IngestEventsJob $job) use ($import): bool {
                return $job->importUuid() === $import->uuid;
            }
        );
        Queue::assertNotPushed(IngestPlayersJob::class);
    }

    public function test_it_rejects_an_unsupported_type(): void
    {
        Queue::fake();
        $version = Version::factory()->create();

        $this->expectException(InvalidArgumentException::class);

        (new CreateImportAction())->execute($version, 'invalid', []);
    }
}
