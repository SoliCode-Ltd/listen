<?php

namespace App\Services\Billing;

use App\Models\BillingTrial;
use App\Models\SiteSetting;
use App\Models\Subscription;
use App\Models\User;
use App\Support\StripeInvoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Cashier;

/**
 * A card renewal that could not be charged. A member who has paid for their
 * plan before keeps it, with every paid feature, for the grace days (Billing
 * Settings, the days the grace job waits before it cancels): a one-off bank
 * decline never costs a paying member their plan straight away.
 *
 * While the grace lasts, the plan keeps the dates of the period that was paid
 * for, so its credits stay in that period with what was used so far: no fresh
 * allowance until the renewal is paid. (Stripe has already moved its own
 * dates on to the new, unpaid month.) Paid during the grace, the billing sync
 * takes Stripe's new period, a fresh allowance as for any renewal. Unpaid,
 * the plan stops counting the moment the grace ends, and the grace job cancels
 * the subscription (BillingLifecycleService::downgradeExpiredGraceSubscriptions()).
 * Stripe ending the subscription first (its own retry settings) changes none
 * of this: the plan stays on until the grace ends, as the member was told.
 *
 * No grace for a member who never paid for the plan: the first charge after a
 * free trial failing pauses the paid features at once. A declined first payment
 * or plan change never gets here (Stripe does not put those subscriptions past
 * due), and a plan paid by hand (manual_override) is never touched.
 *
 * On the member's plan row (subscriptions): billing_payment_state 'past_due'
 * records the missed payment, once; billing_grace_ends_at is when the plan
 * stops, or null when it got no grace.
 */
class RenewalGrace
{
    /** The plan stays on until the grace ends. */
    public const KEPT = 'kept';

    /** No grace: the paid features pause at once. */
    public const PAUSED = 'paused';

    /** The grace days ran out unpaid. */
    public const ENDED = 'ended';

    /** Two of Stripe's timestamps this close are the same moment. */
    private const SAME_MOMENT_SECONDS = 120;

    public function __construct(private readonly BillingLifecycleMail $mail) {}

    /**
     * The rows the grace keeps on at $at (the caller narrows the query to the
     * member's active rows), for PlanAccessService and CreditPeriod.
     *
     * @template TQuery of \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public static function whereKept($query, \DateTimeInterface $at)
    {
        return $query->where('billing_payment_state', 'past_due')
            ->whereNotNull('billing_grace_ends_at')
            ->where('billing_grace_ends_at', '>', $at);
    }

    /** Whether the grace keeps this paid plan on at $at (now by default). */
    public static function keeps(?Subscription $plan, ?\DateTimeInterface $at = null): bool
    {
        return $plan !== null
            && (string) $plan->status === 'active'
            && (string) $plan->billing_payment_state === 'past_due'
            && $plan->billing_grace_ends_at !== null
            && $plan->billing_grace_ends_at->gt($at ?? now())
            && self::isPaidPlan($plan);
    }

    /**
     * Whether this paid plan's grace days ran out unpaid while it is still the
     * member's row: nothing counts it any more (they are on Free), and it is
     * left for the grace job, which cancels the subscription and then moves
     * the row to Free.
     */
    public static function ranOut(?Subscription $plan): bool
    {
        return $plan !== null
            && (string) $plan->status === 'active'
            && (string) $plan->billing_payment_state === 'past_due'
            && $plan->billing_grace_ends_at !== null
            && $plan->billing_grace_ends_at->lte(now())
            && self::isPaidPlan($plan);
    }

    /** What a recorded missed payment means for the plan now. */
    public static function outcome(Subscription $plan): string
    {
        if ($plan->billing_grace_ends_at === null) {
            return self::PAUSED;
        }
        if ($plan->billing_grace_ends_at->lte(now())) {
            return self::ENDED;
        }

        // Kept only while the row still holds the paid plan.
        return self::isPaidPlan($plan) ? self::KEPT : self::PAUSED;
    }

    /**
     * Records that the member's card plan is behind on payment (Stripe says the
     * subscription is past_due or unpaid). Once: whichever of the failed-payment
     * webhook and a billing sync sees it first starts the grace, and nothing
     * after that moves its end. Returns what it means for the plan (KEPT, PAUSED
     * or ENDED); the plan itself is the caller's to move.
     *
     * @param  Subscription  $plan  the member's latest plan row, on a paid plan when nothing was recorded yet
     * @param  object  $stripeSubscription  their own subscription, as Stripe returns it now
     * @param  object|null  $invoice  the failed invoice, when the webhook brought it
     *
     * @throws \Stripe\Exception\ApiErrorException when Stripe could not be read: nothing was recorded
     */
    public function begin(Subscription $plan, object $stripeSubscription, ?object $invoice = null): string
    {
        if ((string) $plan->billing_payment_state === 'past_due') {
            if (self::keeps($plan)) {
                $this->holdPaidPeriod($plan, $stripeSubscription, $invoice);
            }

            return self::outcome($plan);
        }

        $unpaidFrom = self::unpaidPeriodStart($stripeSubscription);
        $paidBefore = $this->paidBefore($stripeSubscription, $unpaidFrom);
        $paidPeriod = $paidBefore ? $this->paidPeriod($plan, $stripeSubscription, $unpaidFrom, $invoice) : null;

        if ($paidBefore && $paidPeriod === null) {
            Log::warning('A renewal payment failed, but which period was paid for could not be told, so the paid features pause at once instead of a grace.', [
                'user_id' => $plan->user_id,
                'stripe_subscription_id' => (string) ($stripeSubscription->id ?? ''),
            ]);
        }

        $changes = [
            'billing_payment_state' => 'past_due',
            'billing_grace_ends_at' => $paidPeriod !== null ? now()->addDays(self::graceDays()) : null,
            // A notice sent before was about an earlier missed payment.
            'billing_failed_notice_sent_at' => null,
        ] + ($paidPeriod ?? []);

        // Two events at once (the webhook and a sync) record it only once.
        $recorded = DB::transaction(function () use ($plan, $changes): bool {
            $row = Subscription::query()->whereKey($plan->getKey())->lockForUpdate()->first();
            if (! $row || (string) $row->billing_payment_state === 'past_due') {
                return false;
            }
            $row->forceFill($changes)->save();

            return true;
        });

        $fresh = $plan->fresh(['plan']);
        if ($fresh) {
            $plan->setRawAttributes($fresh->getAttributes(), true);
            $plan->setRelation('plan', $fresh->plan);
        }

        if ($recorded) {
            Log::info($paidPeriod !== null
                ? 'A renewal payment failed: the plan stays on until the grace ends.'
                : 'A payment on a plan never paid for failed: its paid features pause at once.', [
                    'user_id' => $plan->user_id,
                    'stripe_subscription_id' => (string) ($stripeSubscription->id ?? ''),
                    'grace_ends_at' => $plan->billing_grace_ends_at?->toIso8601String(),
                    'paid_period_end' => $plan->current_period_end?->toIso8601String(),
                ]);
        }

        return self::outcome($plan);
    }

    /**
     * The member's plan row as a billing sync finds it before reading Stripe:
     * its payment state and the end of its paid period. A webhook handled while
     * Stripe is read (a missed payment recorded or settled, a payment taken)
     * changes one of them, and makes what the sync read stale.
     *
     * @return array{state: ?string, period_end: ?int}
     */
    public static function rowBeforeRead(User $user): array
    {
        return self::rowState(Subscription::query()->where('user_id', $user->id)->latest('id')->first());
    }

    /**
     * For the billing sync, when Stripe says the member's subscription is
     * behind on payment: whether their plan stays exactly as it is (the grace
     * keeps it, or it ran out and waits for the grace job), or moves to Free.
     * A missed renewal seen here first is recorded, and the member warned.
     *
     * @param  array{state: ?string, period_end: ?int}|null  $rowBeforeRead  rowBeforeRead(), taken before Stripe was read
     *
     * @throws \Stripe\Exception\ApiErrorException when Stripe could not be read: nothing was recorded
     */
    public function forBillingSync(User $user, object $stripeSubscription, ?array $rowBeforeRead = null): bool
    {
        $plan = Subscription::query()->where('user_id', $user->id)->latest('id')->with('plan')->first();
        if (! $plan || (string) $plan->status !== 'active') {
            return false;
        }

        // Paid by hand, or the plan row changed while Stripe was being read (the
        // payment was taken or settled, or a webhook recorded the missed payment):
        // what was read can be from before that, so nothing is recorded and the
        // plan stays as it is. The next sync reads Stripe again.
        $state = (string) $plan->billing_payment_state;
        if ($state === 'manual_override' || ($rowBeforeRead !== null && $rowBeforeRead !== self::rowState($plan))) {
            return true;
        }

        $recordedBefore = $state === 'past_due';
        if (! $recordedBefore && ! self::isPaidPlan($plan)) {
            return false;
        }

        $invoice = null;
        if (! $recordedBefore) {
            // Only a renewal that could not be charged is a payment missing on the plan
            // the member has. Another invoice (a plan change or a first payment that
            // failed) leaves the plan exactly as it is, as the failed-payment webhook does.
            $invoice = $this->latestInvoice($stripeSubscription, null);
            if ($invoice === null || StripeInvoice::billingReason($invoice) !== 'subscription_cycle') {
                return true;
            }
        }

        $outcome = $this->begin($plan, $stripeSubscription, $invoice);
        if (! $recordedBefore) {
            $this->warn($user, $plan);
        }

        return self::isPaidPlan($plan) && in_array($outcome, [self::KEPT, self::ENDED], true);
    }

    /**
     * Stripe says the subscription is paid again: the missed payment is settled
     * (called once the plan has taken Stripe's paid period).
     */
    public function settle(User $user): void
    {
        Subscription::query()
            ->where('user_id', $user->id)
            ->where('billing_payment_state', 'past_due')
            ->update([
                'billing_payment_state' => 'ok',
                'billing_grace_ends_at' => null,
                'billing_failed_notice_sent_at' => null,
            ]);
    }

    /**
     * Warns the member of the missed payment, once (billing_failed_notice_sent_at
     * is set only once it was sent, so a later failure tries again). Never after
     * the grace ran out.
     */
    public function warn(User $user, Subscription $plan): void
    {
        if ((string) $plan->billing_payment_state !== 'past_due' || $plan->billing_failed_notice_sent_at !== null) {
            return;
        }

        $outcome = self::outcome($plan);
        if ($outcome === self::ENDED) {
            return;
        }

        $subject = 'Payment failed - action required';
        if ($outcome === self::KEPT) {
            $planName = (string) ($plan->plan?->name ?? 'your plan');
            $until = $plan->billing_grace_ends_at->format('M j, Y');
            $body = $this->mail->compose(
                $user,
                [
                    "We could not take your latest payment for {$planName}.",
                    "Your plan stays on until {$until}, with all its paid features.",
                    "Update your card and pay the open invoice before then to keep it. If it is still unpaid on {$until}, your subscription will be canceled and your account will move to the free plan.",
                ],
                [
                    'Plan' => $planName,
                    'Payment State' => 'Past due',
                    'Plan Active Until' => $until,
                ],
                'Update your payment method at '.$this->mail->manageBillingUrl()
            );
            $dedupeKey = "payment-failed:{$plan->id}:{$until}";
        } else {
            // Never paid for (a free trial's first charge): the paid features are paused now.
            $planName = self::isPaidPlan($plan) ? (string) ($plan->plan?->name ?? '') : '';
            $body = $this->mail->compose(
                $user,
                [
                    'We could not take your latest payment, so your paid features are paused.',
                    'Update your card and pay the open invoice to get them back straight away.',
                ],
                [
                    'Plan' => $planName,
                    'Payment State' => 'Past due',
                ],
                'Update your payment method at '.$this->mail->manageBillingUrl()
            );
            $dedupeKey = "payment-failed:{$plan->id}:paused:".now()->format('Y-m-d');
        }

        if ($this->mail->send($user, $subject, $body, $dedupeKey, 24 * 14)) {
            $plan->forceFill(['billing_failed_notice_sent_at' => now()])->save();
        }
    }

    /**
     * While the grace lasts nothing moves the plan's dates on to Stripe's unpaid
     * period; dates that were moved anyway are put back.
     */
    private function holdPaidPeriod(Subscription $plan, object $stripeSubscription, ?object $invoice): void
    {
        $changes = $this->paidPeriod($plan, $stripeSubscription, self::unpaidPeriodStart($stripeSubscription), $invoice);
        if ($changes === null) {
            Log::warning('A plan in its grace days has dates in the unpaid period, and the paid period could not be told.', [
                'user_id' => $plan->user_id,
                'stripe_subscription_id' => (string) ($stripeSubscription->id ?? ''),
            ]);

            return;
        }

        if ($changes !== []) {
            $plan->forceFill($changes)->save();
        }
    }

    /**
     * Whether the member has paid for this subscription before. Not when this is
     * the first charge after a free trial: the trial was never paid, or Stripe's
     * trial ended where the unpaid period starts.
     */
    private function paidBefore(object $stripeSubscription, ?Carbon $unpaidFrom): bool
    {
        $stripeId = trim((string) ($stripeSubscription->id ?? ''));
        if ($stripeId !== '' && BillingTrial::query()->where('stripe_subscription_id', $stripeId)->whereNull('paid_at')->exists()) {
            return false;
        }

        $trialEnd = (int) ($stripeSubscription->trial_end ?? 0);
        if ($trialEnd > 0) {
            // Without Stripe's period: a trial that ended less than a month ago
            // cannot have been followed by a paid month and its renewal yet.
            $firstChargeFrom = $unpaidFrom !== null
                ? $unpaidFrom->getTimestamp() - self::SAME_MOMENT_SECONDS
                : now()->subDays(28)->getTimestamp();
            if ($trialEnd >= $firstChargeFrom) {
                return false;
            }
        }

        return true;
    }

    /**
     * The plan's dates for the grace: the period last paid for. Nothing to change
     * when its own dates already end where the unpaid period starts (or Stripe
     * gave no period to compare with). When a billing sync had moved them on to
     * the unpaid month while the renewal was still being charged: the period
     * the failed renewal invoice looks back on, else the service period of the
     * last paid invoice. Null when neither tells it.
     *
     * @return array<string, Carbon>|null
     *
     * @throws \Stripe\Exception\ApiErrorException when an invoice could not be read
     */
    private function paidPeriod(Subscription $plan, object $stripeSubscription, ?Carbon $unpaidFrom, ?object $invoice): ?array
    {
        $end = $plan->current_period_end;
        if ($unpaidFrom === null || $end === null || $end->getTimestamp() <= $unpaidFrom->getTimestamp() + self::SAME_MOMENT_SECONDS) {
            return [];
        }

        $period = $this->lookBack($invoice, $unpaidFrom)
            ?? $this->lookBack($this->latestInvoice($stripeSubscription, $invoice), $unpaidFrom)
            ?? $this->lastPaidPeriod($stripeSubscription, $unpaidFrom);

        return $period === null ? null : [
            'current_period_start' => $period[0],
            'current_period_end' => $period[1],
        ];
    }

    /**
     * The service period of the subscription's last paid invoice that ends where
     * the unpaid period starts (its line for the plan): the period paid for.
     *
     * @return array{0: Carbon, 1: Carbon}|null
     *
     * @throws \Stripe\Exception\ApiErrorException
     */
    private function lastPaidPeriod(object $stripeSubscription, Carbon $unpaidFrom): ?array
    {
        $id = trim((string) ($stripeSubscription->id ?? ''));
        if ($id === '') {
            return null;
        }

        $paid = Cashier::stripe()->invoices->all(['subscription' => $id, 'status' => 'paid', 'limit' => 3]);
        foreach ($paid->data ?? [] as $invoice) {
            foreach ($invoice->lines->data ?? [] as $line) {
                $period = self::realPeriod((int) ($line->period->start ?? 0), (int) ($line->period->end ?? 0), $unpaidFrom);
                if ($period !== null) {
                    return $period;
                }
            }
        }

        return null;
    }

    /**
     * The billing period a renewal invoice looks back on (its period_start and
     * period_end: the period before the one it charges for), when it ends
     * where the unpaid period starts.
     *
     * @return array{0: Carbon, 1: Carbon}|null
     */
    private function lookBack(?object $invoice, Carbon $unpaidFrom): ?array
    {
        if ($invoice === null || StripeInvoice::billingReason($invoice) !== 'subscription_cycle') {
            return null;
        }

        return self::realPeriod((int) ($invoice->period_start ?? 0), (int) ($invoice->period_end ?? 0), $unpaidFrom);
    }

    /**
     * The billing period from $start to $end, when it ends where the unpaid period
     * starts and is a real one: from a day to a year and a few days.
     *
     * @return array{0: Carbon, 1: Carbon}|null
     */
    private static function realPeriod(int $start, int $end, Carbon $unpaidFrom): ?array
    {
        if ($start <= 0 || abs($end - $unpaidFrom->getTimestamp()) > self::SAME_MOMENT_SECONDS) {
            return null;
        }

        $length = $end - $start;
        if ($length < 86400 || $length > 370 * 86400) {
            return null;
        }

        return [Carbon::createFromTimestamp($start), Carbon::createFromTimestamp($end)];
    }

    /**
     * The subscription's latest invoice (the failed renewal while it is past
     * due), unless it is the one already at hand.
     *
     * @throws \Stripe\Exception\ApiErrorException
     */
    private function latestInvoice(object $stripeSubscription, ?object $known): ?object
    {
        $latest = $stripeSubscription->latest_invoice ?? null;
        if (is_object($latest)) {
            return $latest;
        }

        $id = trim((string) $latest);
        if ($id === '' || ($known !== null && $id === trim((string) ($known->id ?? '')))) {
            return null;
        }

        return Cashier::stripe()->invoices->retrieve($id, []);
    }

    /** When the period Stripe has not been paid for started (its current period). */
    private static function unpaidPeriodStart(object $stripeSubscription): ?Carbon
    {
        $start = (int) ($stripeSubscription->current_period_start ?? 0);
        if ($start <= 0) {
            foreach ($stripeSubscription->items->data ?? [] as $item) {
                $start = max($start, (int) ($item->current_period_start ?? 0));
            }
        }

        return $start > 0 ? Carbon::createFromTimestamp($start) : null;
    }

    /** @return array{state: ?string, period_end: ?int} */
    private static function rowState(?Subscription $row): array
    {
        return [
            'state' => $row?->billing_payment_state !== null ? (string) $row->billing_payment_state : null,
            'period_end' => $row?->current_period_end?->getTimestamp(),
        ];
    }

    /** Whether the row holds a paid plan (not the free plan, nor a pack). */
    public static function isPaidPlan(Subscription $plan): bool
    {
        $paid = $plan->plan;

        return $paid !== null && (int) $paid->price_cents > 0 && ! $paid->isPack();
    }

    /** How many days a failed renewal keeps the plan on (the site setting). */
    public static function graceDays(): int
    {
        return max(1, (int) SiteSetting::get('billing_grace_days', config('listen.billing.grace_days', 7)));
    }
}
