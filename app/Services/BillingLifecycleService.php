<?php

namespace App\Services;

use App\Models\BillingSubscription;
use App\Models\SiteSetting;
use App\Models\Subscription;
use App\Models\User;
use App\Support\LiveServer;
use App\Support\StripeInvoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Laravel\Cashier\Cashier;

class BillingLifecycleService
{
    private const EMAIL_SIGNATURE = 'Listen Studio Team';

    public function __construct(
        private readonly PlanAccessService $planAccessService,
        private readonly BillingSyncService $billingSyncService,
    ) {}

    public function sendPreRenewalReminders(): int
    {
        // Staging runs on a copy of live members: they never get reminders from it.
        if (! LiveServer::allowsCustomerActions()) {
            LiveServer::skipped('plan end reminder emails');

            return 0;
        }

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

            // A reminder sent more than two days ago was for an earlier end date (the
            // plan was renewed or extended since), so this one is still to be sent.
            if ($day === 7) {
                $query->where(fn ($q) => $q->whereNull('billing_reminder_7d_sent_at')->orWhere('billing_reminder_7d_sent_at', '<', now()->subDays(2)));
            }
            if ($day === 1) {
                $query->where(fn ($q) => $q->whereNull('billing_reminder_1d_sent_at')->orWhere('billing_reminder_1d_sent_at', '<', now()->subDays(2)));
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

                    // A card subscription gets Stripe's own branded reminder with the
                    // amount, so it is never reminded twice.
                    if ($this->renewsByCard((int) $user->id)) {
                        continue;
                    }

                    // So this plan does not renew by card (paid by InstaPay / Vodafone
                    // Cash, or given by the team): say when it ends, not that it renews.
                    $periodEnd = $subscription->current_period_end?->format('M j, Y') ?? 'soon';
                    $planName = (string) ($subscription->plan?->name ?? 'your plan');
                    $displayName = $this->displayName($user);

                    $subject = $day === 1
                        ? 'Your plan ends tomorrow'
                        : 'Your plan ends in '.$day.' days';
                    $body = $this->composeBody(
                        $displayName,
                        ["{$planName} ends on {$periodEnd}. It does not renew by itself."],
                        [
                            'Plan' => $planName,
                            'End Date' => $periodEnd,
                            'Days Remaining' => (string) $day,
                            'Subscription Status' => ucfirst((string) ($subscription->status ?? 'active')),
                        ],
                        'To keep it, renew or choose a plan at '.$this->manageBillingUrl()
                    );

                    $wasSent = $this->sendMail(
                        $user,
                        $subject,
                        $body,
                        "renewal-reminder:{$subscription->id}:{$day}:{$periodEnd}",
                        24 * 14
                    );
                    if (! $wasSent) {
                        continue;
                    }

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

    private function renewsByCard(int $userId): bool
    {
        return BillingSubscription::query()
            ->where('user_id', $userId)
            ->whereIn('stripe_status', ['active', 'trialing', 'past_due'])
            ->exists();
    }

    /**
     * A card payment failed (invoice.payment_failed). The member's plan is marked
     * past due, with a grace period, only when the invoice is for their own card
     * subscription and Stripe still says that subscription is behind on payment.
     * A declined plan change or first payment, an invoice paid since (a late
     * event) and a plan paid by InstaPay / Vodafone Cash never are.
     */
    public function handlePaymentFailed(object $invoice): void
    {
        $user = $this->findUserByStripeCustomerId((string) ($invoice->customer ?? ''));
        if (! $user) {
            return;
        }

        $subscription = $this->latestPaidLocalSubscription($user);
        if (! $subscription || $subscription->billing_payment_state === 'manual_override') {
            return;
        }

        // A declined plan change is voided and leaves the plan as it was; a declined
        // first payment is for a subscription the member never had. Neither is a
        // payment missing on the plan they have now.
        if (in_array(StripeInvoice::billingReason($invoice), ['subscription_update', 'subscription_create'], true)) {
            return;
        }

        if (! $this->stripeSaysBehindOnPayment($user, StripeInvoice::subscriptionId($invoice))) {
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

        // Marked as sent only once it was, so a later failure tries the warning again.
        if ($this->sendMail($user, $subject, $body, "payment-failed:{$subscription->id}:{$graceEnds}", 24 * 14)) {
            $subscription->forceFill([
                'billing_failed_notice_sent_at' => now(),
            ])->save();
        }
    }

    public function handlePaymentRecovered(object $invoice): void
    {
        $user = $this->findUserByStripeCustomerId((string) ($invoice->customer ?? ''));
        if (! $user) {
            return;
        }

        $subscription = $this->latestLocalSubscription($user);
        if (! $subscription) {
            return;
        }

        // A plan paid by InstaPay / Vodafone Cash keeps its state, unless this invoice
        // really charged a card subscription: the member now pays by card, so the
        // card plan takes over, as after a checkout (a $0 trial invoice never does).
        $cardTakesOver = false;
        if ($subscription->billing_payment_state === 'manual_override') {
            if ((int) ($invoice->amount_paid ?? 0) <= 0 || StripeInvoice::subscriptionId($invoice) === '') {
                return;
            }
            $cardTakesOver = true;
        }

        $subscription->forceFill([
            'billing_payment_state' => 'ok',
            'billing_grace_ends_at' => null,
            'billing_failed_notice_sent_at' => null,
        ])->save();

        if ($cardTakesOver) {
            $this->billingSyncService->syncIfStale($user, true);
        }
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

                    // Stripe decides, not our copy: a member who has paid since keeps
                    // their plan, and only a subscription still owed is cancelled.
                    $billingSubscription = $this->latestBillingSubscription($user);
                    $stripeStatus = $this->stripeStatusNow($billingSubscription);
                    if ($stripeStatus === null) {
                        continue;
                    }

                    if (in_array($stripeStatus, ['active', 'trialing'], true)) {
                        $subscription->forceFill([
                            'billing_payment_state' => 'ok',
                            'billing_grace_ends_at' => null,
                            'billing_failed_notice_sent_at' => null,
                        ])->save();

                        continue;
                    }

                    if (! $billingSubscription || ! in_array($stripeStatus, ['past_due', 'unpaid'], true)) {
                        // Nothing live in Stripe to cancel (it ended, or never started):
                        // the flag is settled and the sync decides the plan.
                        $subscription->forceFill([
                            'billing_payment_state' => 'ok',
                            'billing_grace_ends_at' => null,
                        ])->save();
                        $this->billingSyncService->syncIfStale($user, true);

                        continue;
                    }

                    if (! LiveServer::allowsCustomerActions()) {
                        LiveServer::skipped('cancelling a subscription whose grace period ended', [
                            'user_id' => $user->id,
                            'stripe_subscription_id' => $billingSubscription->stripe_id,
                        ]);

                        continue;
                    }

                    if (! $this->cancelStripeSubscriptionNow($user, $billingSubscription)) {
                        continue;
                    }

                    $planName = (string) ($subscription->plan?->name ?? 'your paid plan');
                    $graceEndedAt = optional($subscription->billing_grace_ends_at)->format('M j, Y') ?? now()->format('M j, Y');
                    $subscription->forceFill([
                        'billing_payment_state' => 'ok',
                        'billing_grace_ends_at' => null,
                        'billing_downgraded_at' => now(),
                    ])->save();

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

    /**
     * Whether the subscription is the member's own and Stripe, asked now, says
     * it is behind on payment. After a late event for an invoice that has been
     * paid since, Stripe says it is active again. If Stripe cannot be read, the
     * error goes up so the webhook is sent again later.
     */
    private function stripeSaysBehindOnPayment(User $user, string $stripeSubscriptionId): bool
    {
        if ($stripeSubscriptionId === '') {
            return false;
        }

        $ours = BillingSubscription::query()
            ->where('user_id', $user->id)
            ->where('stripe_id', $stripeSubscriptionId)
            ->exists();
        if (! $ours) {
            return false;
        }

        $live = Cashier::stripe()->subscriptions->retrieve($stripeSubscriptionId, []);

        return in_array(strtolower((string) ($live->status ?? '')), ['past_due', 'unpaid'], true);
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

    /**
     * Whether the email went out now. Not when it was sent before (the dedupe key),
     * on a server that is not the live site, or when sending failed; a failed send
     * is not remembered, so a later run can try again.
     */
    private function sendMail(User $user, string $subject, string $body, ?string $dedupeKey = null, int $ttlHours = 72): bool
    {
        if (! $user->email) {
            return false;
        }

        if (! LiveServer::allowsCustomerActions()) {
            LiveServer::skipped('billing email "'.$subject.'"', ['user_id' => $user->id]);

            return false;
        }

        if ($dedupeKey !== null && $this->shouldSkipEmail($dedupeKey, $ttlHours)) {
            return false;
        }

        try {
            Mail::raw($body, function ($message) use ($user, $subject) {
                $message->to($user->email, $user->name ?: null)->subject($subject);
            });
        } catch (\Throwable $exception) {
            if ($dedupeKey !== null) {
                Cache::forget($this->emailCacheKey($dedupeKey));
            }

            Log::warning('Unable to send billing lifecycle email.', [
                'user_id' => $user->id,
                'subject' => $subject,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }

        return true;
    }

    private function latestBillingSubscription(User $user): ?BillingSubscription
    {
        return BillingSubscription::query()
            ->where('user_id', $user->id)
            ->where('type', 'default')
            ->latest('id')
            ->first();
    }

    /**
     * What Stripe says about the card subscription right now: its status, '' when
     * there is none to ask about, or null when Stripe could not be read (the
     * next run asks again).
     */
    private function stripeStatusNow(?BillingSubscription $billingSubscription): ?string
    {
        $stripeId = trim((string) ($billingSubscription?->stripe_id ?? ''));
        if ($stripeId === '') {
            return '';
        }

        try {
            return strtolower((string) (Cashier::stripe()->subscriptions->retrieve($stripeId, [])->status ?? ''));
        } catch (\Throwable $exception) {
            Log::warning('Unable to read the Stripe subscription after grace period.', [
                'user_id' => $billingSubscription->user_id,
                'stripe_subscription_id' => $stripeId,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function cancelStripeSubscriptionNow(User $user, BillingSubscription $billingSubscription): bool
    {
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
        return ! Cache::add($this->emailCacheKey($key), now()->timestamp, now()->addHours(max(1, $ttlHours)));
    }

    private function emailCacheKey(string $key): string
    {
        return 'billing_lifecycle_email:'.sha1($key);
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
