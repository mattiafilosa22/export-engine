<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\Player;
use App\Models\Version;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    protected $model = Event::class;

    public function definition(): array
    {
        $types = [
            Event::TYPE_ANSWER_SUBMITTED,
            Event::TYPE_GAME_COMPLETED,
            Event::TYPE_REWARD_GRANTED,
        ];

        return [
            'version_id' => Version::factory(),
            // Real player in the SAME version as the event: the FK now enforces it.
            // The closure reads version_id after it has resolved to an id (or an
            // explicit override), so player and event never diverge on version.
            'player_id' => function (array $attributes): int {
                return Player::factory()
                    ->create(['version_id' => $attributes['version_id']])
                    ->id;
            },
            'type' => $this->faker->randomElement($types),
            'occurred_at' => now()->subMinutes($this->faker->numberBetween(0, 10000)),
            'payload' => [
                'language' => $this->faker->randomElement(['it', 'en', 'es', 'fr', 'de']),
                'utm_source' => $this->faker->randomElement(['linkedin', 'facebook', 'google']),
                'score' => $this->faker->numberBetween(0, 1000),
            ],
        ];
    }
}
