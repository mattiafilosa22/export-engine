<?php

namespace Tests\Feature\Ingestion;

use App\Jobs\IngestAnswersJob;
use App\Models\AnswerOption;
use App\Models\Import;
use App\Models\Player;
use App\Models\Question;
use App\Models\Version;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

class IngestAnswersTest extends IngestionTestCase
{
    public function test_it_accepts_the_batch_and_returns_202_with_a_pending_import(): void
    {
        Queue::fake();
        $version = Version::factory()->create();
        $question = Question::factory()->create(['version_id' => $version->id]);
        $option = AnswerOption::factory()->create(['version_id' => $version->id, 'question_id' => $question->id]);

        $response = $this->postJson("/api/v1/versions/{$version->uuid}/answers", [
            'answers' => [$this->row(1, (int) $question->id, (int) $option->id)],
        ]);

        $response->assertStatus(202)
            ->assertJsonPath('data.type', Import::TYPE_ANSWERS)
            ->assertJsonPath('data.status', Import::STATUS_PENDING);

        Queue::assertPushed(IngestAnswersJob::class);
    }

    public function test_the_worker_appends_answers_with_no_linked_event(): void
    {
        $version = Version::factory()->create();
        $player = Player::factory()->resolvable($version, 'a@example.com')->create();
        $question = Question::factory()->create(['version_id' => $version->id]);
        $option = AnswerOption::factory()->create(['version_id' => $version->id, 'question_id' => $question->id]);

        $import = $this->queueImport($version, Import::TYPE_ANSWERS, [
            $this->row((int) $player->id, (int) $question->id, (int) $option->id),
        ]);
        $this->work();

        $import->refresh();
        $this->assertSame(Import::STATUS_COMPLETED, $import->status);
        $this->assertSame(1, $import->inserted);

        $this->assertDatabaseHas('answers', [
            'version_id' => $version->id,
            'player_id' => $player->id,
            'question_id' => $question->id,
            'answer_option_id' => $option->id,
            'event_id' => null,
        ]);
    }

    public function test_a_resent_batch_does_not_duplicate_answers(): void
    {
        $version = Version::factory()->create();
        $player = Player::factory()->resolvable($version, 'a@example.com')->create();
        $question = Question::factory()->create(['version_id' => $version->id]);
        $option = AnswerOption::factory()->create(['version_id' => $version->id, 'question_id' => $question->id]);
        $rows = [$this->row((int) $player->id, (int) $question->id, (int) $option->id)];

        $this->queueImport($version, Import::TYPE_ANSWERS, $rows);
        $this->work();

        $second = $this->queueImport($version, Import::TYPE_ANSWERS, $rows);
        $this->work();

        $second->refresh();
        $this->assertSame(0, $second->inserted);
        $this->assertSame(1, $second->duplicates);
        $this->assertDatabaseCount('answers', 1);
    }

    public function test_an_unknown_question_id_is_counted_failed_without_blocking_the_batch(): void
    {
        $version = Version::factory()->create();
        $player = Player::factory()->resolvable($version, 'a@example.com')->create();

        $import = $this->queueImport($version, Import::TYPE_ANSWERS, [
            $this->row((int) $player->id, 999999, null, 'free text'),
        ]);
        $this->work();

        $import->refresh();
        $this->assertSame(0, $import->inserted);
        $this->assertSame(1, $import->failed);
        $this->assertDatabaseCount('answers', 0);
    }

    public function test_unresolvable_player_rows_are_counted_failed_without_blocking_the_batch(): void
    {
        $version = Version::factory()->create();
        $question = Question::factory()->create(['version_id' => $version->id]);
        $option = AnswerOption::factory()->create(['version_id' => $version->id, 'question_id' => $question->id]);

        $import = $this->queueImport($version, Import::TYPE_ANSWERS, [
            $this->row(999999, (int) $question->id, (int) $option->id),
        ]);
        $this->work();

        $import->refresh();
        $this->assertSame(0, $import->inserted);
        $this->assertSame(1, $import->failed);
    }

    public function test_it_rejects_a_batch_over_the_limit_with_413(): void
    {
        config(['gamindo.ingestion.max_batch_rows' => 2]);
        Queue::fake();
        $version = Version::factory()->create();

        $rows = array_map(function (int $i): array {
            return $this->row($i, 1, 1);
        }, range(1, 3));

        $this->postJson("/api/v1/versions/{$version->uuid}/answers", ['answers' => $rows])
            ->assertStatus(413);
        Queue::assertNothingPushed();
    }

    public function test_it_returns_422_when_neither_option_nor_text_is_present(): void
    {
        Queue::fake();
        $version = Version::factory()->create();

        $row = $this->row(1, 1, null);

        $this->postJson("/api/v1/versions/{$version->uuid}/answers", ['answers' => [$row]])
            ->assertStatus(422);
        Queue::assertNothingPushed();
    }

    public function test_it_returns_404_for_an_unknown_version(): void
    {
        $this->postJson('/api/v1/versions/' . Str::uuid() . '/answers', [
            'answers' => [$this->row(1, 1, 1)],
        ])->assertStatus(404);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $playerId, int $questionId, ?int $optionId, ?string $text = null): array
    {
        $row = [
            'player_id' => $playerId,
            'question_id' => $questionId,
            'occurred_at' => '2026-01-15T10:00:00Z',
        ];

        if ($optionId !== null) {
            $row['answer_option_id'] = $optionId;
        }
        if ($text !== null) {
            $row['answer_text'] = $text;
        }

        return $row;
    }
}
