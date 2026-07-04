<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * The published campaign/game: root that events and exports attach to.
 * Exposed in URLs via `uuid` (route key), never via numeric id.
 */
class Version extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'uuid',
        'name',
        'client_name',
        'status',
        'starts_at',
        'ends_at',
        'config',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'config' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $version): void {
            $version->uuid = $version->uuid ?: (string) Str::uuid();
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    public function exports(): HasMany
    {
        return $this->hasMany(Export::class);
    }
}
