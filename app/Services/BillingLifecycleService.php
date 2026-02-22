<?php

namespace App\Services;

use App\Models\BillingSubscription;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class BillingLifecycleService
{
    public function __construct(
        private readonly PlanAccessService $planAccessService,
        private readonly BillingSyncService $billingSyncService,
    ) {}

    public function sendPreRenewalReminders(): int
    {
        $sent = 0;
        $days = array_values(array_unique(array_filter(
            array_map(fn ($v) => (int) $v, (array) config('listen.billing.pre_renewal_reminder_days', [7, 1])),
            fn (int $v) => $v >= 0
        )));

        foreach ($days as $day) {
            $query = Subscription::query()
                ->with(['user', 'plan'])
                ->where('status', 'active')
                ->whereNotNull('current_period_end')
                ->whereDate('current_period_end', now()->copy()->addDays($day)->toDateString())
                ->whereHas('plan', fn ($q) => $q->where('price_cents', '>', 0)->where('is_active', true));

            if ($day === 7) {
                $query->whereNull('billing_reminder_7d_sent_at');
            }
            if ($day === 1) {
                $query->whereNull('billing_reminder_1d_sent_at');
            }

            $query->chunkById(100, function ($subscriptions) use ($day, &$sent) {
                foreach ($subscriptions as $subscription) {
                    /** @var Subscription $subscription */
                    $user = $subscription->user;
                    if (! $user || ! $user->email) {
                        continue;
                    }

                    $periodEnd = $subscription->current_period_end?->format('M j, Y') ?? 'soon';
                    $planName = (string) ($subscription->plan?->name ?? 'your plan');

                    $subject = $day === 1
                        ? 'Your subscription renews tomorrow'
                        : 'Your subscription renews in '.$day.' days';
                    $body = "Hi {$user->name},\n\n".
                        "{$planName} renews on {$periodEnd}.\n".
                        "To avoid interruption, make sure your payment method is up to date in Manage Subscription.\n\n".
                        "Thanks,\nListen";

                    $this->sendMail($user, $subject, $body);

                    $subscription->forceFill([
                        'billing_reminder_7d_sent_at' => $day === 7 ? now() : $subscription->billing_reminder_7d_sent_at,
                        'billing_reminder_1d_sent_at' => $day === 1 ? now() : $subscription->billing_reminder_1d_sent_at,
                    ])->save();

                    $sent++;
                }
            });
        }

        return $sent;
    }

    public function handlePaymentFailed(?string $stripeCustomerId): void
    {
        $user = $this->findUserByStripeCustomerId($stripeCustomerId);
        if (! $user) {
            return;
        }

        $subscription = $this->latestPaidLocalSubscription($user);
        if (! $subscription) {
            return;
        }

        $graceDays = max(1, (int) config('listen.billing.grace_days', 7));
        $graceEndsAt = now()->addDays($graceDays);

        $subscription->forceFill([
            'billing_payment_state' => 'past_due',
            'billing_grace_ends_at' => $graceEndsAt,
        ])->save();

        if ($subscription->billing_failed_notice_sent_at) {
            return;
        }

        $periodEnd = $subscription->current_period_end?->format('M j, Y') ?? 'now';
        $subject = 'Payment failed - action required';
        $body = "Hi {$user->name},\n\n".
            "We could not process your renewal around {$periodEnd}.\n".
            "Please update your payment method within {$graceDays} days to keep your paid access.\n".
            "After that, your Stripe subscription will be canceled automatically.\n\n".
            "Thanks,\nListen";

        $this->sendMail($user, $subject, $body);

        $subscription->forceFill([
            'billing_failed_notice_sent_at' => now(),
        ])->save();
    }

    public function handlePaymentRecovered(?string $stripeCustomerId): void
    {
        $user = $this->findUserByStripeCustomerId($stripeCustomerId);
        if (! $user) {
            return;
        }

        $subscription = $this->latestLocalSubscription($user);
        if (! $subscription) {
            return;
        }

        $subscription->forceFill([
            'billing_payment_state' => 'ok',
            'billing_grace_ends_at' => null,
            'billing_failed_notice_sent_at' => null,
        ])->save();
    }

    public function downgradeExpiredGraceSubscriptions(): int
    {
        $count = 0;

        Subscription::query()
            ->with(['user', 'plan'])
            ->where('status', 'active')
            ->where('billing_payment_state', 'past_due')
            ->whereNotNull('billing_grace_ends_at')
            ->where('billing_grace_ends_at', '<=', now())
            ->whereHas('plan', fn ($q) => $q->where('price_cents', '>', 0))
            ->chunkById(100, function ($subscriptions) use (&$count) {
                foreach ($subscriptions as $subscription) {
                    /** @var Subscription $subscription */
                    $user = $subscription->user;
                    if (! $user) {
                        continue;
                    }

                    $stripeCanceled = $this->cancelStripeSubscriptionNow($user);
                    if (! $stripeCanceled) {
                        continue;
                    }

                    $local = $this->latestLocalSubscription($user);
                    $local?->forceFill([
                        'billing_payment_state' => 'ok',
                        'billing_grace_ends_at' => null,
                        'billing_downgraded_at' => now(),
                    ])->save();

                    $this->sendMail(
                        $user,
                        'Your subscription was canceled',
                        "Hi {$user->name},\n\nYour grace period ended without successful payment, so your Stripe subscription was canceled.\nYou can upgrade again anytime from Billing.\n\nThanks,\nListen"
                    );

                    $this->billingSyncService->syncIfStale($user, true);
                    $count++;
                }
            });

        return $count;
    }

    public function syncUserByStripeCustomerId(?string $stripeCustomerId): void
    {
        $user = $this->findUserByStripeCustomerId($stripeCustomerId);
        if (! $user) {
            return;
        }

        $this->billingSyncService->syncIfStale($user, true);
    }

    private function latestPaidLocalSubscription(User $user): ?Subscription
    {
        return Subscription::query()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->whereHas('plan', fn ($q) => $q->where('price_cents', '>', 0))
            ->latest('id')
            ->first();
    }

    private function latestLocalSubscription(User $user): ?Subscription
    {
        return Subscription::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->first();
    }

    private function findUserByStripeCustomerId(?string $stripeCustomerId): ?User
    {
        $stripeCustomerId = trim((string) $stripeCustomerId);
        if ($stripeCustomerId === '') {
            return null;
        }

        return User::query()->where('stripe_id', $stripeCustomerId)->first();
    }

    private function sendMail(User $user, string $subject, string $body): void
    {
        if (! $user->email) {
            return;
        }

        try {
            Mail::raw($body, function ($message) use ($user, $subject) {
                $message->to($user->email, $user->name ?: null)->subject($subject);
            });
        } catch (\Throwable $exception) {
            Log::warning('Unable to send billing lifecycle email.', [
                'user_id' => $user->id,
                'subject' => $subject,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function cancelStripeSubscriptionNow(User $user): bool
    {
        $billingSubscription = BillingSubscription::query()
            ->where('user_id', $user->id)
            ->where('type', 'default')
            ->latest('id')
            ->first();

        if (! $billingSubscription || ! $billingSubscription->valid()) {
            return true;
        }

        try {
            $billingSubscription->cancelNow();
            return true;
        } catch (\Throwable $exception) {
            Log::warning('Unable to cancel Stripe subscription after grace period.', [
                'user_id' => $user->id,
                'subscription_id' => $billingSubscription->id,
                'stripe_subscription_id' => $billingSubscription->stripe_id,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }
}
