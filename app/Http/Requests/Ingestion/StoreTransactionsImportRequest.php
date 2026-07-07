<?php

namespace App\Http\Requests\Ingestion;

use App\Models\Transaction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Synchronous boundary validation for a transactions batch (append, idempotent).
 * Direct ingestion alternative to the event-driven `transaction` event type: rows
 * inserted here have `event_id = NULL`. `dedup_key` is optional: dedup if
 * present, otherwise append — same contract as events.
 */
class StoreTransactionsImportRequest extends FormRequest
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
        $rows = $this->input('transactions');
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
            'transactions' => ['required', 'array', 'min:1'],
            'transactions.*.dedup_key' => ['nullable', 'string', 'max:64'],
            'transactions.*.player_id' => ['required', 'integer'],
            'transactions.*.player_email' => ['nullable', 'email'],
            'transactions.*.type' => ['required', 'string', Rule::in([
                Transaction::TYPE_PURCHASE,
                Transaction::TYPE_SPEND,
                Transaction::TYPE_REFUND,
            ])],
            'transactions.*.amount' => ['required', 'numeric'],
            'transactions.*.currency' => ['required', 'string', 'size:3'],
            'transactions.*.status' => ['nullable', 'string', Rule::in([
                Transaction::STATUS_PENDING,
                Transaction::STATUS_COMPLETED,
                Transaction::STATUS_FAILED,
            ])],
            'transactions.*.external_ref' => ['nullable', 'string', 'max:100'],
            'transactions.*.occurred_at' => ['required', 'date'],
        ];
    }

    /**
     * The validated transaction rows.
     *
     * @return array<int, array<string, mixed>>
     */
    public function transactions(): array
    {
        return (array) $this->input('transactions', []);
    }
}
