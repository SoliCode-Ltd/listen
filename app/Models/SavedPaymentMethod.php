<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavedPaymentMethod extends Model
{
    public const PURPOSE_TRIAL = 'trial';

    public const PURPOSE_SUBSCRIPTION = 'subscription';

    public const PURPOSE_UPDATE = 'update';

    protected $fillable = [
        'user_id',
        'billing_trial_id',
        'stripe_customer_id',
        'stripe_payment_method_id',
        'brand',
        'last4',
        'exp_month',
        'exp_year',
        'fingerprint',
        'funding',
        'country',
        'cvc_check',
        'setup_intent_id',
        'off_session_ready',
        'purpose',
        'is_default',
    ];

    protected $casts = [
        'off_session_ready' => 'boolean',
        'is_default' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function billingTrial(): BelongsTo
    {
        return $this->belongsTo(BillingTrial::class);
    }
}
