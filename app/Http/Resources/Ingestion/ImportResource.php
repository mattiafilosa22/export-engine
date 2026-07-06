<?php

namespace App\Http\Resources\Ingestion;

use App\Models\Import;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Import
 */
class ImportResource extends JsonResource
{
    /**
     * Exposes the uuid as the public `id` and hides the internal numeric id.
     * The raw batch `payload` is never exposed.
     *
     * @param \Illuminate\Http\Request $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->uuid,
            'type' => $this->type,
            'status' => $this->status,
            'total_rows' => $this->total_rows,
            'processed_rows' => (int) $this->processed_rows,
            'inserted' => (int) $this->inserted,
            'duplicates' => (int) $this->duplicates,
            'failed' => (int) $this->failed,
            'error_message' => $this->error_message,
            'created_at' => optional($this->created_at)->toIso8601String(),
            'started_at' => optional($this->started_at)->toIso8601String(),
            'completed_at' => optional($this->completed_at)->toIso8601String(),
        ];
    }
}
