<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A member's pack characters. Each purchase adds characters and restarts
 * the clock; whatever is left when the clock runs out is gone.
 */
class CharPackWallet extends Model
{
    protected $fillable = [
        'user_id',
        'chars_total',
        'chars_used',
        'expires_at',
    ];

    protected $casts = [
        'chars_total' => 'integer',
        'chars_used' => 'integer',
        'expires_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at === null || ! $this->expires_at->isFuture();
    }

    public function remaining(): int
    {
        return $this->isExpired() ? 0 : max(0, (int) $this->chars_total - (int) $this->chars_used);
    }

    public function isActive(): bool
    {
        return $this->remaining() > 0;
    }
}
