<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A version's question (small dimension). `type` drives the answering rule.
 */
class Question extends Model
{
    use HasFactory;

    public const TYPE_SINGLE_CHOICE = 'single_choice';
    public const TYPE_MULTIPLE_CHOICE = 'multiple_choice';
    public const TYPE_RATING = 'rating';
    public const TYPE_OPEN = 'open';

    protected $fillable = [
        'version_id',
        'code',
        'text',
        'type',
        'position',
    ];

    protected $casts = [
        'position' => 'integer',
    ];

    /**
     * Scopes the query to a version's questions.
     */
    public function scopeForVersion(Builder $query, int $versionId): Builder
    {
        return $query->where('version_id', $versionId);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(Version::class);
    }

    public function answerOptions(): HasMany
    {
        return $this->hasMany(AnswerOption::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(Answer::class);
    }
}
