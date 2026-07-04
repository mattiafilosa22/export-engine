<?php

namespace App\Http\Resources;

use App\Models\Export;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Export
 */
class ExportResource extends JsonResource
{
    /**
     * Exposes the uuid as the public `id` and hides the internal numeric id.
     * `download_url` present only when the export is `completed`.
     *
     * @param \Illuminate\Http\Request $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->uuid,
            'status' => $this->status,
            'format' => $this->format,
            'progress' => (int) $this->progress,
            'total_rows' => $this->total_rows,
            'file_size' => $this->file_size,
            'error_message' => $this->error_message,
            'created_at' => optional($this->created_at)->toIso8601String(),
            'started_at' => optional($this->started_at)->toIso8601String(),
            'completed_at' => optional($this->completed_at)->toIso8601String(),
            'download_url' => $this->when(
                $this->resource->isCompleted(),
                function () {
                    return route('exports.download', ['export' => $this->uuid]);
                }
            ),
        ];
    }
}
