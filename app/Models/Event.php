<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only firehose log. Immutable: only `created_at`, no `updated_at`.
 * Hot JSON fields are exposed via the generated `payload_*` columns.
 */
class Event extends Model
{
    use HasFactory;

    public const TYPE_ANSWER_SUBMITTED = 'answer_submitted';
    public const TYPE_GAME_COMPLETED = 'game_completed';
    public const TYPE_REWARD_GRANTED = 'reward_granted';

    // Immutable table: no updated_at.
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
     * Scopes the query to a version's events (first column of every index).
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
