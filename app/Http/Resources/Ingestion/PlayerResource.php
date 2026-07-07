<?php

namespace App\Http\Resources\Ingestion;

use App\Models\Player;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Player
 */
class PlayerResource extends JsonResource
{
    /**
     * @param \Illuminate\Http\Request $request
     * @return array<string, mixed>
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'email' => optional($this->user)->email,
            'registered_at' => optional($this->registered_at)->toIso8601String(),
            'total_score' => (int) $this->total_score,
            'language' => $this->language,
        ];
    }
}
