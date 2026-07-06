<?php

namespace Tests\Feature\Ingestion;

use App\Models\AnswerOption;
use App\Models\Event;
use App\Models\Import;
use App\Models\Player;
use App\Models\Question;
use App\Models\Version;
use Illuminate\Support\Facades\DB;

class IngestTypedRecordsTest extends IngestionTestCase
{
    public function test_the_database_pins_the_consecutive_autoinc_lock_mode(): void
    {
        // Typed-record linkage (firstId + offset) relies on contiguous ids, which
        // a single bulk INSERT guarantees only under innodb_autoinc_lock_mode=1.
        $row = DB::selectOne('SELECT @@innodb_autoinc_lock_mode AS mode');

        $this->assertSame(1, (int) $row->mode);
    }

    public function test_typed_records_in_a_multi_row_batch_link_to_their_own_event(): void
    {
        $version = Version::factory()->create();
        $alice = Player::factory()->create(['version_id' => $version->id]);
        $bob = Player::factory()->create(['version_id' => $version->id]);
        $carol = Player::factory()->create(['version_id' => $version->id]);

        // Mixed batch, one typed event per distinct player: a wrong offset would
        // link a typed record to another player's event.
        $this->queueImport($version, Import::TYPE_EVENTS, [
            [
                'player_id' => $alice->id, 'type' => Event::TYPE_TRANSACTION,
                'occurred_at' => '2026-01-15T10:00:00Z', 'dedup_key' => 't-a',
                'payload' => ['type' => 'purchase', 'amount' => 5.00, 'currency' => 'EUR'],
            ],
            [
                'player_id' => $bob->id, 'type' => Event::TYPE_REWARD_GRANTED,
                'occurred_at' => '2026-01-15T10:05:00Z', 'dedup_key' => 'r-b',
                'payload' => ['reward_type' => 'coupon', 'reward_code' => 'B10'],
            ],
            [
                'player_id' => $carol->id, 'type' => Event::TYPE_TRANSACTION,
                'occurred_at' => '2026-01-15T10:10:00Z', 'dedup_key' => 't-c',
                'payload' => ['type' => 'refund', 'amount' => 2.00, 'currency' => 'EUR'],
            ],
        ]);
        $this->work();

        $this->assertDatabaseCount('transactions', 2);
        $this->assertDatabaseCount('rewards', 1);

        // Every typed record must point at an event of its own player and type.
        foreach (DB::table('transactions')->get() as $transaction) {
            $this->assertDatabaseHas('events', [
                'id' => $transaction->event_id,
                'player_id' => $transaction->player_id,
                'type' => Event::TYPE_TRANSACTION,
            ]);
        }
        foreach (DB::table('rewards')->get() as $reward) {
            $this->assertDatabaseHas('events', [
                'id' => $reward->event_id,
                'player_id' => $reward->player_id,
                'type' => Event::TYPE_REWARD_GRANTED,
            ]);
        }
    }

    public function test_an_answer_event_creates_the_event_and_the_linked_answer(): void
    {
        $version = Version::factory()->create();
        $player = Player::factory()->create(['version_id' => $version->id]);
        $question = Question::factory()->create(['version_id' => $version->id]);
        $option = AnswerOption::factory()->create(['version_id' => $version->id, 'question_id' => $question->id]);

        $import = $this->queueImport($version, Import::TYPE_EVENTS, [[
            'player_id' => $player->id,
            'type' => Event::TYPE_ANSWER_SUBMITTED,
            'occurred_at' => '2026-01-15T10:00:00Z',
            'dedup_key' => 'a-1',
            'payload' => ['question_id' => $question->id, 'answer_option_id' => $option->id],
        ]]);
        $this->work();

        $import->refresh();
        $this->assertSame(1, $import->inserted);
        $this->assertDatabaseCount('events', 1);

        $event = Event::firstOrFail();
        $this->assertDatabaseHas('answers', [
            'version_id' => $version->id,
            'player_id' => $player->id,
            'event_id' => $event->id,
            'question_id' => $question->id,
            'answer_option_id' => $option->id,
        ]);
    }

    public function test_a_transaction_event_creates_the_linked_transaction(): void
    {
        $version = Version::factory()->create();
        $player = Player::factory()->create(['version_id' => $version->id]);

        $this->queueImport($version, Import::TYPE_EVENTS, [[
            'player_id' => $player->id,
            'type' => Event::TYPE_TRANSACTION,
            'occurred_at' => '2026-01-15T10:00:00Z',
            'dedup_key' => 't-1',
            'payload' => ['type' => 'purchase', 'amount' => 9.99, 'currency' => 'EUR'],
        ]]);
        $this->work();

        $event = Event::firstOrFail();
        $this->assertDatabaseHas('transactions', [
            'event_id' => $event->id,
            'player_id' => $player->id,
            'type' => 'purchase',
            'amount' => 9.99,
            'currency' => 'EUR',
            'status' => 'completed',
        ]);
    }

    public function test_a_reward_event_creates_the_linked_reward(): void
    {
        $version = Version::factory()->create();
        $player = Player::factory()->create(['version_id' => $version->id]);

        $this->queueImport($version, Import::TYPE_EVENTS, [[
            'player_id' => $player->id,
            'type' => Event::TYPE_REWARD_GRANTED,
            'occurred_at' => '2026-01-15T10:00:00Z',
            'dedup_key' => 'r-1',
            'payload' => ['reward_type' => 'coupon', 'reward_code' => 'XMAS10'],
        ]]);
        $this->work();

        $event = Event::firstOrFail();
        $this->assertDatabaseHas('rewards', [
            'event_id' => $event->id,
            'player_id' => $player->id,
            'reward_type' => 'coupon',
            'reward_code' => 'XMAS10',
            'status' => 'granted',
        ]);
    }

    public function test_a_resent_batch_duplicates_neither_events_nor_typed_records(): void
    {
        $version = Version::factory()->create();
        $player = Player::factory()->create(['version_id' => $version->id]);
        $question = Question::factory()->create(['version_id' => $version->id]);
        $option = AnswerOption::factory()->create(['version_id' => $version->id, 'question_id' => $question->id]);
        $rows = [[
            'player_id' => $player->id,
            'type' => Event::TYPE_ANSWER_SUBMITTED,
            'occurred_at' => '2026-01-15T10:00:00Z',
            'dedup_key' => 'a-1',
            'payload' => ['question_id' => $question->id, 'answer_option_id' => $option->id],
        ]];

        $this->queueImport($version, Import::TYPE_EVENTS, $rows);
        $this->work();

        $second = $this->queueImport($version, Import::TYPE_EVENTS, $rows);
        $this->work();

        $second->refresh();
        $this->assertSame(0, $second->inserted);
        $this->assertSame(1, $second->duplicates);
        $this->assertDatabaseCount('events', 1);
        $this->assertDatabaseCount('answers', 1);
    }

    public function test_a_typed_event_with_missing_fields_is_skipped_without_failing_the_batch(): void
    {
        $version = Version::factory()->create();
        $player = Player::factory()->create(['version_id' => $version->id]);

        $import = $this->queueImport($version, Import::TYPE_EVENTS, [[
            'player_id' => $player->id,
            'type' => Event::TYPE_ANSWER_SUBMITTED,
            'occurred_at' => '2026-01-15T10:00:00Z',
            'dedup_key' => 'a-1',
            'payload' => ['answer_option_id' => 123], // no question_id
        ]]);
        $this->work();

        $import->refresh();
        // The event is still ingested; only the typed answer is skipped.
        $this->assertSame(Import::STATUS_COMPLETED, $import->status);
        $this->assertSame(1, $import->inserted);
        $this->assertDatabaseCount('events', 1);
        $this->assertDatabaseCount('answers', 0);
    }
}
