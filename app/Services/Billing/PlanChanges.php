<?php

namespace App\Services\Billing;

use App\Models\BillingSubscription;
use App\Models\ManualPaymentRequest;
use App\Models\Plan;
use App\Models\PlanChange;
use App\Models\Subscription;
use App\Models\User;
use App\Services\LocalizedPricingService;
use App\Services\PlanAccessService;
use App\Support\CreditPeriod;
use App\Support\DiscountTerms;
use App\Support\PlanPricingLines;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Cashier;
use Stripe\StripeClient;

/**
 * Upgrades now, downgrades at renewal.
 *
 * A move to a higher plan (or from monthly to yearly) is charged today
 * (BillingController::changePlanAndCharge). A move to a lower plan (or from yearly
 * to monthly) costs nothing today and refunds nothing: the member keeps the plan
 * they paid for, and its credits, until the paid period ends, and the lower plan
 * starts then.
 *
 * - On a card (source "card"), a Stripe subscription schedule keeps the current
 *   price until the renewal and switches to the lower price there. Every other change
 *   to that subscription (an upgrade, a cancellation, keeping the plan) lets the
 *   schedule go first: Stripe would otherwise put its phases back at the renewal.
 * - A plan paid by hand or given by the team that runs until a date: a lower plan
 *   paid by card starts that day (source "card_start", a schedule that creates the
 *   subscription then), one paid by InstaPay or Vodafone Cash too (source "manual",
 *   applied by applyDue()).
 *
 * An offer running on the lower plan when the member chose it is kept: a coupon of
 * their own (used once, with no end date), so the offer ending or its coupon being
 * deleted before the renewal changes nothing.
 */
final class PlanChanges
{
    public const UP = 'up';

    public const DOWN = 'down';

    public const SAME = 'same';

    public function __construct(
        private readonly PlanAccessService $planAccess,
        private readonly StripePricePlans $stripePrices,
        private readonly LocalizedPricingService $pricing,
    ) {}

    /**
     * Whether a move is an upgrade (paid today), a downgrade (at the end of the paid
     * period) or no change. Plans rank by their monthly price, as on the pricing page;
     * on the same plan, yearly ranks above monthly.
     */
    public static function direction(?Plan $from, ?string $fromCycle, Plan $to, string $toCycle): string
    {
        if ($from === null || (int) $from->price_cents <= 0 || $from->isPack()) {
            return self::UP;
        }

        if ((int) $from->id !== (int) $to->id) {
            return (int) $to->price_cents < (int) $from->price_cents ? self::DOWN : self::UP;
        }

        if (($fromCycle ?: 'monthly') === $toCycle) {
            return self::SAME;
        }

        return $toCycle === 'yearly' ? self::UP : self::DOWN;
    }

    public function scheduledFor(User|int $user): ?PlanChange
    {
        return PlanChange::query()
            ->where('user_id', $user instanceof User ? (int) $user->id : $user)
            ->scheduled()
            ->latest('id')
            ->with(['toPlan', 'fromPlan'])
            ->first();
    }

    /**
     * The cycle the member pays for now: their card subscription's, or the one of the
     * payment by hand that set their plan; monthly otherwise.
     */
    public function currentCycle(User $user, ?BillingSubscription $subscription = null): string
    {
        $subscription ??= BillingSubscription::query()->where('user_id', $user->id)->where('type', 'default')->latest('id')->first();
        if ($subscription && $subscription->valid()) {
            $price = trim((string) $subscription->stripe_price) ?: trim((string) ($subscription->items()->latest('id')->value('stripe_price') ?? ''));
            $cycle = $price !== '' ? ($this->stripePrices->planFor($price)['cycle'] ?? null) : null;
            if ($cycle !== null) {
                return $cycle === 'yearly' ? 'yearly' : 'monthly';
            }
        }

        $row = Subscription::query()->where('user_id', $user->id)->latest('id')->first();
        $cycle = $row ? ManualPaymentRequest::query()
            ->where('user_id', $user->id)
            ->where('status', ManualPaymentRequest::STATUS_APPROVED)
            ->where('approved_subscription_id', $row->id)
            ->where('plan_id', $row->plan_id)
            ->latest('id')
            ->value('billing_cycle') : null;

        return $cycle === 'yearly' ? 'yearly' : 'monthly';
    }

    /**
     * When the member's plan ends, when it was paid by hand or given by the team and
     * still runs: a lower plan starts that day. Null for a card plan (its renewal
     * decides), the free plan, a plan with no end, or one already over.
     */
    public function heldUntil(User $user): ?Carbon
    {
        $card = BillingSubscription::query()->where('user_id', $user->id)->where('type', 'default')->latest('id')->first();
        if ($card && $card->valid()) {
            return null;
        }

        $row = Subscription::query()->where('user_id', $user->id)->latest('id')->with('plan')->first();
        $end = $row?->current_period_end;
        if (! $row || (string) $row->status !== 'active' || $end === null || $end->lte(now())
            || ! $row->plan || (int) $row->plan->price_cents <= 0 || $row->plan->isPack()) {
            return null;
        }

        return Carbon::instance($end);
    }

    /**
     * A card member's lower plan, from their renewal: the schedule keeps today's price
     * until then and switches to the lower one there. Nothing is charged now.
     */
    public function scheduleOnSubscription(User $user, BillingSubscription $subscription, ?Plan $from, Plan $to, string $cycle, string $priceId, string $paymentMethod = ''): PlanChange
    {
        $stripe = Cashier::stripe();
        $stripeId = (string) $subscription->stripe_id;
        $live = $stripe->subscriptions->retrieve($stripeId, []);
        $existing = $this->scheduleIdOf($live);
        $schedule = $existing !== null
            ? $stripe->subscriptionSchedules->retrieve($existing, [])
            : $stripe->subscriptionSchedules->create(['from_subscription' => $stripeId]);

        $current = $this->currentPhase($schedule);
        if ($current === null) {
            if ($existing === null) {
                rescue(fn () => $stripe->subscriptionSchedules->release((string) $schedule->id, []), null, false);
            }
            throw new \RuntimeException('The subscription schedule has no current phase.');
        }

        $offer = $this->offerFor($to, $cycle);
        $couponId = null;
        // The renewal is charged to the card the subscription uses now (or the one just given).
        $card = $paymentMethod !== '' ? $paymentMethod : $this->idOf($live->default_payment_method ?? null);
        try {
            $couponId = $offer !== null ? $this->keptOfferCoupon($stripe, $user, $to, $offer) : null;
            $stripe->subscriptionSchedules->update((string) $schedule->id, [
                'end_behavior' => 'release',
                'phases' => [$current, $this->phaseFor($to, $cycle, $priceId, $couponId, $card)],
                'metadata' => ['listen_user_id' => (string) $user->id, 'listen_change' => 'downgrade'],
            ]);
        } catch (\Throwable $exception) {
            // Nothing half done: the subscription goes on as it was.
            if ($couponId !== null) {
                rescue(fn () => $stripe->coupons->delete($couponId), null, false);
            }
            if ($existing === null) {
                rescue(fn () => $stripe->subscriptionSchedules->release((string) $schedule->id, []), null, false);
            }
            throw $exception;
        }

        return $this->record($user, [
            'from_plan_id' => $from?->id,
            'to_plan_id' => $to->id,
            'billing_cycle' => $cycle,
            'source' => PlanChange::SOURCE_CARD,
            'starts_at' => Carbon::createFromTimestamp((int) $current['end_date']),
            'stripe_subscription_id' => $stripeId,
            'stripe_schedule_id' => (string) $schedule->id,
            'stripe_price_id' => $priceId,
            'stripe_coupon_id' => $couponId,
        ] + $this->amounts($to, $cycle, $offer));
    }

    /**
     * A member whose plan was paid by hand or given by the team chose a lower plan by
     * card: it starts on its own the day the current plan ends, and the card is
     * charged then (Stripe creates the subscription that day).
     */
    public function scheduleCardStart(User $user, ?Plan $from, Plan $to, string $cycle, string $priceId, Carbon $startsAt, string $paymentMethod): PlanChange
    {
        $stripe = Cashier::stripe();
        $offer = $this->offerFor($to, $cycle);
        $couponId = $offer !== null ? $this->keptOfferCoupon($stripe, $user, $to, $offer) : null;

        try {
            $schedule = $stripe->subscriptionSchedules->create([
                'customer' => (string) $user->stripe_id,
                'start_date' => $startsAt->getTimestamp(),
                'end_behavior' => 'release',
                // The same billing as every subscription Cashier creates.
                'billing_mode' => ['type' => 'classic'],
                'default_settings' => [
                    'default_payment_method' => $paymentMethod,
                    'collection_method' => 'charge_automatically',
                ],
                'phases' => [$this->phaseFor($to, $cycle, $priceId, $couponId)],
                'metadata' => ['listen_user_id' => (string) $user->id, 'listen_change' => 'downgrade'],
            ]);
        } catch (\Throwable $exception) {
            if ($couponId !== null) {
                rescue(fn () => $stripe->coupons->delete($couponId), null, false);
            }
            throw $exception;
        }

        return $this->record($user, [
            'from_plan_id' => $from?->id,
            'to_plan_id' => $to->id,
            'billing_cycle' => $cycle,
            'source' => PlanChange::SOURCE_CARD_START,
            'starts_at' => $startsAt,
            'stripe_schedule_id' => (string) $schedule->id,
            'stripe_price_id' => $priceId,
            'stripe_coupon_id' => $couponId,
        ] + $this->amounts($to, $cycle, $offer));
    }

    /**
     * An InstaPay or Vodafone Cash payment for a lower plan, approved while the current
     * plan still runs: the lower plan starts the day it ends (applyDue()), for the
     * period paid for.
     */
    public function queueManual(User $user, ?Plan $from, Plan $to, string $cycle, Carbon $startsAt, ManualPaymentRequest $request): PlanChange
    {
        return $this->record($user, [
            'from_plan_id' => $from?->id,
            'to_plan_id' => $to->id,
            'billing_cycle' => $cycle,
            'source' => PlanChange::SOURCE_MANUAL,
            'starts_at' => $startsAt,
            'period_ends_at' => $cycle === 'yearly' ? $startsAt->copy()->addYear() : $startsAt->copy()->addMonth(),
            'manual_payment_request_id' => $request->id,
        ] + $this->amounts($to, $cycle, null));
    }

    /**
     * The member keeps their plan: the change waiting for the renewal is called off
     * (on a card, the schedule lets the subscription go on as it is). A change paid by
     * hand stays: it is already paid for. The change called off, or null.
     */
    public function cancel(User $user, string $reason = 'kept'): ?PlanChange
    {
        $change = $this->scheduledFor($user);
        if ($change === null || $change->source === PlanChange::SOURCE_MANUAL) {
            return null;
        }

        $this->letGoInStripe($change);

        return $this->markCancelled($change, $reason);
    }

    /** The Stripe side of a change called off: its schedule let go (card) or never started (card_start). */
    private function letGoInStripe(PlanChange $change): void
    {
        $stripe = Cashier::stripe();
        try {
            if ($change->source === PlanChange::SOURCE_CARD && $change->stripe_subscription_id) {
                $scheduleId = $this->scheduleIdOf($stripe->subscriptions->retrieve((string) $change->stripe_subscription_id, []));
                if ($scheduleId !== null) {
                    $stripe->subscriptionSchedules->release($scheduleId, []);
                }
            } elseif ($change->source === PlanChange::SOURCE_CARD_START && $change->stripe_schedule_id) {
                $schedule = $stripe->subscriptionSchedules->retrieve((string) $change->stripe_schedule_id, []);
                if ((string) ($schedule->status ?? '') === 'not_started') {
                    $stripe->subscriptionSchedules->cancel((string) $schedule->id, []);
                }
            }
        } catch (\Stripe\Exception\InvalidRequestException $exception) {
            // Stripe no longer has it: there is nothing left to let go.
            if ((string) $exception->getStripeCode() !== 'resource_missing') {
                throw $exception;
            }
        }
    }

    /**
     * A newer decision (a plan bought or paid by hand now): whatever lower plan was
     * waiting, of any kind, gives way. The change called off, or null.
     */
    public function supersede(User $user, string $reason): ?PlanChange
    {
        $change = $this->scheduledFor($user);
        if ($change === null) {
            return null;
        }
        if ($change->source !== PlanChange::SOURCE_MANUAL) {
            $this->letGoInStripe($change);
        }

        return $this->markCancelled($change, $reason);
    }

    /**
     * Before any other change to a card subscription (an upgrade, a cancellation):
     * a schedule on it is let go, so its phases never come back at the renewal, and
     * the change it carried is called off. Takes the subscription as Stripe has it.
     */
    public function releaseScheduleOf(object $liveSubscription, int $userId, string $reason): void
    {
        $scheduleId = $this->scheduleIdOf($liveSubscription);
        if ($scheduleId !== null) {
            Cashier::stripe()->subscriptionSchedules->release($scheduleId, []);
        }

        $change = PlanChange::query()->scheduled()
            ->where('user_id', $userId)
            ->where('source', PlanChange::SOURCE_CARD)
            ->latest('id')
            ->first();
        if ($change !== null) {
            $this->markCancelled($change, $reason);
        }
    }

    /** releaseScheduleOf() for our copy of the subscription: Stripe is asked for its schedule first. */
    public function releaseScheduleFor(BillingSubscription $subscription, string $reason): void
    {
        $live = Cashier::stripe()->subscriptions->retrieve((string) $subscription->stripe_id, []);
        $this->releaseScheduleOf($live, (int) $subscription->user_id, $reason);
    }

    /**
     * What Stripe says now (BillingSyncService): a card change that took effect at the
     * renewal is marked done and its schedule let go; one whose subscription ended, or
     * whose schedule Stripe let go before the renewal, is called off.
     */
    public function reconcile(User $user, ?object $stripeSubscription): void
    {
        $change = $this->scheduledFor($user);
        if ($change === null || $change->source !== PlanChange::SOURCE_CARD) {
            return;
        }

        $sameSubscription = $stripeSubscription !== null && (string) ($stripeSubscription->id ?? '') === (string) $change->stripe_subscription_id;
        $status = $sameSubscription ? (string) ($stripeSubscription->status ?? '') : '';
        if (! $sameSubscription || in_array($status, ['canceled', 'incomplete_expired'], true)) {
            $this->markCancelled($change, 'subscription_ended');

            return;
        }

        $item = $stripeSubscription->items->data[0] ?? null;
        $price = is_object($item?->price ?? null) ? (string) $item->price->id : (string) ($item->price ?? '');
        if ($price !== '' && $price === (string) $change->stripe_price_id) {
            $change->forceFill(['status' => PlanChange::STATUS_APPLIED, 'applied_at' => now()])->save();
            // Its work is done: the subscription goes on by itself from here.
            $scheduleId = $this->scheduleIdOf($stripeSubscription);
            if ($scheduleId !== null) {
                rescue(fn () => Cashier::stripe()->subscriptionSchedules->release($scheduleId, []), null, false);
            }

            return;
        }

        if ($this->scheduleIdOf($stripeSubscription) === null) {
            // Let go in Stripe before the renewal (from its dashboard, for example).
            $this->markCancelled($change, 'released_in_stripe');
        }
    }

    /**
     * A card plan booked to start when the plan paid by hand ended: once Stripe has
     * started it, the hand-set plan steps aside so the card subscription is read
     * (BillingSyncService skips a member whose plan is set by hand). True when a card
     * plan took over.
     */
    public function startDueCardPlans(User $user): bool
    {
        $change = PlanChange::query()->scheduled()
            ->where('user_id', $user->id)
            ->where('source', PlanChange::SOURCE_CARD_START)
            ->where('starts_at', '<=', now()->addMinutes(5))
            ->latest('id')
            ->first();
        if ($change === null || ! $change->stripe_schedule_id) {
            return false;
        }

        $stripe = Cashier::stripe();
        $schedule = $stripe->subscriptionSchedules->retrieve((string) $change->stripe_schedule_id, []);
        $subscriptionId = is_object($schedule->subscription ?? null)
            ? (string) $schedule->subscription->id
            : (string) ($schedule->subscription ?? $schedule->released_subscription ?? '');

        if ($subscriptionId === '') {
            if (in_array((string) ($schedule->status ?? ''), ['canceled', 'released', 'completed'], true)) {
                $this->markCancelled($change, 'cancelled_in_stripe');
            }

            return false;
        }

        Subscription::query()->where('user_id', $user->id)->where('billing_payment_state', 'manual_override')
            ->update(['billing_payment_state' => 'ok']);
        $change->forceFill([
            'status' => PlanChange::STATUS_APPLIED,
            'applied_at' => now(),
            'stripe_subscription_id' => $subscriptionId,
        ])->save();
        if ((string) ($schedule->status ?? '') === 'active') {
            rescue(fn () => $stripe->subscriptionSchedules->release((string) $schedule->id, []), null, false);
        }

        return true;
    }

    /**
     * Lower plans paid by hand whose day has come take over (the scheduler runs this
     * every minute; a member's own visit runs it too). The number applied.
     */
    public function applyDue(?int $userId = null): int
    {
        $applied = 0;
        PlanChange::query()->scheduled()
            ->where('source', PlanChange::SOURCE_MANUAL)
            ->where('starts_at', '<=', now())
            ->when($userId !== null, fn ($query) => $query->where('user_id', $userId))
            ->orderBy('id')
            ->get()
            ->each(function (PlanChange $change) use (&$applied): void {
                $applied += $this->applyManual($change) ? 1 : 0;
            });

        return $applied;
    }

    private function applyManual(PlanChange $change): bool
    {
        return DB::transaction(function () use ($change): bool {
            $locked = PlanChange::query()->whereKey($change->id)->lockForUpdate()->with(['user', 'toPlan'])->first();
            if (! $locked || ! $locked->isScheduled() || ! $locked->user || ! $locked->toPlan) {
                return false;
            }

            // A card plan bought since then runs instead: its renewals decide now.
            $card = BillingSubscription::query()->where('user_id', $locked->user_id)->where('type', 'default')->latest('id')->first();
            if ($card && $card->valid()) {
                $this->markCancelled($locked, 'card_plan');

                return false;
            }

            // The plan it was to follow was extended since (another payment, the team):
            // that decision is the newer one.
            // (The free plan a login sets once a plan has ended is no such decision.)
            $row = Subscription::query()->where('user_id', $locked->user_id)->latest('id')->with('plan')->first();
            if ($row && $row->current_period_end && $row->current_period_end->gt($locked->starts_at->copy()->addMinute())
                && (string) $row->status === 'active' && $row->plan && (int) $row->plan->price_cents > 0) {
                $this->markCancelled($locked, 'superseded');

                return false;
            }

            // Paid in full for a period of its own: fresh credits from its first day.
            $subscription = CreditPeriod::startingAfresh(fn (): Subscription => $this->planAccess->assignPlan(
                $locked->user,
                $locked->toPlan,
                'active',
                $locked->period_ends_at,
                $locked->starts_at,
            ));
            $subscription->forceFill(['billing_payment_state' => 'manual_override'])->save();

            if ($locked->manual_payment_request_id) {
                ManualPaymentRequest::query()->whereKey($locked->manual_payment_request_id)
                    ->update(['approved_subscription_id' => $subscription->id]);
            }
            $locked->forceFill(['status' => PlanChange::STATUS_APPLIED, 'applied_at' => now()])->save();

            return true;
        });
    }

    /** A schedule Stripe ended (released, cancelled or done) by itself: its change is settled. */
    public function scheduleEnded(object $schedule): void
    {
        $change = PlanChange::query()->scheduled()->where('stripe_schedule_id', (string) ($schedule->id ?? ''))->first();
        if ($change === null) {
            return;
        }

        $user = $change->user;
        if ($change->source === PlanChange::SOURCE_CARD_START && $user) {
            $this->startDueCardPlans($user) || $this->markCancelled($change, 'cancelled_in_stripe');

            return;
        }
        // A card change: the sync that follows the webhook reads the subscription and settles it.
    }

    /**
     * The scheduled change in the reader's words and money, for the billing, pricing
     * and Manage Subscription pages.
     *
     * @return array{id:int, plan:string, plan_slug:string, cycle:string, date:string, starts_at:string, current:string, price:string, first:?string, terms:string, can_keep:bool, source:string}
     */
    public function describe(PlanChange $change, array $countryContext, string $locale): array
    {
        $plan = $change->toPlan;
        $price = $this->money((int) $change->amount_cents, $change->billing_cycle, $countryContext, $locale);
        $first = $change->first_amount_cents !== null
            ? $this->money((int) $change->first_amount_cents, $change->billing_cycle, $countryContext, $locale)
            : null;
        $per = __($change->billing_cycle === 'yearly' ? 'ui.plan_change.per_year' : 'ui.plan_change.per_month', ['price' => $this->isolate($price)], $locale);
        $discount = is_array($change->discount) ? $change->discount : null;
        $terms = $change->source === PlanChange::SOURCE_MANUAL
            ? __('ui.plan_change.already_paid', [], $locale)
            : ($plan ? $this->termsWith($plan, $change->billing_cycle, $price, $first !== null ? $discount : null, $locale) : $per);

        return [
            'id' => (int) $change->id,
            'plan' => $plan ? ($plan->getLocalizedName($locale) ?: (string) $plan->name) : '',
            'plan_slug' => (string) ($plan?->slug ?? ''),
            'cycle' => (string) $change->billing_cycle,
            'date' => $this->date($change->starts_at, $locale),
            'starts_at' => $change->starts_at->toIso8601String(),
            'current' => $change->fromPlan ? ($change->fromPlan->getLocalizedName($locale) ?: (string) $change->fromPlan->name) : '',
            'price' => $price,
            'first' => $first,
            'terms' => $terms,
            'can_keep' => $change->canBeKept(),
            'source' => (string) $change->source,
            'changes' => $plan ? $this->differences($change->fromPlan, $plan, $locale) : [],
        ];
    }

    /**
     * The price as the member sees it now, in English and Arabic, kept with the change so
     * the emails that follow say exactly what the page said.
     */
    public function rememberTerms(PlanChange $change, array $countryContext): void
    {
        $change->loadMissing(['toPlan', 'fromPlan']);
        $terms = [];
        foreach (['en', 'ar'] as $locale) {
            $line = $this->describe($change, $countryContext, $locale);
            $terms[$locale] = ['terms' => $line['terms'], 'price' => $line['price'], 'first' => $line['first']];
        }
        $change->forceFill(['terms' => $terms])->save();
    }

    /**
     * What changes when the lower plan starts: the plan comparison's rows whose values
     * differ, in the reader's language ("Audio history: 90 days → 30 days").
     *
     * @return list<array{label:string, from:string, to:string}>
     */
    public function differences(?Plan $from, Plan $to, string $locale): array
    {
        if ($from === null || (int) $from->id === (int) $to->id) {
            return [];
        }

        $rows = static function (Plan $plan) use ($locale): array {
            $byKey = [];
            foreach (PlanPricingLines::forComparison($plan, $locale) as $line) {
                $label = trim((string) ($line['label'] ?? ''));
                if ($label !== '') {
                    $byKey[PlanPricingLines::labelKey($label)] = ['label' => $label, 'value' => trim((string) ($line['value'] ?? ''))];
                }
            }

            return $byKey;
        };
        $before = $rows($from);
        $after = $rows($to);
        $none = __('ui.plan_change.not_included', [], $locale);

        $changes = [];
        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $key) {
            $was = $before[$key]['value'] ?? '';
            $will = $after[$key]['value'] ?? '';
            if ($was === $will) {
                continue;
            }
            $changes[] = [
                'label' => $before[$key]['label'] ?? $after[$key]['label'],
                'from' => $was !== '' ? $was : $none,
                'to' => $will !== '' ? $will : $none,
            ];
        }

        return $changes;
    }

    /**
     * A lower plan booked for this renewal whose first payment failed: the grace days are
     * on the plan being renewed (the lower one), with the dates and credits of the period
     * paid for, never a fresh allowance. BillingSyncService calls it when the grace keeps
     * the plan. True when the plan was moved.
     */
    public function graceOnRenewedPlan(User $user, Plan $renewed, object $stripeSubscription): bool
    {
        $change = PlanChange::query()
            ->where('user_id', $user->id)
            ->where('source', PlanChange::SOURCE_CARD)
            ->where('stripe_subscription_id', (string) ($stripeSubscription->id ?? ''))
            ->where('to_plan_id', $renewed->id)
            ->whereIn('status', [PlanChange::STATUS_SCHEDULED, PlanChange::STATUS_APPLIED])
            ->where('starts_at', '<=', now()->addMinutes(5))
            ->latest('id')
            ->first();
        $row = Subscription::query()->where('user_id', $user->id)->latest('id')->first();
        if ($change === null || $row === null || (int) $row->plan_id === (int) $renewed->id) {
            return false;
        }

        $this->planAccess->assignPlan($user, $renewed, (string) $row->status, $row->current_period_end, $row->current_period_start);
        if ($change->isScheduled()) {
            $change->forceFill(['status' => PlanChange::STATUS_APPLIED, 'applied_at' => now()])->save();
        }

        return true;
    }

    /**
     * What a plan costs from its first payment, in the reader's money: "EGP 112 a
     * month", or with an offer running now "50% off your first month, then EGP
     * 112/month." (the plan cards' words).
     */
    public function terms(Plan $plan, string $cycle, array $countryContext, string $locale): string
    {
        $full = $this->amounts($plan, $cycle, null)['amount_cents'];

        return $this->termsWith($plan, $cycle, $this->money($full, $cycle, $countryContext, $locale), $this->offerFor($plan, $cycle), $locale);
    }

    /** @param  array{percent:int, duration:string, months:?int, plan_duration:string}|null  $offer */
    private function termsWith(Plan $plan, string $cycle, string $price, ?array $offer, string $locale): string
    {
        if ($offer === null) {
            return __($cycle === 'yearly' ? 'ui.plan_change.per_year' : 'ui.plan_change.per_month', ['price' => $this->isolate($price)], $locale);
        }

        // The offer as it was when the member chose it, in the same words as the plan cards.
        $kept = (clone $plan)->forceFill([
            'discount_duration' => $offer['plan_duration'] ?? Plan::DISCOUNT_FIRST_PAYMENT,
            'discount_duration_months' => $offer['months'] ?? null,
        ]);
        $previous = app()->getLocale();
        app()->setLocale($locale);
        try {
            // DiscountTerms keeps the price in its own direction itself.
            return DiscountTerms::line($kept, $cycle, (int) $offer['percent'], $price);
        } finally {
            app()->setLocale($previous);
        }
    }

    /** "30 Nov 2026" in the reader's language. */
    public function date(\DateTimeInterface $date, string $locale): string
    {
        return Carbon::instance($date)->locale($locale)->translatedFormat('j M Y');
    }

    /**
     * The offer on a plan when the member chooses it, for monthly billing only (a
     * yearly plan keeps its yearly saving instead), or null.
     *
     * @return array{percent:int, duration:string, months:?int, plan_duration:string}|null
     */
    public function offerFor(Plan $plan, string $cycle): ?array
    {
        if ($cycle === 'yearly' || ! $plan->hasActiveDiscount()) {
            return null;
        }

        $stripe = $plan->stripeCouponDuration();

        return [
            'percent' => max(1, min(99, (int) $plan->discount_percentage)),
            'duration' => (string) $stripe['duration'],
            'months' => isset($stripe['duration_in_months']) ? (int) $stripe['duration_in_months'] : null,
            'plan_duration' => $plan->discountDuration(),
        ];
    }

    /**
     * @param  array{percent:int, duration:string, months:?int, plan_duration:string}|null  $offer
     * @return array{amount_cents:int, first_amount_cents:?int, discount:?array}
     */
    private function amounts(Plan $plan, string $cycle, ?array $offer): array
    {
        $full = $cycle === 'yearly' ? $plan->getEffectiveYearlyPriceCents() : (int) $plan->price_cents;

        return [
            'amount_cents' => max(0, $full),
            'first_amount_cents' => $offer !== null ? (int) round($full * (100 - $offer['percent']) / 100) : null,
            'discount' => $offer,
        ];
    }

    private function money(int $usdCents, string $cycle, array $countryContext, string $locale): string
    {
        return (string) $this->pricing->convertedQuoteFromUsd($usdCents, $cycle, $countryContext, 'stripe', $locale)['amount_formatted'];
    }

    private function isolate(string $text): string
    {
        return "\u{2066}".$text."\u{2069}";
    }

    /**
     * The offer kept for this member: a coupon of their own, used once, with no end
     * date. The plan's own coupon ends with the offer and is deleted then.
     *
     * @param  array{percent:int, duration:string, months:?int, plan_duration:string}  $offer
     */
    private function keptOfferCoupon(StripeClient $stripe, User $user, Plan $plan, array $offer): string
    {
        $coupon = $stripe->coupons->create(array_filter([
            'percent_off' => (float) $offer['percent'],
            'duration' => $offer['duration'],
            'duration_in_months' => $offer['duration'] === 'repeating' ? $offer['months'] : null,
            'max_redemptions' => 1,
            'name' => mb_substr($plan->name.' - '.$offer['percent'].'% Off', 0, 40),
            'metadata' => [
                'listen_plan_slug' => (string) $plan->slug,
                'listen_plan_id' => (string) $plan->id,
                'listen_kept_for_user' => (string) $user->id,
            ],
        ], fn ($value) => $value !== null));

        return (string) $coupon->id;
    }

    /** @return array<string, mixed> */
    private function phaseFor(Plan $plan, string $cycle, string $priceId, ?string $couponId, string $paymentMethod = ''): array
    {
        return [
            'items' => [['price' => $priceId, 'quantity' => 1]],
            'duration' => ['interval' => $cycle === 'yearly' ? 'year' : 'month', 'interval_count' => 1],
            'proration_behavior' => 'none',
            // Entering the phase writes these onto the subscription.
            'metadata' => ['listen_plan_slug' => (string) $plan->slug, 'listen_billing_cycle' => $cycle],
        ] + ($couponId !== null ? ['discounts' => [['coupon' => $couponId]]] : [])
            + ($paymentMethod !== '' ? ['default_payment_method' => $paymentMethod] : []);
    }

    private function idOf(mixed $value): string
    {
        return is_object($value) ? (string) ($value->id ?? '') : (string) ($value ?? '');
    }

    /**
     * The schedule's phase running now, as update parameters: Stripe unsets whatever
     * an update leaves out, so everything it carries is passed back.
     *
     * @return array<string, mixed>|null
     */
    private function currentPhase(object $schedule): ?array
    {
        $now = time();
        $start = (int) ($schedule->current_phase->start_date ?? 0);
        foreach ($schedule->phases ?? [] as $phase) {
            $begins = (int) ($phase->start_date ?? 0);
            $ends = (int) ($phase->end_date ?? 0);
            if (($start > 0 && $begins === $start) || ($start === 0 && $begins <= $now && $ends > $now)) {
                return $this->phaseParams($phase);
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function phaseParams(object $phase): array
    {
        $id = static fn ($value) => is_object($value) ? (string) ($value->id ?? '') : (string) ($value ?? '');

        $items = [];
        foreach ($phase->items ?? [] as $item) {
            $entry = ['price' => $id($item->price ?? null), 'quantity' => (int) ($item->quantity ?? 1)];
            $taxRates = array_values(array_filter(array_map($id, (array) ($item->tax_rates ?? []))));
            if ($taxRates !== []) {
                $entry['tax_rates'] = $taxRates;
            }
            $items[] = $entry;
        }

        $params = [
            'items' => $items,
            'start_date' => (int) $phase->start_date,
            'end_date' => (int) $phase->end_date,
        ];

        $discounts = [];
        foreach ($phase->discounts ?? [] as $discount) {
            foreach (['discount', 'coupon', 'promotion_code'] as $key) {
                $value = $id($discount->{$key} ?? null);
                if ($value !== '') {
                    $discounts[] = [$key => $value];
                    break;
                }
            }
        }
        if ($discounts !== []) {
            $params['discounts'] = $discounts;
        }

        $metadata = isset($phase->metadata) && is_object($phase->metadata) && method_exists($phase->metadata, 'toArray')
            ? $phase->metadata->toArray()
            : (array) ($phase->metadata ?? []);
        if ($metadata !== []) {
            $params['metadata'] = $metadata;
        }

        foreach (['proration_behavior', 'billing_cycle_anchor', 'collection_method', 'currency', 'trial_end'] as $key) {
            $value = $phase->{$key} ?? null;
            if (is_scalar($value) && $value !== '') {
                $params[$key] = $value;
            }
        }
        $paymentMethod = $id($phase->default_payment_method ?? null);
        if ($paymentMethod !== '') {
            $params['default_payment_method'] = $paymentMethod;
        }
        $taxRates = array_values(array_filter(array_map($id, (array) ($phase->default_tax_rates ?? []))));
        if ($taxRates !== []) {
            $params['default_tax_rates'] = $taxRates;
        }
        if (! empty($phase->automatic_tax->enabled)) {
            $params['automatic_tax'] = ['enabled' => true];
        }

        return $params;
    }

    private function scheduleIdOf(object $subscription): ?string
    {
        $schedule = $subscription->schedule ?? null;
        $id = is_object($schedule) ? (string) ($schedule->id ?? '') : (string) ($schedule ?? '');

        return $id !== '' ? $id : null;
    }

    /** A new scheduled change; any earlier one is replaced (on a card, its schedule was updated in place). */
    private function record(User $user, array $attributes): PlanChange
    {
        return DB::transaction(function () use ($user, $attributes): PlanChange {
            PlanChange::query()->scheduled()->where('user_id', $user->id)->get()
                ->each(function (PlanChange $earlier) use ($attributes): void {
                    // A card change keeps its schedule (it was just updated in place); a card
                    // plan booked to start later is a schedule of its own, called off here.
                    if ($earlier->source === PlanChange::SOURCE_CARD_START
                        && (string) $earlier->stripe_schedule_id !== (string) ($attributes['stripe_schedule_id'] ?? '')) {
                        $this->letGoInStripe($earlier);
                    }
                    $this->markCancelled($earlier, 'replaced', $attributes['stripe_coupon_id'] ?? null);
                });

            return PlanChange::query()->create($attributes + ['user_id' => $user->id, 'status' => PlanChange::STATUS_SCHEDULED]);
        });
    }

    private function markCancelled(PlanChange $change, string $reason, ?string $keepCoupon = null): PlanChange
    {
        $change->forceFill([
            'status' => PlanChange::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancel_reason' => mb_substr($reason, 0, 40),
        ])->save();

        // Its kept offer was never used: no coupon is left behind.
        $coupon = (string) $change->stripe_coupon_id;
        if ($coupon !== '' && $coupon !== $keepCoupon) {
            try {
                Cashier::stripe()->coupons->delete($coupon);
            } catch (\Throwable $exception) {
                Log::info('A kept-offer coupon could not be deleted.', ['coupon' => $coupon, 'error' => $exception->getMessage()]);
            }
        }

        return $change;
    }
}
