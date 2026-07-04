<?php

namespace Tests\Feature\Export;

use App\Models\Export;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ShowExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_the_export_status(): void
    {
        $export = Export::factory()->create(['status' => Export::STATUS_PENDING]);

        $response = $this->getJson("/api/v1/exports/{$export->uuid}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $export->uuid)
            ->assertJsonPath('data.status', Export::STATUS_PENDING);
        $this->assertArrayNotHasKey('download_url', $response->json('data'));
    }

    public function test_a_completed_export_exposes_a_download_url(): void
    {
        $export = Export::factory()->completed()->create();

        $response = $this->getJson("/api/v1/exports/{$export->uuid}");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', Export::STATUS_COMPLETED);
        $this->assertStringContainsString(
            "/api/v1/exports/{$export->uuid}/download",
            $response->json('data.download_url')
        );
    }

    public function test_it_returns_404_for_an_unknown_export(): void
    {
        $response = $this->getJson('/api/v1/exports/' . Str::uuid());

        $response->assertStatus(404);
    }
}
