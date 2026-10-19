<?php

namespace App\Services;

use App\Models\BillingSubscription;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\BillingLifecycleMail;
use App\Services\Billing\RenewalGrace;
use App\Support\LiveServer;
use App\Support\StripeInvoice;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Cashier;

class BillingLifecycleService
{
    /** What the cancellation email says when the grace days ran out unpaid. */
    private const GRACE_RAN_OUT = [
        'Your grace period ended without a successful payment, so the subscription was canceled.',
        'Your account is on the free plan now.',
    ];

    public function __construct(
        private readonly PlanAccessService $planAccessService,
        private readonly BillingSyncService $billingSyncService,
        private readonly RenewalGrace $renewalGrace,
        private readonly BillingLifecycleMail $mail,
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

                    $subject = $day === 1
                        ? 'Your plan ends tomorrow'
                        : 'Your plan ends in '.$day.' days';
                    $body = $this->mail->compose(
                        $user,
                        ["{$planName} ends on {$periodEnd}. It does not renew by itself."],
                        [
                            'Plan' => $planName,
                            'End Date' => $periodEnd,
                            'Days Remaining' => (string) $day,
                            'Subscription Status' => ucfirst((string) ($subscription->status ?? 'active')),
                        ],
                        'To keep it, renew or choose a plan at '.$this->mail->manageBillingUrl()
                    );

                    $wasSent = $this->mail->send(
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
     * A card payment failed (invoice.payment_failed). It counts only when the
     * invoice is for the member's own card subscription and Stripe still says
     * that subscription is behind on payment: never a declined plan change or
     * first payment, an invoice paid since (a late event), or a plan paid by
     * InstaPay / Vodafone Cash. Then RenewalGrace decides, once: a member who
     * paid for the plan before keeps it until the grace days run out; one who
     * never did (a free trial's first charge) is moved to Free at once.
     */
    public function handlePaymentFailed(object $invoice): void
    {
        $user = $this->findUserByStripeCustomerId((string) ($invoice->customer ?? ''));
        if (! $user) {
            return;
        }

        // The member's own plan row: the one their plan comes from.
        $subscription = $this->latestLocalSubscription($user);
        if (! $subscription
            || (string) $subscription->status !== 'active'
            || $subscription->billing_payment_state === 'manual_override') {
            return;
        }

        // Nothing paid to keep, unless the missed payment was recorded before (a
        // billing sync saw it first and moved a plan never paid for to Free).
        if ($subscription->billing_payment_state !== 'past_due' && ! RenewalGrace::isPaidPlan($subscription)) {
            return;
        }

        // A declined plan change is voided and leaves the plan as it was; a declined
        // first payment is for a subscription the member never had. Neither is a
        // payment missing on the plan they have now.
        if (in_array(StripeInvoice::billingReason($invoice), ['subscription_update', 'subscription_create'], true)) {
            return;
        }

        $stripeSubscription = $this->ownSubscriptionBehindOnPayment($user, StripeInvoice::subscriptionId($invoice));
        if (! $stripeSubscription) {
            return;
        }

        $outcome = $this->renewalGrace->begin($subscription, $stripeSubscription, $invoice);
        // Marked as sent only once it was, so a later failure tries the warning again.
        $this->renewalGrace->warn($user, $subscription);

        // No grace: the paid features pause now, whichever of Stripe's events comes first.
        if ($outcome === RenewalGrace::PAUSED && RenewalGrace::isPaidPlan($subscription)) {
            $this->moveToFreePlan($user);
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

        // Behind on payment: Stripe decides whether that is over. The billing sync
        // takes Stripe's paid period (a fresh allowance from the renewal date) and
        // settles the missed payment; until it has, a grace keeps the plan on, so
        // a sync that cannot reach Stripe never drops a member who has just paid.
        if (! $cardTakesOver && $subscription->billing_payment_state === 'past_due') {
            $this->billingSyncService->syncIfStale($user, true);

            return;
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
                        // The plan still has the dates of the period paid before: it
                        // takes the one just paid for (a fresh allowance) from Stripe.
                        $this->billingSyncService->syncIfStale($user, true);

                        continue;
                    }

                    if (! $billingSubscription || ! in_array($stripeStatus, ['past_due', 'unpaid'], true)) {
                        // Nothing live in Stripe to cancel: it ended meanwhile (Stripe's own
                        // retries can cancel it before the grace runs out), or never started.
                        // The plan was kept on until now, as the member was told; it ends
                        // now, and they are told, as when it is cancelled below.
                        $this->endUnpaidPlan($user, $subscription);
                        $count++;

                        continue;
                    }

                    if (! LiveServer::allowsCustomerActions()) {
                        // Staging works on a copy of live members with the live Stripe keys:
                        // it never cancels in Stripe or emails them (the live site does).
                        // Its own copy of the member ends the plan as the live site does,
                        // on Free; the missed payment stays recorded, as Stripe still has it.
                        LiveServer::skipped('cancelling a subscription whose grace period ended', [
                            'user_id' => $user->id,
                            'stripe_subscription_id' => $billingSubscription->stripe_id,
                        ]);
                        $this->moveToFreePlan($user);

                        continue;
                    }

                    if (! $this->endUnpaidSubscription($user, $subscription, $billingSubscription)) {
                        continue;
                    }

                    $count++;
                }
            });

        return $count;
    }

    /**
     * The member cancels while a failed renewal's grace days keep their plan on.
     * The renewal was never paid, so no paid period is left to run to its end:
     * the subscription and the plan end now, the way the grace job ends them,
     * and the member is on Free. False when that could not be done, and nothing
     * changed: Stripe could not be read or refused, or has been paid since.
     */
    public function cancelDuringGrace(User $user): bool
    {
        $subscription = $this->latestLocalSubscription($user);
        $billingSubscription = $this->latestBillingSubscription($user);
        if (! RenewalGrace::keeps($subscription) || ! $billingSubscription) {
            return false;
        }

        // Stripe decides, not our copy: a renewal paid meanwhile is never cancelled this way.
        if (! in_array($this->stripeStatusNow($billingSubscription), ['past_due', 'unpaid'], true)) {
            $this->billingSyncService->syncIfStale($user, true);

            return false;
        }

        if (! LiveServer::allowsCustomerActions()) {
            // As the grace job there: staging never cancels in Stripe or emails, and its
            // own copy of the member ends on Free, as on the live site.
            LiveServer::skipped('cancelling a subscription during its grace period', [
                'user_id' => $user->id,
                'stripe_subscription_id' => $billingSubscription->stripe_id,
            ]);
            $this->moveToFreePlan($user);

            return true;
        }

        return $this->endUnpaidSubscription($user, $subscription, $billingSubscription, [
            'You canceled your subscription while its latest payment was still unpaid, so it ended straight away.',
            'Your account is on the free plan now, and nothing more will be charged.',
        ]);
    }

    /**
     * Ends a card subscription whose renewal was never paid, on the live site:
     * cancelled now in Stripe, its open invoice voided, and the plan moved to
     * Free (endUnpaidPlan()). The grace job does this when the grace days run
     * out, and the member when they cancel during them. False when Stripe could
     * not cancel it: nothing changed.
     *
     * @param  list<string>  $summary  what the email says happened
     */
    private function endUnpaidSubscription(User $user, Subscription $subscription, BillingSubscription $billingSubscription, array $summary = self::GRACE_RAN_OUT): bool
    {
        if (! $this->cancelStripeSubscriptionNow($user, $billingSubscription)) {
            return false;
        }

        $this->voidOpenInvoices($user, (string) $billingSubscription->stripe_id);
        $this->endUnpaidPlan($user, $subscription, $summary);

        return true;
    }

    /**
     * Moves a card plan whose renewal was never paid to Free, and tells the
     * member (once: the email's dedupe key).
     *
     * @param  list<string>  $summary  what the email says happened
     */
    private function endUnpaidPlan(User $user, Subscription $subscription, array $summary = self::GRACE_RAN_OUT): void
    {
        $planName = (string) ($subscription->plan?->name ?? 'your paid plan');
        $graceEndedAt = optional($subscription->billing_grace_ends_at)->format('M j, Y') ?? now()->format('M j, Y');
        // Cancelled by the member before the grace days ran out: they did not end.
        $graceRanOut = $subscription->billing_grace_ends_at === null || $subscription->billing_grace_ends_at->lte(now());
        $subscription->forceFill([
            'billing_payment_state' => 'ok',
            'billing_grace_ends_at' => null,
            'billing_downgraded_at' => now(),
        ])->save();
        // On Free now, also when the billing sync below cannot read Stripe.
        $this->moveToFreePlan($user);

        $this->mail->send(
            $user,
            'Your subscription was canceled',
            $this->mail->compose(
                $user,
                $summary,
                [
                    'Plan' => $planName,
                    'Grace Period Ended' => $graceRanOut ? $graceEndedAt : null,
                    'Canceled At' => now()->format('M j, Y g:i A'),
                    'Payment State' => 'Canceled',
                ],
                'You can upgrade again any time from '.$this->mail->manageBillingUrl()
            ),
            "grace-cancel:{$user->id}:{$graceEndedAt}",
            24 * 30
        );

        $this->billingSyncService->syncIfStale($user, true);
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
     * The member's own subscription as Stripe has it now, when Stripe says it is
     * behind on payment (null otherwise). After a late event for an invoice that
     * has been paid since, Stripe says it is active again. If Stripe cannot be
     * read, the error goes up so the webhook is sent again later.
     */
    private function ownSubscriptionBehindOnPayment(User $user, string $stripeSubscriptionId): ?object
    {
        if ($stripeSubscriptionId === '') {
            return null;
        }

        $ours = BillingSubscription::query()
            ->where('user_id', $user->id)
            ->where('stripe_id', $stripeSubscriptionId)
            ->exists();
        if (! $ours) {
            return null;
        }

        $live = Cashier::stripe()->subscriptions->retrieve($stripeSubscriptionId, []);

        return in_array(strtolower((string) ($live->status ?? '')), ['past_due', 'unpaid'], true) ? $live : null;
    }

    private function moveToFreePlan(User $user): void
    {
        $free = $this->planAccessService->freePlan();
        if ($free) {
            $this->planAccessService->assignPlan($user, $free);
        }
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
            // Without proration: the period this cuts short was never paid, so it
            // must leave no credit for unused time (a later subscription would get
            // it as a discount). Cashier prorates by default.
            $billingSubscription->noProrate()->cancelNow();

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

    /**
     * Cancelling only pauses collection on the subscription's open invoices: the
     * unpaid renewal is voided, so it can never be paid for a period the member
     * no longer has (Manage Subscription lists an open invoice with a pay link).
     */
    private function voidOpenInvoices(User $user, string $stripeSubscriptionId): void
    {
        try {
            $open = Cashier::stripe()->invoices->all(['subscription' => $stripeSubscriptionId, 'status' => 'open', 'limit' => 20]);
            foreach ($open->data ?? [] as $invoice) {
                Cashier::stripe()->invoices->voidInvoice((string) $invoice->id, []);
            }
        } catch (\Throwable $exception) {
            Log::warning('Unable to void the open invoices of a subscription cancelled unpaid.', [
                'user_id' => $user->id,
                'stripe_subscription_id' => $stripeSubscriptionId,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
