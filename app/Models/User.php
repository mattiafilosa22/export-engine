<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The person's identity, stored once and reused across versions.
 * Domain model (unique email + optional external_id), not an auth model.
 */
class User extends Model
{
    use HasFactory;

    protected $fillable = [
        'email',
        'external_id',
    ];

    public function players(): HasMany
    {
        return $this->hasMany(Player::class);
    }
}
