<?php

namespace Database\Factories;

use App\Models\Answer;
use App\Models\AnswerOption;
use App\Models\Player;
use App\Models\Question;
use App\Models\Version;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Answer>
 */
class AnswerFactory extends Factory
{
    protected $model = Answer::class;

    public function definition(): array
    {
        // Coordinate related rows in a single version for FK/UNIQUE coherence.
        $version = Version::factory()->create();
        $question = Question::factory()->create(['version_id' => $version->id]);
        $answerOption = AnswerOption::factory()->create([
            'version_id' => $version->id,
            'question_id' => $question->id,
        ]);
        $player = Player::factory()->create(['version_id' => $version->id]);

        return [
            'version_id' => $version->id,
            'player_id' => $player->id,
            'event_id' => null,
            'question_id' => $question->id,
            'answer_option_id' => $answerOption->id,
            'answer_text' => null,
            'occurred_at' => now()->subMinutes($this->faker->numberBetween(0, 10000)),
        ];
    }
}
