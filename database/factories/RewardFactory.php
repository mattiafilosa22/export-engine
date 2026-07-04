<?php

namespace Database\Factories;

use App\Models\Player;
use App\Models\Reward;
use App\Models\Version;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Reward>
 */
class RewardFactory extends Factory
{
    protected $model = Reward::class;

    public function definition(): array
    {
        $version = Version::factory()->create();
        $player = Player::factory()->create(['version_id' => $version->id]);

        return [
            'version_id' => $version->id,
            'player_id' => $player->id,
            'event_id' => null,
            'reward_type' => $this->faker->randomElement(['coupon', 'badge', 'physical']),
            'reward_code' => strtoupper($this->faker->bothify('RW-####')),
            'status' => Reward::STATUS_GRANTED,
            'granted_at' => now()->subMinutes($this->faker->numberBetween(0, 10000)),
            'redeemed_at' => null,
        ];
    }
}
