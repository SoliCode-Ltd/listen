<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A move to a lower plan that waits for the end of the period the member paid
 * for (App\Services\Billing\PlanChanges).
 *
 * @property string $source card | card_start | manual
 * @property string $status scheduled | applied | cancelled
 */
class PlanChange extends Model
{
    public const SOURCE_CARD = 'card';

    public const SOURCE_CARD_START = 'card_start';

    public const SOURCE_MANUAL = 'manual';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'user_id', 'from_plan_id', 'to_plan_id', 'billing_cycle', 'source', 'status',
        'starts_at', 'period_ends_at', 'stripe_subscription_id', 'stripe_schedule_id',
        'stripe_price_id', 'stripe_coupon_id', 'amount_cents', 'first_amount_cents', 'discount',
        'manual_payment_request_id', 'cancel_reason', 'applied_at', 'cancelled_at',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'period_ends_at' => 'datetime',
        'applied_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'discount' => 'array',
        'amount_cents' => 'integer',
        'first_amount_cents' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function fromPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'from_plan_id');
    }

    public function toPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'to_plan_id');
    }

    public function manualPaymentRequest(): BelongsTo
    {
        return $this->belongsTo(ManualPaymentRequest::class);
    }

    public function scopeScheduled(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_SCHEDULED);
    }

    public function isScheduled(): bool
    {
        return $this->status === self::STATUS_SCHEDULED;
    }

    /** The member can call it off and keep their plan (one paid by hand is already paid for). */
    public function canBeKept(): bool
    {
        return $this->isScheduled() && $this->source !== self::SOURCE_MANUAL;
    }
}
