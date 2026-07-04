<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Log append-only del firehose. Immutabile: solo `created_at`, niente `updated_at`.
 * I campi JSON "caldi" sono esposti dalle colonne generate `payload_*`.
 */
class Event extends Model
{
    use HasFactory;

    public const TYPE_ANSWER_SUBMITTED = 'answer_submitted';
    public const TYPE_GAME_COMPLETED = 'game_completed';
    public const TYPE_REWARD_GRANTED = 'reward_granted';

    // Tabella immutabile: nessun updated_at.
    public const UPDATED_AT = null;

    protected $fillable = [
        'version_id',
        'player_id',
        'type',
        'occurred_at',
        'payload',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'created_at' => 'datetime',
        'payload' => 'array',
        'payload_score' => 'integer',
    ];

    /**
     * Restringe la query agli eventi di una versione (prima colonna di ogni indice).
     */
    public function scopeForVersion(Builder $query, int $versionId): Builder
    {
        return $query->where('version_id', $versionId);
    }

    public function version(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Version::class);
    }
}
