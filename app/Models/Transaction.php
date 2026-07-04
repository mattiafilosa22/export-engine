<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A money movement (append-only). DECIMAL amount, direction via `type`.
 */
class Transaction extends Model
{
    use HasFactory;

    public const TYPE_PURCHASE = 'purchase';
    public const TYPE_SPEND = 'spend';
    public const TYPE_REFUND = 'refund';

    public const STATUS_PENDING = 'pending';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    // Append-only table: no updated_at.
    public const UPDATED_AT = null;

    protected $fillable = [
        'version_id',
        'player_id',
        'event_id',
        'type',
        'amount',
        'currency',
        'status',
        'external_ref',
        'occurred_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'occurred_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    /**
     * Scopes the query to a version's transactions.
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
