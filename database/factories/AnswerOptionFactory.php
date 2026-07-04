<?php

namespace Database\Factories;

use App\Models\AnswerOption;
use App\Models\Question;
use App\Models\Version;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AnswerOption>
 */
class AnswerOptionFactory extends Factory
{
    protected $model = AnswerOption::class;

    public function definition(): array
    {
        return [
            'version_id' => Version::factory(),
            'question_id' => Question::factory(),
            'code' => strtoupper($this->faker->randomLetter()),
            'label' => $this->faker->unique()->words(2, true),
            'position' => $this->faker->numberBetween(1, 5),
            'is_correct' => null,
        ];
    }
}
