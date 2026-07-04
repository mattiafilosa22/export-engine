<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Stato durevole di un job di export asincrono: sistema di verita' dei tre
 * endpoint (crea / stato / download). Esposto nelle URL tramite `uuid`.
 * Timestamp di dominio gestiti a mano (created/started/completed), niente updated_at.
 */
class Export extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public const FORMAT_XLSX = 'xlsx';

    // I timestamp di dominio (created_at/started_at/completed_at) sono gestiti dai marker.
    public $timestamps = false;

    protected $fillable = [
        'uuid',
        'version_id',
        'params',
        'format',
        'status',
        'created_at',
    ];

    protected $casts = [
        'params' => 'array',
        'created_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'total_rows' => 'integer',
        'processed_rows' => 'integer',
        'file_size' => 'integer',
        'attempts' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $export): void {
            $export->uuid = $export->uuid ?: (string) Str::uuid();

            if ($export->getAttribute('created_at') === null) {
                $export->created_at = now();
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

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Marca l'inizio dell'elaborazione da parte del worker.
     */
    public function markProcessing(): void
    {
        $this->status = self::STATUS_PROCESSING;
        $this->started_at = now();
        $this->attempts = (int) $this->attempts + 1;
        $this->save();
    }

    /**
     * Marca il completamento con il file generato.
     */
    public function markCompleted(int $rows, string $filePath, int $fileSize): void
    {
        $this->status = self::STATUS_COMPLETED;
        $this->total_rows = $rows;
        $this->processed_rows = $rows;
        $this->file_path = $filePath;
        $this->file_size = $fileSize;
        $this->completed_at = now();
        $this->save();
    }

    /**
     * Marca il fallimento con il messaggio dell'eccezione.
     */
    public function markFailed(string $errorMessage): void
    {
        $this->status = self::STATUS_FAILED;
        $this->error_message = $errorMessage;
        $this->completed_at = now();
        $this->save();
    }
}
