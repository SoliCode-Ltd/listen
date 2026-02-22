<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'plan_id',
        'status',
        'billing_payment_state',
        'current_period_start',
        'current_period_end',
        'billing_grace_ends_at',
        'billing_reminder_7d_sent_at',
        'billing_reminder_1d_sent_at',
        'billing_failed_notice_sent_at',
        'billing_downgraded_at',
    ];

    protected $casts = [
        'current_period_start' => 'datetime',
        'current_period_end' => 'datetime',
        'billing_grace_ends_at' => 'datetime',
        'billing_reminder_7d_sent_at' => 'datetime',
        'billing_reminder_1d_sent_at' => 'datetime',
        'billing_failed_notice_sent_at' => 'datetime',
        'billing_downgraded_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
