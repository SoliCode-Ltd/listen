<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CharPackPurchase extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REFUNDED = 'refunded';

    public const METHOD_CARD = 'card';

    /** The one-time character pack (members without a paid plan). */
    public const KIND_PACK = 'pack';

    /** Extra credits a paid member adds to their plan (App\Services\Billing\ExtraCredits). */
    public const KIND_EXTRA_CREDITS = 'extra_credits';

    protected $fillable = [
        'user_id',
        'plan_id',
        'chars',
        'days',
        'amount_minor',
        'currency',
        'method',
        'status',
        'stripe_payment_intent_id',
        'manual_payment_request_id',
        'failure_message',
        'paid_at',
        'refunded_at',
        'kind',
        'period_ym',
    ];

    protected $casts = [
        'chars' => 'integer',
        'days' => 'integer',
        'amount_minor' => 'integer',
        'paid_at' => 'datetime',
        'refunded_at' => 'datetime',
    ];

    /** Extra credits for a paid plan, not a pack. */
    public function isExtraCredits(): bool
    {
        return (string) $this->kind === self::KIND_EXTRA_CREDITS;
    }

    /** Whole minutes of voice the purchase's credits make. */
    public function minutes(): int
    {
        return intdiv(max(0, (int) $this->chars), \App\Support\Credits::PER_MINUTE);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function manualPaymentRequest(): BelongsTo
    {
        return $this->belongsTo(ManualPaymentRequest::class);
    }
}
