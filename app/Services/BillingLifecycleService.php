<?php

namespace App\Services;

use App\Models\BillingSubscription;
use App\Models\SiteSetting;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class BillingLifecycleService
{
    private const EMAIL_SIGNATURE = 'Listen Studio Team';

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

                    if ($this->planAccessService->userHasUnpaidTrialWindow((int) $user->id)) {
                        continue;
                    }

                    $periodEnd = $subscription->current_period_end?->format('M j, Y') ?? 'soon';
                    $planName = (string) ($subscription->plan?->name ?? 'your plan');
                    $displayName = $this->displayName($user);

                    $subject = $day === 1
                        ? 'Your subscription renews tomorrow'
                        : 'Your subscription renews in '.$day.' days';
                    $body = $this->composeBody(
                        $displayName,
                        ["{$planName} renews on {$periodEnd}."],
                        [
                            'Plan' => $planName,
                            'Renewal Date' => $periodEnd,
                            'Days Remaining' => (string) $day,
                            'Subscription Status' => ucfirst((string) ($subscription->status ?? 'active')),
                        ],
                        'To avoid interruption, update your payment method at '.$this->manageBillingUrl()
                    );

                    $this->sendMail(
                        $user,
                        $subject,
                        $body,
                        "renewal-reminder:{$subscription->id}:{$day}:{$periodEnd}",
                        24 * 14
                    );

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

        $graceDays = max(1, (int) SiteSetting::get('billing_grace_days', config('listen.billing.grace_days', 7)));
        $graceEndsAt = now()->addDays($graceDays);

        $subscription->forceFill([
            'billing_payment_state' => 'past_due',
            'billing_grace_ends_at' => $graceEndsAt,
        ])->save();

        if ($subscription->billing_failed_notice_sent_at) {
            return;
        }

        $graceEnds = $graceEndsAt->format('M j, Y');
        $planName = (string) ($subscription->plan?->name ?? 'your paid plan');
        $subject = 'Payment failed - action required';
        $body = $this->composeBody(
            $this->displayName($user),
            [
                'We could not take your latest payment, so your paid features are paused.',
                'Update your card and pay the open invoice to get them back straight away.',
            ],
            [
                'Plan' => $planName,
                'Payment State' => 'Past due',
            ],
            'Update your payment method at '.$this->manageBillingUrl()
        );

        $this->sendMail($user, $subject, $body, "payment-failed:{$subscription->id}:{$graceEnds}", 24 * 14);

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

                    $planName = (string) ($subscription->plan?->name ?? 'your paid plan');
                    $graceEndedAt = optional($subscription->billing_grace_ends_at)->format('M j, Y') ?? now()->format('M j, Y');
                    $this->sendMail(
                        $user,
                        'Your subscription was canceled',
                        $this->composeBody(
                            $this->displayName($user),
                            ['Your grace period ended without a successful payment, so the subscription was canceled.'],
                            [
                                'Plan' => $planName,
                                'Grace Period Ended' => $graceEndedAt,
                                'Canceled At' => now()->format('M j, Y g:i A'),
                                'Payment State' => 'Canceled',
                            ],
                            'You can upgrade again any time from '.$this->manageBillingUrl()
                        ),
                        "grace-cancel:{$user->id}:{$graceEndedAt}",
                        24 * 30
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

    private function sendMail(User $user, string $subject, string $body, ?string $dedupeKey = null, int $ttlHours = 72): void
    {
        if (! $user->email) {
            return;
        }

        if ($dedupeKey !== null && $this->shouldSkipEmail($dedupeKey, $ttlHours)) {
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

    private function shouldSkipEmail(string $key, int $ttlHours = 72): bool
    {
        $cacheKey = 'billing_lifecycle_email:'.sha1($key);

        return ! Cache::add($cacheKey, now()->timestamp, now()->addHours(max(1, $ttlHours)));
    }

    /**
     * @param  array<int, string>  $summaryLines
     * @param  array<string, string|null>  $details
     */
    private function composeBody(string $displayName, array $summaryLines, array $details = [], ?string $actionLine = null): string
    {
        $lines = [
            'Hi '.$displayName.',',
            '',
        ];

        foreach ($summaryLines as $line) {
            $line = trim((string) $line);
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        $filtered = [];
        foreach ($details as $label => $value) {
            $value = trim((string) $value);
            if ($value !== '' && $value !== 'N/A') {
                $filtered[$label] = $value;
            }
        }

        if ($filtered !== []) {
            $lines[] = '';
            $lines[] = 'Details:';
            foreach ($filtered as $label => $value) {
                $lines[] = "- {$label}: {$value}";
            }
        }

        if ($actionLine !== null && trim($actionLine) !== '') {
            $lines[] = '';
            $lines[] = trim($actionLine);
        }

        $lines[] = '';
        $lines[] = 'Thanks,';
        $lines[] = self::EMAIL_SIGNATURE;

        return implode("\n", $lines);
    }

    private function displayName(User $user): string
    {
        return trim((string) $user->name) !== '' ? (string) $user->name : 'there';
    }

    private function manageBillingUrl(): string
    {
        return rtrim((string) config('app.url', ''), '/').'/manage-subscription';
    }
}
