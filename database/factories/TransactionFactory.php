<?php

namespace Database\Factories;

use App\Models\Player;
use App\Models\Transaction;
use App\Models\Version;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    protected $model = Transaction::class;

    public function definition(): array
    {
        $version = Version::factory()->create();
        $player = Player::factory()->create(['version_id' => $version->id]);

        return [
            'version_id' => $version->id,
            'player_id' => $player->id,
            'event_id' => null,
            'type' => $this->faker->randomElement([
                Transaction::TYPE_PURCHASE,
                Transaction::TYPE_SPEND,
                Transaction::TYPE_REFUND,
            ]),
            'amount' => $this->faker->randomFloat(2, 1, 999),
            'currency' => 'EUR',
            'status' => Transaction::STATUS_COMPLETED,
            'external_ref' => $this->faker->uuid(),
            'occurred_at' => now()->subMinutes($this->faker->numberBetween(0, 10000)),
        ];
    }
}
