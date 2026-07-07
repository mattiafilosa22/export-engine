<?php

namespace App\Http\Requests\Ingestion;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Symfony\Component\HttpFoundation\Response;

/**
 * Synchronous boundary validation for an answers batch (append, idempotent).
 * Direct ingestion alternative to the event-driven `answer_submitted` event
 * type: rows inserted here have `event_id = NULL`. Idempotency is the table's
 * natural unique key (version_id, player_id, question_id) — no `dedup_key`
 * needed here, unlike events/transactions/rewards.
 */
class StoreAnswersImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Size guard before per-row validation: an oversized batch is rejected
     * with 413 in ms, without walking every row.
     */
    protected function prepareForValidation(): void
    {
        $rows = $this->input('answers');
        $max = (int) config('gamindo.ingestion.max_batch_rows');

        if (is_array($rows) && count($rows) > $max) {
            abort(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, 'Batch too large.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'answers' => ['required', 'array', 'min:1'],
            'answers.*.player_id' => ['required', 'integer'],
            'answers.*.player_email' => ['nullable', 'email'],
            'answers.*.question_id' => ['required', 'integer'],
            'answers.*.answer_option_id' => ['nullable', 'integer'],
            'answers.*.answer_text' => ['nullable', 'string', 'max:500'],
            'answers.*.occurred_at' => ['required', 'date'],
        ];
    }

    /**
     * A closed question needs an option, an open one needs free text.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach ($this->answers() as $index => $row) {
                $hasOption = ! empty($row['answer_option_id']);
                $hasText = ! empty($row['answer_text']);
                if (! $hasOption && ! $hasText) {
                    $validator->errors()->add(
                        "answers.{$index}",
                        'Either answer_option_id or answer_text is required.'
                    );
                }
            }
        });
    }

    /**
     * The validated answer rows.
     *
     * @return array<int, array<string, mixed>>
     */
    public function answers(): array
    {
        return (array) $this->input('answers', []);
    }
}
