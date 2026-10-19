<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A browser or a device a member used the demo from (App\Services\DemoLinkedAccounts).
 */
class MemberDevice extends Model
{
    public const BROWSER = 'browser';

    public const DEVICE = 'device';

    protected $fillable = [
        'user_id',
        'kind',
        'signal',
        'touch',
        'first_seen_at',
        'last_seen_at',
    ];

    protected $casts = [
        'touch' => 'boolean',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
