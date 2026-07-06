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

    public function test_existing_ids_keeps_only_player_ids_in_the_version(): void
    {
        $version = Version::factory()->create();
        $other = Version::factory()->create();
        $mine = Player::factory()->create(['version_id' => $version->id]);
        $theirs = Player::factory()->create(['version_id' => $other->id]);

        $valid = (new PlayerResolver())->existingIds($version->id, [$mine->id, $theirs->id, 999999]);

        $this->assertArrayHasKey((int) $mine->id, $valid);
        $this->assertArrayNotHasKey((int) $theirs->id, $valid);
        $this->assertArrayNotHasKey(999999, $valid);
    }

    public function test_existing_ids_returns_an_empty_set_for_no_ids(): void
    {
        $version = Version::factory()->create();

        $this->assertSame([], (new PlayerResolver())->existingIds($version->id, []));
    }
}
