<?php

namespace Database\Seeders;

use App\Models\Event;
use App\Models\Version;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Minimal seed for the walking skeleton: one active version + ~3,000 events.
 * Chunked (bulk) inserts to avoid loading 3,000 models in memory.
 * Prints the version uuid, to use in the export POST.
 */
class WalkingSkeletonSeeder extends Seeder
{
    private const TOTAL_EVENTS = 3000;
    private const CHUNK_SIZE = 500;

    private const LANGUAGES = ['it', 'en', 'es', 'fr', 'de'];
    private const UTM_SOURCES = ['linkedin', 'facebook', 'google', 'newsletter'];
    private const TYPES = [
        Event::TYPE_ANSWER_SUBMITTED,
        Event::TYPE_GAME_COMPLETED,
        Event::TYPE_REWARD_GRANTED,
    ];

    public function run(): void
    {
        $version = Version::factory()->create([
            'name' => 'Walking Skeleton — Demo',
            'client_name' => 'Gamindo',
            'status' => Version::STATUS_ACTIVE,
        ]);

        $this->seedEvents($version);

        $this->command->info("Version uuid: {$version->uuid}");
        $this->command->info('Events seeded: ' . self::TOTAL_EVENTS);
    }

    private function seedEvents(Version $version): void
    {
        $now = Carbon::now();
        $chunk = [];

        for ($i = 1; $i <= self::TOTAL_EVENTS; $i++) {
            $chunk[] = [
                'version_id' => $version->id,
                'player_id' => ($i % 500) + 1,
                'type' => self::TYPES[$i % count(self::TYPES)],
                'occurred_at' => $now->copy()->subMinutes($i)->toDateTimeString(),
                'payload' => json_encode([
                    'language' => self::LANGUAGES[$i % count(self::LANGUAGES)],
                    'utm_source' => self::UTM_SOURCES[$i % count(self::UTM_SOURCES)],
                    'score' => ($i * 7) % 1000,
                ]),
                'created_at' => $now->toDateTimeString(),
            ];

            if (count($chunk) === self::CHUNK_SIZE) {
                Event::insert($chunk);
                $chunk = [];
            }
        }

        if ($chunk !== []) {
            Event::insert($chunk);
        }
    }
}
