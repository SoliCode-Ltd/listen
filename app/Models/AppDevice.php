<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A phone with the Listen app, signed in as a member (App\Services\Push\AppPush).
 */
class AppDevice extends Model
{
    /** A member's phones beyond this many: the ones unused the longest are forgotten. */
    public const PER_MEMBER = 10;

    protected $fillable = [
        'user_id',
        'platform',
        'token_hash',
        'token',
        'locale',
        'app_version',
        'last_seen_at',
    ];

    protected $hidden = ['token'];

    protected $casts = [
        'token' => 'encrypted',
        'last_seen_at' => 'datetime',
    ];

    public static function hashOf(string $token): string
    {
        return hash('sha256', $token);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
