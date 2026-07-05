<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Symfony\Component\HttpFoundation\Response;

/**
 * Synchronous boundary validation for a players batch (upsert).
 * Structural only, in-memory (ms): the DB is touched solely by the worker.
 */
class StorePlayersImportRequest extends FormRequest
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
        $rows = $this->input('players');
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
            'players' => ['required', 'array', 'min:1'],
            'players.*.email' => ['required', 'email'],
            'players.*.external_id' => ['nullable', 'string', 'max:100'],
            'players.*.registered_at' => ['nullable', 'date'],
            'players.*.language' => ['nullable', 'string', 'max:8'],
        ];
    }

    /**
     * The validated player rows.
     *
     * @return array<int, array<string, mixed>>
     */
    public function players(): array
    {
        return (array) $this->input('players', []);
    }
}
