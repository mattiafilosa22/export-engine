<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Durable state of an async ingestion job (players/events batch): source of
 * truth for the create/status endpoints. Exposed in URLs via `uuid`.
 * Domain timestamps handled manually (created/started/completed), no updated_at.
 */
class Import extends Model
{
    use HasFactory;

    public const TYPE_PLAYERS = 'players';
    public const TYPE_EVENTS = 'events';

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    // Domain timestamps (created_at/started_at/completed_at) handled by the markers.
    public $timestamps = false;

    protected $fillable = [
        'uuid',
        'version_id',
        'type',
        'status',
        'total_rows',
        'payload',
        'created_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'created_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'total_rows' => 'integer',
        'processed_rows' => 'integer',
        'inserted' => 'integer',
        'duplicates' => 'integer',
        'failed' => 'integer',
        'attempts' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $import): void {
            $import->uuid = $import->uuid ?: (string) Str::uuid();

            if ($import->getAttribute('created_at') === null) {
                $import->created_at = now();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(Version::class);
    }

    public function scopeForVersion(Builder $query, int $versionId): Builder
    {
        return $query->where('version_id', $versionId);
    }

    /**
     * A terminal import is never reprocessed (idempotency at the import level).
     * FAILED is deliberately excluded: after markFailed() a retry must be able to
     * re-run the (idempotent) ingestion; the final failure is handled by failed().
     */
    public function isTerminal(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Marks the start of processing by the worker.
     */
    public function markProcessing(): void
    {
        $this->status = self::STATUS_PROCESSING;
        $this->started_at = now();
        $this->attempts = (int) $this->attempts + 1;
        $this->save();
    }

    /**
     * Marks completion with the ingestion counters.
     */
    public function markCompleted(int $processed, int $inserted, int $duplicates, int $failed): void
    {
        $this->status = self::STATUS_COMPLETED;
        $this->processed_rows = $processed;
        $this->inserted = $inserted;
        $this->duplicates = $duplicates;
        $this->failed = $failed;
        $this->completed_at = now();
        $this->save();
    }

    /**
     * Marks failure with the exception message.
     */
    public function markFailed(string $errorMessage): void
    {
        $this->status = self::STATUS_FAILED;
        $this->error_message = $errorMessage;
        $this->completed_at = now();
        $this->save();
    }
}
