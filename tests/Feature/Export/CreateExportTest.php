<?php

namespace Tests\Feature\Export;

use App\Jobs\GenerateExportJob;
use App\Models\Export;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class CreateExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_accepts_the_request_and_returns_202_with_a_pending_export(): void
    {
        Queue::fake();
        $version = Version::factory()->create();

        $response = $this->postJson("/api/v1/versions/{$version->uuid}/exports", [
            'format' => 'xlsx',
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('data.status', Export::STATUS_PENDING)
            ->assertJsonPath('data.format', Export::FORMAT_XLSX);
        $this->assertArrayNotHasKey('download_url', $response->json('data'));

        $this->assertDatabaseHas('exports', [
            'version_id' => $version->id,
            'status' => Export::STATUS_PENDING,
        ]);

        Queue::assertPushed(GenerateExportJob::class);
    }

    public function test_the_public_id_is_the_uuid_and_hides_the_numeric_id(): void
    {
        Queue::fake();
        $version = Version::factory()->create();

        $response = $this->postJson("/api/v1/versions/{$version->uuid}/exports", []);

        $export = Export::firstOrFail();
        $response->assertJsonPath('data.id', $export->uuid);
    }

    public function test_it_rejects_an_unsupported_format(): void
    {
        Queue::fake();
        $version = Version::factory()->create();

        $response = $this->postJson("/api/v1/versions/{$version->uuid}/exports", [
            'format' => 'csv',
        ]);

        $response->assertStatus(422);
        Queue::assertNothingPushed();
    }

    public function test_it_returns_404_for_an_unknown_version(): void
    {
        $response = $this->postJson('/api/v1/versions/' . Str::uuid() . '/exports', []);

        $response->assertStatus(404);
    }
}
