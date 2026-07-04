<?php

namespace Database\Factories;

use App\Models\Question;
use App\Models\Version;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Question>
 */
class QuestionFactory extends Factory
{
    protected $model = Question::class;

    public function definition(): array
    {
        return [
            'version_id' => Version::factory(),
            'code' => strtoupper($this->faker->unique()->bothify('Q##')),
            'text' => $this->faker->sentence(),
            'type' => Question::TYPE_SINGLE_CHOICE,
            'position' => $this->faker->numberBetween(1, 20),
        ];
    }
}
