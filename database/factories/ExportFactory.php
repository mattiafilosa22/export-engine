<?php

namespace Database\Factories;

use App\Models\Export;
use App\Models\Version;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Export>
 */
class ExportFactory extends Factory
{
    protected $model = Export::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'version_id' => Version::factory(),
            'params' => [],
            'format' => Export::FORMAT_XLSX,
            'status' => Export::STATUS_PENDING,
            'created_at' => now(),
        ];
    }

    public function completed(): self
    {
        return $this->state(function (array $attributes): array {
            $uuid = $attributes['uuid'] ?? (string) Str::uuid();

            return [
                'status' => Export::STATUS_COMPLETED,
                'total_rows' => 10,
                'processed_rows' => 10,
                'file_path' => 'exports/' . $uuid . '.xlsx',
                'file_size' => 1024,
                'started_at' => now(),
                'completed_at' => now(),
            ];
        });
    }

    public function processing(): self
    {
        return $this->state(function (array $attributes): array {
            return [
                'status' => Export::STATUS_PROCESSING,
                'started_at' => now(),
            ];
        });
    }
}
