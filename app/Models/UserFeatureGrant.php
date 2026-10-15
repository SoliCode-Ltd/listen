<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One studio option given to one member until a date, added by an admin.
 */
class UserFeatureGrant extends Model
{
    protected $fillable = ['user_id', 'feature', 'expires_at', 'note', 'granted_by'];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    /**
     * @param  Builder<UserFeatureGrant>  $query
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('expires_at', '>', now());
    }

    public function isActive(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isFuture();
    }
}
