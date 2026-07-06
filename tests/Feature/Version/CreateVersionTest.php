<?php

namespace Tests\Feature\Version;

use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateVersionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_version_and_returns_201_with_a_server_generated_uuid(): void
    {
        $response = $this->postJson('/api/v1/versions', [
            'name' => 'Summer Campaign 2026',
            'client_name' => 'Acme Inc.',
            'status' => Version::STATUS_ACTIVE,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Summer Campaign 2026')
            ->assertJsonPath('data.status', Version::STATUS_ACTIVE);

        $uuid = $response->json('data.uuid');
        $this->assertNotEmpty($uuid);
        $this->assertDatabaseHas('versions', ['uuid' => $uuid, 'name' => 'Summer Campaign 2026']);
    }

    public function test_it_defaults_the_status_to_draft(): void
    {
        $response = $this->postJson('/api/v1/versions', ['name' => 'Minimal']);

        $response->assertStatus(201)->assertJsonPath('data.status', Version::STATUS_DRAFT);
    }

    public function test_it_returns_422_when_the_name_is_missing(): void
    {
        $this->postJson('/api/v1/versions', ['client_name' => 'Acme'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }
}
