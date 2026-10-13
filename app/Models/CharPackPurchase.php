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
    ];

    protected $casts = [
        'chars' => 'integer',
        'days' => 'integer',
        'amount_minor' => 'integer',
        'paid_at' => 'datetime',
        'refunded_at' => 'datetime',
    ];

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
