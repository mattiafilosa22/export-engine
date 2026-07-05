<?php

namespace Tests\Feature\Ingestion;

use App\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ShowImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_the_import_state_and_counters(): void
    {
        $import = Import::factory()->completed()->create([
            'total_rows' => 100,
            'processed_rows' => 100,
            'inserted' => 98,
            'duplicates' => 1,
            'failed' => 1,
        ]);

        $response = $this->getJson("/api/v1/imports/{$import->uuid}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $import->uuid)
            ->assertJsonPath('data.status', Import::STATUS_COMPLETED)
            ->assertJsonPath('data.inserted', 98)
            ->assertJsonPath('data.duplicates', 1)
            ->assertJsonPath('data.failed', 1);
        $this->assertArrayNotHasKey('payload', $response->json('data'));
    }

    public function test_it_returns_404_for_an_unknown_import(): void
    {
        $response = $this->getJson('/api/v1/imports/' . Str::uuid());

        $response->assertStatus(404);
    }
}
