<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A granted/redeemed prize (append-only). Lifecycle via `status`.
 */
class Reward extends Model
{
    use HasFactory;

    public const STATUS_GRANTED = 'granted';
    public const STATUS_REDEEMED = 'redeemed';
    public const STATUS_EXPIRED = 'expired';

    // Append-only table: no updated_at.
    public const UPDATED_AT = null;

    protected $fillable = [
        'version_id',
        'player_id',
        'event_id',
        'reward_type',
        'reward_code',
        'status',
        'granted_at',
        'redeemed_at',
    ];

    protected $casts = [
        'granted_at' => 'datetime',
        'redeemed_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    /**
     * Scopes the query to a version's rewards.
     */
    public function scopeForVersion(Builder $query, int $versionId): Builder
    {
        return $query->where('version_id', $versionId);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(Version::class);
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
