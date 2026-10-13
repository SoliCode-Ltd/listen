<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BillingTrial extends Model
{
    public const STATUS_TRIALING = 'trialing';

    public const STATUS_PAID = 'paid';

    public const STATUS_CANCELED = 'canceled';

    public const STATUS_CHARGE_FAILED = 'charge_failed';

    public const STATUS_BLOCKED = 'blocked';

    protected $fillable = [
        'user_id',
        'plan_id',
        'quota_plan_id',
        'billing_cycle',
        'status',
        'is_admin_test',
        'stripe_customer_id',
        'stripe_subscription_id',
        'stripe_invoice_id',
        'setup_intent_id',
        'stripe_payment_method_id',
        'card_brand',
        'card_last4',
        'card_exp_month',
        'card_exp_year',
        'card_fingerprint',
        'card_funding',
        'card_country',
        'cvc_check',
        'off_session_ready',
        'amount_cents',
        'amount_paid_cents',
        'currency',
        'chars_limit',
        'minutes_limit',
        'max_chars_per_request',
        'premium_minutes_limit',
        'chars_used',
        'requests_used',
        'minutes_used',
        'email_normalized',
        'phone_normalized',
        'device_id',
        'registration_ip',
        'gclid',
        'blocked_reason',
        'started_at',
        'trial_ends_at',
        'paid_at',
        'canceled_at',
        'charge_failed_at',
    ];

    protected $casts = [
        'is_admin_test' => 'boolean',
        'off_session_ready' => 'boolean',
        'started_at' => 'datetime',
        'trial_ends_at' => 'datetime',
        'paid_at' => 'datetime',
        'canceled_at' => 'datetime',
        'charge_failed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // A trial that has just been paid makes a new paid member: send the welcome email.
        static::saved(function (BillingTrial $trial): void {
            if ($trial->status !== self::STATUS_PAID || ! $trial->wasChanged('status')) {
                return;
            }
            try {
                $user = User::query()->find($trial->user_id);
                $welcome = app(\App\Services\Newsletter\PaidWelcomeEmail::class);
                if ($user && $welcome->isPaidMember($user)) {
                    $welcome->queueWelcome($user);
                }
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Paid welcome email check failed on trial payment.', [
                    'trial_id' => $trial->id,
                    'exception' => $e->getMessage(),
                ]);
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function quotaPlan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'quota_plan_id');
    }

    public function savedPaymentMethods(): HasMany
    {
        return $this->hasMany(SavedPaymentMethod::class);
    }

    public function consumesOffer(): bool
    {
        return ! $this->is_admin_test && $this->status !== self::STATUS_BLOCKED;
    }

    /**
     * @return array{trialing:int,paid:int,canceled:int,charge_failed:int,blocked:int,finished:int,conversion_rate:float,revenue_cents:int}
     */
    public static function outcomeCounts(): array
    {
        $counts = static::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $trialing = (int) ($counts[self::STATUS_TRIALING] ?? 0);
        $paid = (int) ($counts[self::STATUS_PAID] ?? 0);
        $canceled = (int) ($counts[self::STATUS_CANCELED] ?? 0);
        $failed = (int) ($counts[self::STATUS_CHARGE_FAILED] ?? 0);
        $blocked = (int) ($counts[self::STATUS_BLOCKED] ?? 0);
        $finished = $paid + $canceled + $failed;

        return [
            'trialing' => $trialing,
            'paid' => $paid,
            'canceled' => $canceled,
            'charge_failed' => $failed,
            'blocked' => $blocked,
            'finished' => $finished,
            'conversion_rate' => $finished > 0 ? round(($paid / $finished) * 100, 1) : 0.0,
            'revenue_cents' => (int) static::query()->where('status', self::STATUS_PAID)->sum('amount_paid_cents'),
        ];
    }
}
