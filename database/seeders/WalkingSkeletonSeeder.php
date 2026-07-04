<?php

namespace Database\Seeders;

use App\Models\Event;
use App\Models\Player;
use App\Models\User;
use App\Models\Version;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Minimal seed for the walking skeleton: one active version + players + ~3,000
 * events. Chunked (bulk) inserts to avoid loading models in memory.
 * Players are created before events so the `events.player_id` FK is satisfied.
 * Prints the version uuid, to use in the export POST.
 */
class WalkingSkeletonSeeder extends Seeder
{
    private const TOTAL_PLAYERS = 500;
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

        $playerIds = $this->seedPlayers($version);
        $this->seedEvents($version, $playerIds);

        $this->command->info("Version uuid: {$version->uuid}");
        $this->command->info('Players seeded: ' . count($playerIds));
        $this->command->info('Events seeded: ' . self::TOTAL_EVENTS);
    }

    /**
     * Creates users + players and returns the real player ids.
     *
     * @return array<int, int>
     */
    private function seedPlayers(Version $version): array
    {
        $ids = [];

        for ($i = 1; $i <= self::TOTAL_PLAYERS; $i++) {
            $user = User::factory()->create();
            $player = Player::factory()->create([
                'version_id' => $version->id,
                'user_id' => $user->id,
                'language' => self::LANGUAGES[$i % count(self::LANGUAGES)],
            ]);
            $ids[] = $player->id;
        }

        return $ids;
    }

    /**
     * @param array<int, int> $playerIds
     */
    private function seedEvents(Version $version, array $playerIds): void
    {
        $now = Carbon::now();
        $count = count($playerIds);
        $chunk = [];

        for ($i = 1; $i <= self::TOTAL_EVENTS; $i++) {
            $chunk[] = [
                'version_id' => $version->id,
                'player_id' => $playerIds[$i % $count],
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
