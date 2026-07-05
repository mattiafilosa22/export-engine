<?php

namespace Database\Factories;

use App\Models\Player;
use App\Models\User;
use App\Models\Version;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Player>
 */
class PlayerFactory extends Factory
{
    protected $model = Player::class;

    public function definition(): array
    {
        return [
            'version_id' => Version::factory(),
            'user_id' => User::factory(),
            'registered_at' => now()->subDays($this->faker->numberBetween(0, 7)),
            'total_score' => $this->faker->numberBetween(0, 5000),
            'language' => $this->faker->randomElement(['it', 'en', 'es', 'fr']),
        ];
    }

    /**
     * A player resolvable by email: bound to the version and to a user with
     * the given email (the grain PlayerResolver joins on).
     */
    public function resolvable(Version $version, string $email): self
    {
        return $this->for($version)->for(User::factory()->state(['email' => $email]));
    }
}
