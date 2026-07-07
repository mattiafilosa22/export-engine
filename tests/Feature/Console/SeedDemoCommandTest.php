<?php

namespace Tests\Feature\Console;

use App\Models\Answer;
use App\Models\Event;
use App\Models\Player;
use App\Models\Reward;
use App\Models\Transaction;
use App\Models\Version;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SeedDemoCommandTest extends TestCase
{
    use RefreshDatabase;

    // Transactions are generated for 1-in-10 players (index % 10 === 0), only if
    // that player happens to roll a game_completed event (~30% per event). With
    // only 1 eligible player (PLAYERS=10) the "at least one transaction" assertion
    // was flaky (~17% chance of 0). 100 players => 10 independent eligible players,
    // making a zero-transaction run astronomically unlikely.
    private const PLAYERS = 100;
    private const EVENTS = 500;

    public function test_it_seeds_the_expected_row_counts(): void
    {
        $version = $this->seedDemo();

        $this->assertSame(self::PLAYERS, Player::where('version_id', $version->id)->count());
        $this->assertSame(self::PLAYERS, DB::table('users')->count());
        $this->assertSame(self::EVENTS, Event::where('version_id', $version->id)->count());
        $this->assertSame(3, DB::table('questions')->where('version_id', $version->id)->count());
        $this->assertSame(3, DB::table('answer_options')->where('version_id', $version->id)->count());
    }

    public function test_total_score_equals_sum_of_game_completed_scores(): void
    {
        $version = $this->seedDemo();

        foreach (Player::where('version_id', $version->id)->get() as $player) {
            $expected = (int) Event::where('player_id', $player->id)
                ->where('type', Event::TYPE_GAME_COMPLETED)
                ->sum('payload_score');

            $this->assertSame(
                $expected,
                (int) $player->total_score,
                "total_score mismatch for player {$player->id}"
            );
        }
    }

    public function test_it_is_idempotent_per_version(): void
    {
        $version = $this->seedDemo();

        Artisan::call('gamindo:seed-demo', [
            '--version-id' => $version->id,
            '--players' => self::PLAYERS,
            '--events' => self::EVENTS,
        ]);

        $this->assertSame(1, Version::count());
        $this->assertSame(self::PLAYERS, DB::table('users')->count());
        $this->assertSame(self::PLAYERS, Player::where('version_id', $version->id)->count());
        $this->assertSame(self::EVENTS, Event::where('version_id', $version->id)->count());
    }

    public function test_with_facts_populates_and_links_answers_transactions_and_rewards(): void
    {
        $version = $this->seedDemo();

        $this->assertGreaterThan(0, Answer::where('version_id', $version->id)->count());
        $this->assertGreaterThan(0, Reward::where('version_id', $version->id)->count());
        $this->assertGreaterThan(0, Transaction::where('version_id', $version->id)->count());

        // Every fact points at a real event of its player.
        $this->assertSame(
            0,
            Answer::where('version_id', $version->id)->whereNull('event_id')->count()
        );
        $this->assertSame(0, $this->orphanFacts('answers'));
        $this->assertSame(0, $this->orphanFacts('transactions'));
        $this->assertSame(0, $this->orphanFacts('rewards'));

        // One answer per player at most (single_choice UNIQUE holds).
        $this->assertLessThanOrEqual(
            self::PLAYERS,
            Answer::where('version_id', $version->id)->count()
        );
    }

    public function test_without_facts_only_players_and_events_are_generated(): void
    {
        Artisan::call('gamindo:seed-demo', [
            '--players' => self::PLAYERS,
            '--events' => self::EVENTS,
            '--with-facts' => 0,
        ]);
        $version = Version::firstOrFail();

        $this->assertSame(self::EVENTS, Event::where('version_id', $version->id)->count());
        $this->assertSame(0, Answer::where('version_id', $version->id)->count());
        $this->assertSame(0, Transaction::where('version_id', $version->id)->count());
        $this->assertSame(0, Reward::where('version_id', $version->id)->count());
    }

    public function test_events_reference_existing_players_of_the_same_version(): void
    {
        $version = $this->seedDemo();

        $orphans = DB::table('events as e')
            ->leftJoin('players as p', 'p.id', '=', 'e.player_id')
            ->where('e.version_id', $version->id)
            ->where(function ($query) {
                $query->whereNull('p.id')->orWhereColumn('p.version_id', '!=', 'e.version_id');
            })
            ->count();

        $this->assertSame(0, $orphans);
    }

    private function seedDemo(): Version
    {
        $exit = Artisan::call('gamindo:seed-demo', [
            '--players' => self::PLAYERS,
            '--events' => self::EVENTS,
        ]);

        $this->assertSame(0, $exit);

        return Version::firstOrFail();
    }

    private function orphanFacts(string $table): int
    {
        return DB::table($table . ' as f')
            ->leftJoin('events as e', 'e.id', '=', 'f.event_id')
            ->whereNull('e.id')
            ->count();
    }
}
