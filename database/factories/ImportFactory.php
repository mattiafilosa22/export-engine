<?php

namespace Database\Factories;

use App\Models\Import;
use App\Models\Version;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Import>
 */
class ImportFactory extends Factory
{
    protected $model = Import::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'version_id' => Version::factory(),
            'type' => Import::TYPE_PLAYERS,
            'status' => Import::STATUS_PENDING,
            'total_rows' => 0,
            'payload' => [],
            'created_at' => now(),
        ];
    }

    public function events(): self
    {
        return $this->state(function (array $attributes): array {
            return ['type' => Import::TYPE_EVENTS];
        });
    }

    public function processing(): self
    {
        return $this->state(function (array $attributes): array {
            return [
                'status' => Import::STATUS_PROCESSING,
                'started_at' => now(),
            ];
        });
    }

    public function completed(): self
    {
        return $this->state(function (array $attributes): array {
            return [
                'status' => Import::STATUS_COMPLETED,
                'started_at' => now(),
                'completed_at' => now(),
            ];
        });
    }
}
