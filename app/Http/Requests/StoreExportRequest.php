<?php

namespace App\Http\Requests;

use App\Models\Export;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Minimal validation of the export request (Slice 1): format and params.
 * Advanced parsing of `params` (sheets/columns/filters/...) comes in Slice 4.
 */
class StoreExportRequest extends FormRequest
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
            'format' => ['sometimes', 'string', Rule::in([Export::FORMAT_XLSX])],
            'params' => ['sometimes', 'array'],
        ];
    }

    public function exportFormat(): string
    {
        return (string) $this->input('format', Export::FORMAT_XLSX);
    }

    /**
     * @return array<string, mixed>
     */
    public function exportParams(): array
    {
        return (array) $this->input('params', []);
    }
}
