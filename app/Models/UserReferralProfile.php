<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserReferralProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'referral_code',
        'referred_by_user_id',
        'referral_source_ip',
        'referral_source_device_id',
        'referred_at',
        'approved_conversions_count',
        'pending_conversions_count',
    ];

    protected $casts = [
        'referred_at' => 'datetime',
        'approved_conversions_count' => 'integer',
        'pending_conversions_count' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_by_user_id');
    }
}
