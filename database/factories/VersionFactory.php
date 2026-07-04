<?php

namespace Database\Factories;

use App\Models\Version;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Version>
 */
class VersionFactory extends Factory
{
    protected $model = Version::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'name' => $this->faker->words(3, true),
            'client_name' => $this->faker->company(),
            'status' => Version::STATUS_ACTIVE,
            'starts_at' => now()->subDays(7),
            'ends_at' => now()->addDays(7),
            'config' => null,
        ];
    }

    public function draft(): self
    {
        return $this->state(function (array $attributes): array {
            return ['status' => Version::STATUS_DRAFT];
        });
    }
}
