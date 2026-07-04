<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The per-question fact: what a player chose. Append-only (no updated_at).
 * Closed questions reference an answer option; open ones use `answer_text`.
 */
class Answer extends Model
{
    use HasFactory;

    // Append-only table: no updated_at.
    public const UPDATED_AT = null;

    protected $fillable = [
        'version_id',
        'player_id',
        'event_id',
        'question_id',
        'answer_option_id',
        'answer_text',
        'occurred_at',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    /**
     * Scopes the query to a version's answers.
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

    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    public function answerOption(): BelongsTo
    {
        return $this->belongsTo(AnswerOption::class, 'answer_option_id');
    }
}
