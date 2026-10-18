<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Characters an admin gave a member on top of their plan, for one credit
 * period (period_ym, App\Support\CreditPeriod). The usable total lives on
 * usage_counters.bonus_chars.
 */
class CharGift extends Model
{
    protected $fillable = [
        'user_id',
        'subscription_id',
        'period_ym',
        'chars',
        'note',
        'granted_by',
        'revoked_at',
        'revoked_by',
    ];

    protected $casts = [
        'chars' => 'integer',
        'revoked_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }
}
