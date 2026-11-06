<?php

namespace App\Services\Billing;

use App\Models\BillingSubscription;
use App\Models\ManualPaymentRequest;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Moving up to a higher plan by hand (InstaPay, Vodafone Cash) works as a card
 * upgrade does: the higher plan starts the moment the payment is approved, with a
 * month (or year) of its own from then, and its price is less what is left of the
 * plan already paid by hand. The credits used so far carry on, as on a card
 * (App\Support\CreditPeriod::carryOverPlanChange()).
 *
 * Only a plan paid by hand earns the deduction: the free plan or a plan given by
 * the team has nothing paid to give back. Paying for the same plan again (monthly
 * or yearly) is a renewal, not an upgrade: that payment follows on after the period
 * already paid for (ManualPaymentService).
 */
final class ManualUpgrade
{
    public function __construct(private readonly PlanChanges $planChanges) {}

    /**
     * The deduction for moving up to $to by hand now, or null when this payment is
     * not an upgrade from a plan paid by hand.
     *
     * Counted in whole days and rounded down to a whole unit of the currency, so the
     * amount the member is shown stays the same while they make the transfer.
     *
     * @return array{credit_minor:int, value_minor:int, days_left:int, days_total:int, from_plan_id:int, from_subscription_id:int}|null
     */
    public function credit(User $user, Plan $to, string $cycle, string $currency, ?Carbon $at = null): ?array
    {
        $at = Carbon::instance($at ?? now());
        if ($to->isPack() || (int) $to->price_cents <= 0) {
            return null;
        }

        // A card plan is changed on the card (BillingController), never by hand.
        $card = BillingSubscription::query()->where('user_id', $user->id)->where('type', 'default')->latest('id')->first();
        if ($card && $card->valid()) {
            return null;
        }

        $current = Subscription::query()->where('user_id', $user->id)->latest('id')->with('plan')->first();
        $from = $current?->plan;
        $start = $current?->current_period_start;
        $end = $current?->current_period_end;
        if (! $current || ! $from || (string) $current->status !== 'active'
            || (string) $current->billing_payment_state !== 'manual_override'
            || $start === null || $end === null || $end->lte($at) || $start->gte($end)
            || (int) $from->id === (int) $to->id
            || PlanChanges::direction($from, $this->planChanges->currentCycle($user), $to, $cycle === 'yearly' ? 'yearly' : 'monthly') !== PlanChanges::UP) {
            return null;
        }

        // What was paid by hand for the period that runs now: a renewal paid early runs
        // on in the same period, so it counts too. An earlier upgrade counts at the full
        // price of its plan, as that is what its period is worth.
        $value = (int) ManualPaymentRequest::query()
            ->where('user_id', $user->id)
            ->where('status', ManualPaymentRequest::STATUS_APPROVED)
            ->where('approved_subscription_id', $current->id)
            ->where('plan_id', $from->id)
            ->where('currency', strtoupper($currency))
            ->where('reviewed_at', '>=', $start->copy()->subMinutes(5))
            ->get()
            ->sum(function (ManualPaymentRequest $paid): int {
                $snapshot = is_array($paid->price_snapshot) ? $paid->price_snapshot : [];

                return (int) (($snapshot['period_value_minor'] ?? 0) ?: $paid->expected_amount_minor);
            });
        if ($value <= 0) {
            return null;
        }

        $daysTotal = max(1, (int) round(abs($end->getTimestamp() - $start->getTimestamp()) / 86400));
        $daysLeft = min($daysTotal, max(0, (int) round(($end->getTimestamp() - $at->getTimestamp()) / 86400)));
        $credit = intdiv($value * $daysLeft, $daysTotal);
        $credit -= $credit % 100;
        if ($credit <= 0) {
            return null;
        }

        return [
            'credit_minor' => $credit,
            'value_minor' => $value,
            'days_left' => $daysLeft,
            'days_total' => $daysTotal,
            'from_plan_id' => (int) $from->id,
            'from_subscription_id' => (int) $current->id,
        ];
    }
}
