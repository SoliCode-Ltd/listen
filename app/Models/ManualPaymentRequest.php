<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ManualPaymentRequest extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    /** billing_cycle of a payment for extra credits (App\Services\Billing\ExtraCredits), not for a plan. */
    public const CYCLE_EXTRA_CREDITS = 'extra_credits';

    protected static function booted(): void
    {
        static::creating(static function (self $model): void {
            if (empty($model->proof_token)) {
                $model->proof_token = Str::uuid()->toString();
            }
        });
    }

    protected $fillable = [
        'user_id',
        'plan_id',
        'billing_cycle',
        'payment_method',
        'expected_amount_minor',
        'currency',
        'country_code',
        'status',
        'price_snapshot',
        'destination_snapshot',
        'proof_path',
        'submitted_at',
        'reviewed_at',
        'reviewed_by',
        'reviewer_notes',
        'approved_subscription_id',
        'gclid',
    ];

    protected $casts = [
        'price_snapshot' => 'array',
        'destination_snapshot' => 'array',
        'chatgpt_ads_context' => 'array',
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'approved_subscription_id' => 'integer',
        'expected_amount_minor' => 'integer',
    ];

    public function isExtraCredits(): bool
    {
        return (string) $this->billing_cycle === self::CYCLE_EXTRA_CREDITS;
    }

    /** Payments for a plan or a pack: not for extra credits. */
    public function scopeForPlans($query)
    {
        return $query->where($query->qualifyColumn('billing_cycle'), '!=', self::CYCLE_EXTRA_CREDITS);
    }

    public function scopeExtraCredits($query)
    {
        return $query->where($query->qualifyColumn('billing_cycle'), self::CYCLE_EXTRA_CREDITS);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
