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

    protected static function booted(): void
    {
        // When any subscription row transitions into an active paid plan
        // (whether through Stripe webhook, manual payment approval, admin
        // edit, or programmatic assignment), evaluate the referral program.
        // evaluate() is idempotent: it only promotes a pending conversion
        // once, and is a no-op if no pending conversion exists.
        static::saved(function (Subscription $subscription): void {
            try {
                if (! in_array((string) $subscription->status, ['active', 'trialing'], true)) {
                    return;
                }

                $plan = $subscription->plan()->first();
                if (! $plan || (int) $plan->price_cents <= 0) {
                    return;
                }

                $user = $subscription->user()->first();
                if (! $user) {
                    return;
                }

                if (app(\App\Services\PlanAccessService::class)->userHasUnpaidTrialWindow((int) $user->id)) {
                    return;
                }

                app(\App\Services\Referral\ReferralQualificationService::class)
                    ->evaluate($user, 'paid_plan');
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Referral qualification evaluation failed on subscription save.', [
                    'subscription_id' => $subscription->id,
                    'user_id' => $subscription->user_id,
                    'exception' => $e->getMessage(),
                ]);
            }
        });

        // The welcome email for new paid members: once, when a member is first on an
        // active paid plan (paid online or set by an admin), never during an unpaid trial.
        static::saved(function (Subscription $subscription): void {
            try {
                if ((string) $subscription->status !== 'active') {
                    return;
                }
                // Only the moment someone becomes paid; renewals and other saves of
                // existing members never send it.
                if (! $subscription->wasRecentlyCreated && ! $subscription->wasChanged(['plan_id', 'status'])) {
                    return;
                }
                $plan = $subscription->plan()->first();
                $user = $subscription->user()->first();
                if (! $plan || (int) $plan->price_cents <= 0 || ! $user) {
                    return;
                }
                if (app(\App\Services\PlanAccessService::class)->userHasUnpaidTrialWindow((int) $user->id)) {
                    return;
                }
                app(\App\Services\Newsletter\PaidWelcomeEmail::class)->queueFirstWelcome($user);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Paid welcome email check failed on subscription save.', [
                    'subscription_id' => $subscription->id,
                    'exception' => $e->getMessage(),
                ]);
            }
        });
    }
}
