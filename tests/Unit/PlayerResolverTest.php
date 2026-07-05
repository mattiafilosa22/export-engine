<?php

namespace Tests\Unit;

use App\Models\Player;
use App\Models\User;
use App\Models\Version;
use App\Support\Ingestion\PlayerResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlayerResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_maps_existing_emails_to_their_player_id_in_the_version(): void
    {
        $version = Version::factory()->create();
        $user = User::factory()->create(['email' => 'mario@example.com']);
        $player = Player::factory()->create(['version_id' => $version->id, 'user_id' => $user->id]);

        $map = (new PlayerResolver())->resolve($version->id, ['mario@example.com']);

        $this->assertSame(['mario@example.com' => $player->id], $map);
    }

    public function test_it_omits_emails_without_a_player_in_the_version(): void
    {
        $version = Version::factory()->create();
        $other = Version::factory()->create();
        $user = User::factory()->create(['email' => 'mario@example.com']);
        // Player exists, but only in another version.
        Player::factory()->create(['version_id' => $other->id, 'user_id' => $user->id]);

        $map = (new PlayerResolver())->resolve($version->id, ['mario@example.com', 'ghost@example.com']);

        $this->assertArrayNotHasKey('mario@example.com', $map);
        $this->assertArrayNotHasKey('ghost@example.com', $map);
        $this->assertSame([], $map);
    }

    public function test_it_returns_an_empty_map_for_no_emails(): void
    {
        $version = Version::factory()->create();

        $this->assertSame([], (new PlayerResolver())->resolve($version->id, []));
    }
}
