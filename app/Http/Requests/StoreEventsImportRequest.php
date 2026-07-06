<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Symfony\Component\HttpFoundation\Response;

/**
 * Synchronous boundary validation for an events batch (append, idempotent).
 * `dedup_key` is optional: dedup if present, otherwise append — robustness
 * without imposing a requirement on external producers.
 */
class StoreEventsImportRequest extends FormRequest
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
        $rows = $this->input('events');
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
            'events' => ['required', 'array', 'min:1'],
            'events.*.dedup_key' => ['nullable', 'string', 'max:64'],
            // player_id is the traccia contract (always present); player_email is
            // kept as an optional fallback identifier for backward compatibility.
            'events.*.player_id' => ['required', 'integer'],
            'events.*.player_email' => ['nullable', 'email'],
            'events.*.type' => ['required', 'string', 'max:40'],
            'events.*.occurred_at' => ['required', 'date'],
            'events.*.payload' => ['nullable', 'array'],
        ];
    }

    /**
     * The validated event rows.
     *
     * @return array<int, array<string, mixed>>
     */
    public function events(): array
    {
        return (array) $this->input('events', []);
    }
}
