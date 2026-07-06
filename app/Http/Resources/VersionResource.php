<?php

namespace App\Http\Resources;

use App\Models\Version;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Version
 */
class VersionResource extends JsonResource
{
    /**
     * Exposes the version by its public `uuid` (never the numeric id).
     *
     * @param \Illuminate\Http\Request $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'client_name' => $this->client_name,
            'status' => $this->status,
            'starts_at' => optional($this->starts_at)->toIso8601String(),
            'ends_at' => optional($this->ends_at)->toIso8601String(),
            'config' => $this->config,
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
