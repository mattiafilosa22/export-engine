<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * The published campaign/game: root that everything attaches to.
 * Exposed in URLs via `uuid` (route key), never via numeric id.
 * Retired via soft-delete (children use RESTRICT foreign keys).
 */
class Version extends Model
{
    use HasFactory;
    use SoftDeletes;

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

    public function players(): HasMany
    {
        return $this->hasMany(Player::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class);
    }

    public function answerOptions(): HasMany
    {
        return $this->hasMany(AnswerOption::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(Answer::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function rewards(): HasMany
    {
        return $this->hasMany(Reward::class);
    }
}
