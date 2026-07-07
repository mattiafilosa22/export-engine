<?php

namespace Tests\Feature\Ingestion;

use App\Models\Player;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListPlayersTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_lists_the_version_players_with_their_email(): void
    {
        $version = Version::factory()->create();
        $mine = Player::factory()->resolvable($version, 'a@example.com')->create();
        // A player in another version must not leak.
        Player::factory()->resolvable(Version::factory()->create(), 'b@example.com')->create();

        $response = $this->getJson("/api/v1/versions/{$version->uuid}/players");

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id)
            ->assertJsonPath('data.0.email', 'a@example.com');
    }

    public function test_it_returns_404_for_an_unknown_version(): void
    {
        $this->getJson('/api/v1/versions/' . \Illuminate\Support\Str::uuid() . '/players')
            ->assertStatus(404);
    }
}
