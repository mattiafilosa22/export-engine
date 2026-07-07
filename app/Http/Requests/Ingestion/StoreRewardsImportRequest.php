<?php

namespace App\Http\Requests\Ingestion;

use App\Models\Reward;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Synchronous boundary validation for a rewards batch (append, idempotent).
 * Direct ingestion alternative to the event-driven `reward_granted` event type:
 * rows inserted here have `event_id = NULL`. `dedup_key` is optional: dedup if
 * present, otherwise append — same contract as events.
 */
class StoreRewardsImportRequest extends FormRequest
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
        $rows = $this->input('rewards');
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
            'rewards' => ['required', 'array', 'min:1'],
            'rewards.*.dedup_key' => ['nullable', 'string', 'max:64'],
            'rewards.*.player_id' => ['required', 'integer'],
            'rewards.*.player_email' => ['nullable', 'email'],
            'rewards.*.reward_type' => ['required', 'string', 'max:40'],
            'rewards.*.reward_code' => ['nullable', 'string', 'max:100'],
            'rewards.*.status' => ['nullable', 'string', Rule::in([
                Reward::STATUS_GRANTED,
                Reward::STATUS_REDEEMED,
                Reward::STATUS_EXPIRED,
            ])],
            'rewards.*.granted_at' => ['required', 'date'],
            'rewards.*.redeemed_at' => ['nullable', 'date'],
        ];
    }

    /**
     * The validated reward rows.
     *
     * @return array<int, array<string, mixed>>
     */
    public function rewards(): array
    {
        return (array) $this->input('rewards', []);
    }
}
