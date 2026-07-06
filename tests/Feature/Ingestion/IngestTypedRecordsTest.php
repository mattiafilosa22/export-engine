<?php

namespace Tests\Feature\Ingestion;

use App\Models\AnswerOption;
use App\Models\Event;
use App\Models\Import;
use App\Models\Player;
use App\Models\Question;
use App\Models\Version;

class IngestTypedRecordsTest extends IngestionTestCase
{
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
