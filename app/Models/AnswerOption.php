<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A question's selectable option (dimension). Correctness lives here.
 */
class AnswerOption extends Model
{
    use HasFactory;

    protected $fillable = [
        'version_id',
        'question_id',
        'code',
        'label',
        'position',
        'is_correct',
    ];

    protected $casts = [
        'position' => 'integer',
        'is_correct' => 'boolean',
    ];

    /**
     * Scopes the query to a version's options.
     */
    public function scopeForVersion(Builder $query, int $versionId): Builder
    {
        return $query->where('version_id', $versionId);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(Version::class);
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(Answer::class, 'answer_option_id');
    }
}
