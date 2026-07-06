<?php

namespace App\Http\Requests\Version;

use App\Models\Version;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Boundary validation for creating a version (small, synchronous operation).
 * Only `name` is required; the uuid is generated server-side by the model.
 */
class StoreVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'client_name' => ['nullable', 'string', 'max:150'],
            'status' => ['nullable', Rule::in([
                Version::STATUS_DRAFT,
                Version::STATUS_ACTIVE,
                Version::STATUS_ARCHIVED,
            ])],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'config' => ['nullable', 'array'],
        ];
    }
}
