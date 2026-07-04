<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Models\Question;
use App\Models\Reward;
use App\Models\Transaction;
use App\Models\Version;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Seeds a demo version with massive, coherent data (players + events + facts)
 * using chunked bulk inserts. Self-contained and idempotent per version:
 * re-running against the same version wipes its demo children and rebuilds them.
 */
class SeedDemoCommand extends Command
{
    // Note: `--version` is reserved by Symfony's console (global -V/--version),
    // so the numeric version id is passed via `--version-id`.
    protected $signature = 'gamindo:seed-demo
        {--version-id= : Numeric version id to (re)seed; a demo version is created when omitted}
        {--players=20000 : Number of players to generate}
        {--events=2000000 : Number of events to generate}
        {--with-facts=1 : Also generate answers/transactions/rewards}
        {--no-fk-checks : Disable FOREIGN_KEY_CHECKS around the bulk inserts (perf lever)}';

    protected $description = 'Seed a demo version with players, events and relational facts.';

    private const CHUNK = 2000;
    private const SCORE_MAX = 1000;

    // Event-type distribution (cumulative thresholds over 1..100).
    private const ANSWER_THRESHOLD = 60;
    private const GAME_THRESHOLD = 90;

    private const UTM_SOURCES = ['linkedin', 'facebook', 'google', 'newsletter', 'direct'];

    // Weighted language buckets over an 8-slot cycle: it 50 / en 25 / es 12.5 / fr 12.5.
    private const LANGUAGE_CYCLE = ['it', 'it', 'it', 'it', 'en', 'en', 'es', 'fr'];

    /** @var int */
    private $windowStart = 0;

    /** @var int */
    private $windowSpan = 0;

    public function handle(): int
    {
        $players = (int) $this->option('players');
        $events = (int) $this->option('events');
        $withFacts = (bool) (int) $this->option('with-facts');
        $noFkChecks = (bool) $this->option('no-fk-checks');

        DB::connection()->disableQueryLog();

        $version = $this->resolveVersion();
        $this->initWindow($version);
        $this->cleanupChildren($version);

        [$singleChoiceQuestionId, $answerOptionIds] = $this->ensureDimensions($version);

        $start = microtime(true);
        if ($noFkChecks) {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
        }

        try {
            $userIds = $this->seedUsers($version, $players);
            $plans = $this->buildPlans($version, $players, $events);
            $playerIds = $this->seedPlayers($version, $userIds, $plans);
            $counts = $this->seedEventsAndFacts(
                $version,
                $playerIds,
                $plans,
                $withFacts,
                $singleChoiceQuestionId,
                $answerOptionIds
            );
        } finally {
            // Always restore FK enforcement, even if the bulk load throws.
            if ($noFkChecks) {
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
            }
        }
        $duration = round(microtime(true) - $start, 2);

        $this->report($version, $players, $counts, $answerOptionIds, $duration);

        return self::SUCCESS;
    }

    /**
     * Resolves the target version: reuse an existing id, create it with the
     * requested id when missing, or spin up a fresh demo version.
     */
    private function resolveVersion(): Version
    {
        $option = $this->option('version-id');

        if ($option === null) {
            return Version::create($this->demoVersionAttributes());
        }

        $id = (int) $option;
        // withTrashed: reusing a soft-deleted id must not attempt a duplicate insert.
        $existing = Version::withTrashed()->find($id);
        if ($existing !== null) {
            if ($existing->trashed()) {
                $existing->restore();
            }

            return $existing;
        }

        $version = new Version($this->demoVersionAttributes());
        $version->id = $id;
        $version->save();

        return $version;
    }

    /**
     * @return array<string, mixed>
     */
    private function demoVersionAttributes(): array
    {
        return [
            'name' => 'Demo Campaign',
            'client_name' => 'Gamindo',
            'status' => Version::STATUS_ACTIVE,
            'starts_at' => Carbon::now()->subDays(30),
            'ends_at' => Carbon::now()->addDays(30),
        ];
    }

    /**
     * Events are scattered across [starts_at, min(ends_at, now)].
     */
    private function initWindow(Version $version): void
    {
        $start = $version->starts_at ?? Carbon::now()->subDays(30);
        $end = $version->ends_at ?? Carbon::now();
        $now = Carbon::now();
        if ($end->greaterThan($now)) {
            $end = $now;
        }
        if ($end->lessThanOrEqualTo($start)) {
            $end = $start->copy()->addDay();
        }

        $this->windowStart = $start->getTimestamp();
        $this->windowSpan = $end->getTimestamp() - $this->windowStart;
    }

    /**
     * Removes this version's demo children in reverse-FK order so a re-run
     * never duplicates data. Demo users are matched by their email prefix.
     */
    private function cleanupChildren(Version $version): void
    {
        DB::table('answers')->where('version_id', $version->id)->delete();
        DB::table('transactions')->where('version_id', $version->id)->delete();
        DB::table('rewards')->where('version_id', $version->id)->delete();
        DB::table('events')->where('version_id', $version->id)->delete();
        DB::table('players')->where('version_id', $version->id)->delete();
        DB::table('users')->where('email', 'like', $this->userEmailPrefix($version) . '%')->delete();
        DB::table('answer_options')->where('version_id', $version->id)->delete();
        DB::table('questions')->where('version_id', $version->id)->delete();
    }

    private function userEmailPrefix(Version $version): string
    {
        return 'demo-v' . $version->id . '-';
    }

    /**
     * Creates the fixed question set (one single_choice + one rating + one open)
     * with the single_choice answer options. Returns the single_choice id and answer option ids.
     *
     * @return array{0:int,1:array<int,int>}
     */
    private function ensureDimensions(Version $version): array
    {
        $now = Carbon::now()->toDateTimeString();

        $questionRows = [
            $this->questionRow($version, 'Q1', 'Which value does the brand convey?', Question::TYPE_SINGLE_CHOICE, 1),
            $this->questionRow($version, 'Q2', 'How likely are you to recommend us?', Question::TYPE_RATING, 2),
            $this->questionRow($version, 'Q3', 'Any additional feedback?', Question::TYPE_OPEN, 3),
        ];
        $firstQuestionId = $this->insertChunk('questions', $questionRows);
        $singleChoiceQuestionId = $firstQuestionId;

        $labels = ['Sustainability', 'Family', 'National identity'];
        $answerOptionRows = [];
        foreach ($labels as $i => $label) {
            $answerOptionRows[] = [
                'version_id' => $version->id,
                'question_id' => $singleChoiceQuestionId,
                'code' => chr(65 + $i),
                'label' => $label,
                'position' => $i + 1,
                'is_correct' => $i === 0 ? 1 : 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        $firstAnswerOptionId = $this->insertChunk('answer_options', $answerOptionRows);
        $answerOptionIds = range($firstAnswerOptionId, $firstAnswerOptionId + count($labels) - 1);

        return [$singleChoiceQuestionId, $answerOptionIds];
    }

    /**
     * @return array<string, mixed>
     */
    private function questionRow(Version $version, string $code, string $text, string $type, int $position): array
    {
        $now = Carbon::now()->toDateTimeString();

        return [
            'version_id' => $version->id,
            'code' => $code,
            'text' => $text,
            'type' => $type,
            'position' => $position,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * Bulk-inserts demo users, returning their ids in insertion order.
     *
     * @return array<int, int>
     */
    private function seedUsers(Version $version, int $players): array
    {
        $now = Carbon::now()->toDateTimeString();
        $prefix = $this->userEmailPrefix($version);
        $ids = [];
        $chunk = [];

        for ($i = 0; $i < $players; $i++) {
            $chunk[] = [
                'email' => $prefix . $i . '@gamindo.test',
                'external_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($chunk) === self::CHUNK) {
                $this->appendIds($ids, $this->insertChunk('users', $chunk), count($chunk));
                $chunk = [];
            }
        }
        if ($chunk !== []) {
            $this->appendIds($ids, $this->insertChunk('users', $chunk), count($chunk));
        }

        return $ids;
    }

    /**
     * Precomputes per-player plans (event count, denormalized total_score,
     * language) so total_score can be written at player-insert time.
     * Events are regenerated identically later from the same per-player seed.
     *
     * @return array<int, array{count:int, score:int, language:string}>
     */
    private function buildPlans(Version $version, int $players, int $events): array
    {
        if ($players === 0) {
            return [];
        }

        $base = intdiv($events, $players);
        $remainder = $events % $players;
        $plans = [];

        for ($i = 0; $i < $players; $i++) {
            $count = $base + ($i < $remainder ? 1 : 0);
            $score = 0;
            foreach ($this->generateEvents($this->seedFor($version, $i), $count) as $event) {
                $score += $event['score'];
            }
            $plans[] = [
                'count' => $count,
                'score' => $score,
                'language' => self::LANGUAGE_CYCLE[$i % count(self::LANGUAGE_CYCLE)],
            ];
        }

        return $plans;
    }

    /**
     * Bulk-inserts players with their precomputed total_score.
     *
     * @param array<int, int> $userIds
     * @param array<int, array{count:int, score:int, language:string}> $plans
     * @return array<int, int>
     */
    private function seedPlayers(Version $version, array $userIds, array $plans): array
    {
        $now = Carbon::now()->toDateTimeString();
        $ids = [];
        $chunk = [];

        foreach ($plans as $i => $plan) {
            $chunk[] = [
                'version_id' => $version->id,
                'user_id' => $userIds[$i],
                'registered_at' => $this->timestampAt($this->windowSpan > 0 ? ($i * 7) % $this->windowSpan : 0),
                'total_score' => $plan['score'],
                'language' => $plan['language'],
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($chunk) === self::CHUNK) {
                $this->appendIds($ids, $this->insertChunk('players', $chunk), count($chunk));
                $chunk = [];
            }
        }
        if ($chunk !== []) {
            $this->appendIds($ids, $this->insertChunk('players', $chunk), count($chunk));
        }

        return $ids;
    }

    /**
     * Streams events (and, optionally, correlated facts) to the database in
     * chunks. Facts are attached to their real event id right after each
     * event chunk is inserted (ids are contiguous within a single insert).
     *
     * @param array<int, int> $playerIds
     * @param array<int, array{count:int, score:int, language:string}> $plans
     * @param array<int, int> $answerOptionIds
     * @return array<string, int>
     */
    private function seedEventsAndFacts(
        Version $version,
        array $playerIds,
        array $plans,
        bool $withFacts,
        int $singleChoiceQuestionId,
        array $answerOptionIds
    ): array {
        $now = Carbon::now()->toDateTimeString();
        $counts = ['events' => 0, 'answers' => 0, 'transactions' => 0, 'rewards' => 0];

        $bar = $this->output->createProgressBar(count($plans));
        $bar->start();

        /** @var array<int, array<string, mixed>> $eventRows */
        $eventRows = [];
        /** @var array<int, array<string, array<string, mixed>>> $eventFacts */
        $eventFacts = [];
        /** @var array<int, array<string, mixed>> $answerRows */
        $answerRows = [];
        /** @var array<int, array<string, mixed>> $transactionRows */
        $transactionRows = [];
        /** @var array<int, array<string, mixed>> $rewardRows */
        $rewardRows = [];

        $flush = function () use (
            &$eventRows,
            &$eventFacts,
            &$answerRows,
            &$transactionRows,
            &$rewardRows,
            &$counts
        ): void {
            if ($eventRows === []) {
                return;
            }
            $firstId = $this->insertChunk('events', $eventRows);
            $counts['events'] += count($eventRows);

            foreach ($eventFacts as $offset => $facts) {
                $eventId = $firstId + $offset;
                if (isset($facts['answer'])) {
                    $answerRows[] = ['event_id' => $eventId] + $facts['answer'];
                }
                if (isset($facts['transaction'])) {
                    $transactionRows[] = ['event_id' => $eventId] + $facts['transaction'];
                }
                if (isset($facts['reward'])) {
                    $rewardRows[] = ['event_id' => $eventId] + $facts['reward'];
                }
            }
            $eventRows = [];
            $eventFacts = [];

            $this->flushFacts('answers', $answerRows, $counts['answers']);
            $this->flushFacts('transactions', $transactionRows, $counts['transactions']);
            $this->flushFacts('rewards', $rewardRows, $counts['rewards']);
        };

        foreach ($plans as $i => $plan) {
            $playerId = $playerIds[$i];
            $language = $plan['language'];
            $events = $this->generateEvents($this->seedFor($version, $i), $plan['count']);

            $answerDone = false;
            $rewardDone = false;
            $transactionDone = !($withFacts && $i % 10 === 0);

            foreach ($events as $event) {
                $occurredAt = $this->timestampAt($event['offset']);
                $eventRows[] = [
                    'version_id' => $version->id,
                    'player_id' => $playerId,
                    'type' => $event['type'],
                    'occurred_at' => $occurredAt,
                    'payload' => $this->payload($language, $event),
                    'created_at' => $now,
                ];

                $facts = [];
                if ($withFacts) {
                    if (!$answerDone && $event['type'] === Event::TYPE_ANSWER_SUBMITTED) {
                        $answerOptionId = $answerOptionIds[$i % count($answerOptionIds)];
                        $facts['answer'] = $this->answerTemplate(
                            $version,
                            $playerId,
                            $singleChoiceQuestionId,
                            $answerOptionId,
                            $occurredAt,
                            $now
                        );
                        $answerDone = true;
                    }
                    if (!$rewardDone && $event['type'] === Event::TYPE_REWARD_GRANTED) {
                        $facts['reward'] = $this->rewardTemplate($version, $playerId, $i, $occurredAt, $now);
                        $rewardDone = true;
                    }
                    if (!$transactionDone && $event['type'] === Event::TYPE_GAME_COMPLETED) {
                        $facts['transaction'] = $this->transactionTemplate($version, $playerId, $i, $occurredAt, $now);
                        $transactionDone = true;
                    }
                }
                $eventFacts[count($eventRows) - 1] = $facts;

                if (count($eventRows) === self::CHUNK) {
                    $flush();
                }
            }

            $bar->advance();
        }

        $flush();
        $this->drainFacts('answers', $answerRows, $counts['answers']);
        $this->drainFacts('transactions', $transactionRows, $counts['transactions']);
        $this->drainFacts('rewards', $rewardRows, $counts['rewards']);

        $bar->finish();
        $this->newLine();

        return $counts;
    }

    /**
     * @return array<string, mixed>
     */
    private function answerTemplate(
        Version $version,
        int $playerId,
        int $questionId,
        int $answerOptionId,
        string $occurredAt,
        string $now
    ): array {
        return [
            'version_id' => $version->id,
            'player_id' => $playerId,
            'question_id' => $questionId,
            'answer_option_id' => $answerOptionId,
            'answer_text' => null,
            'occurred_at' => $occurredAt,
            'created_at' => $now,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function transactionTemplate(
        Version $version,
        int $playerId,
        int $index,
        string $occurredAt,
        string $now
    ): array {
        return [
            'version_id' => $version->id,
            'player_id' => $playerId,
            'type' => Transaction::TYPE_PURCHASE,
            'amount' => number_format(($index % 50) + 9.99, 2, '.', ''),
            'currency' => 'EUR',
            'status' => Transaction::STATUS_COMPLETED,
            'external_ref' => 'PAY-' . $version->id . '-' . $index,
            'occurred_at' => $occurredAt,
            'created_at' => $now,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rewardTemplate(Version $version, int $playerId, int $index, string $occurredAt, string $now): array
    {
        return [
            'version_id' => $version->id,
            'player_id' => $playerId,
            'reward_type' => 'coupon',
            'reward_code' => 'RW-' . $version->id . '-' . $index,
            'status' => Reward::STATUS_GRANTED,
            'granted_at' => $occurredAt,
            'redeemed_at' => null,
            'created_at' => $now,
        ];
    }

    /**
     * Deterministically generates one player's events from a seed, so the
     * total_score summed here matches the events inserted later.
     *
     * @return array<int, array{type:string, score:int, offset:int, utm:string}>
     */
    private function generateEvents(int $seed, int $count): array
    {
        mt_srand($seed);
        $events = [];
        $utmCount = count(self::UTM_SOURCES);
        $span = $this->windowSpan > 0 ? $this->windowSpan : 1;

        for ($j = 0; $j < $count; $j++) {
            $roll = mt_rand(1, 100);
            $type = $roll <= self::ANSWER_THRESHOLD
                ? Event::TYPE_ANSWER_SUBMITTED
                : ($roll <= self::GAME_THRESHOLD ? Event::TYPE_GAME_COMPLETED : Event::TYPE_REWARD_GRANTED);
            $score = $type === Event::TYPE_GAME_COMPLETED ? mt_rand(0, self::SCORE_MAX) : 0;
            $offset = mt_rand(0, $span);
            $utm = self::UTM_SOURCES[mt_rand(0, $utmCount - 1)];

            $events[] = ['type' => $type, 'score' => $score, 'offset' => $offset, 'utm' => $utm];
        }

        return $events;
    }

    /**
     * @param array{type:string, score:int, offset:int, utm:string} $event
     */
    private function payload(string $language, array $event): string
    {
        $payload = ['language' => $language, 'utm_source' => $event['utm']];
        if ($event['type'] === Event::TYPE_GAME_COMPLETED) {
            $payload['score'] = $event['score'];
        }

        return (string) json_encode($payload);
    }

    private function seedFor(Version $version, int $index): int
    {
        return ($version->id * 1000003) + $index;
    }

    private function timestampAt(int $offsetSeconds): string
    {
        return Carbon::createFromTimestamp($this->windowStart + $offsetSeconds)->toDateTimeString();
    }

    /**
     * Inserts a chunk in its own transaction and returns the first row id
     * (auto-increment ids are contiguous within a single insert statement).
     *
     * @param array<int, array<string, mixed>> $rows
     */
    private function insertChunk(string $table, array $rows): int
    {
        return DB::transaction(function () use ($table, $rows): int {
            DB::table($table)->insert($rows);

            return (int) DB::getPdo()->lastInsertId();
        });
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function flushFacts(string $table, array &$rows, int &$count): void
    {
        if (count($rows) < self::CHUNK) {
            return;
        }
        $this->insertChunk($table, $rows);
        $count += count($rows);
        $rows = [];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function drainFacts(string $table, array &$rows, int &$count): void
    {
        if ($rows === []) {
            return;
        }
        $this->insertChunk($table, $rows);
        $count += count($rows);
        $rows = [];
    }

    /**
     * @param array<int, int> $ids
     */
    private function appendIds(array &$ids, int $firstId, int $count): void
    {
        for ($k = 0; $k < $count; $k++) {
            $ids[] = $firstId + $k;
        }
    }

    /**
     * @param array<string, int> $counts
     * @param array<int, int> $answerOptionIds
     */
    private function report(
        Version $version,
        int $players,
        array $counts,
        array $answerOptionIds,
        float $duration
    ): void {
        $this->info("Version id: {$version->id}");
        $this->info("Version uuid: {$version->uuid}");
        $this->table(
            ['table', 'rows'],
            [
                ['users', $players],
                ['players', $players],
                ['questions', 3],
                ['answer_options', count($answerOptionIds)],
                ['events', $counts['events']],
                ['answers', $counts['answers']],
                ['transactions', $counts['transactions']],
                ['rewards', $counts['rewards']],
            ]
        );
        $this->info("Done in {$duration}s.");
    }
}
