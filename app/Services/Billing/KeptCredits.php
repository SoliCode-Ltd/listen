<?php

namespace App\Services\Billing;

use App\Models\Plan;
use App\Models\Subscription;
use App\Support\CreditPeriod;
use App\Support\Credits;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Lowering a plan's monthly credits (and so its voice minutes) without taking
 * them from the members already paying for it. The admin chooses:
 *
 *   new_only     members on the plan now keep their credits for as long as
 *                they stay on it; only new subscribers get the lower amount.
 *   next_period  members on the plan now keep their credits until their paid
 *                period ends (a yearly plan: until it renews), then get the
 *                lower amount like everyone else.
 *
 * What a member keeps is saved on their plan row (subscriptions.kept_*) and
 * used wherever their allowance is worked out (PlanAccessService::
 * resolveQuotaPlanForUserId). It is dropped when they move to another plan or
 * come back after their plan ended. Raising the plan later above what they
 * keep gives them the plan's amount.
 */
class KeptCredits
{
    public const NEW_ONLY = 'new_only';

    public const NEXT_PERIOD = 'next_period';

    /**
     * Members on the plan now keep $previousChars a month. Returns how many members.
     */
    public function keepForCurrentMembers(Plan $plan, int $previousChars, string $mode): int
    {
        $kept = 0;
        $latest = Subscription::query()->selectRaw('max(id)')->groupBy('user_id');

        Subscription::query()
            ->whereIn('id', $latest)
            ->where('plan_id', $plan->id)
            ->where('status', 'active')
            ->each(function (Subscription $row) use ($plan, $previousChars, $mode, &$kept) {
                $still = $this->stillKept($row, $plan);
                $until = $mode === self::NEW_ONLY ? null : $this->periodEnd($row);
                $row->forceFill([
                    // Lowered twice: they keep the most they were promised.
                    'kept_monthly_chars' => max($previousChars, $still ? (int) $row->kept_monthly_chars : 0),
                    'kept_plan_id' => $plan->id,
                    // Kept for good once stays for good; otherwise until the period ends.
                    'kept_until' => $still && $row->kept_until === null ? null : $until,
                ])->save();
                $kept++;
            });

        Log::info('Plan credits lowered; members on the plan keep theirs.', [
            'plan' => $plan->slug, 'from' => $previousChars, 'to' => (int) $plan->monthly_chars, 'mode' => $mode, 'members' => $kept,
        ]);

        return $kept;
    }

    /** Members on the plan now, for the admin form's note. */
    public function membersOn(Plan $plan): int
    {
        return Subscription::query()
            ->whereIn('id', Subscription::query()->selectRaw('max(id)')->groupBy('user_id'))
            ->where('plan_id', $plan->id)
            ->where('status', 'active')
            ->count();
    }

    /**
     * The plan as this member's allowance sees it: with the credits they kept,
     * when they kept more than the plan gives now. Not saved.
     */
    public function applyTo(Plan $plan, int $userId): Plan
    {
        $row = Subscription::query()->where('user_id', $userId)->latest('id')->first();
        if (! $row || ! $this->stillKept($row, $plan) || (int) $row->kept_monthly_chars <= (int) $plan->monthly_chars) {
            return $plan;
        }

        $quota = $plan->replicate();
        $quota->setAttribute($plan->getKeyName(), $plan->getKey());
        $quota->monthly_chars = (int) $row->kept_monthly_chars;
        $quota->monthly_minutes = Credits::minutesFor((int) $row->kept_monthly_chars);
        $quota->setAttribute('kept_credits_until', $row->kept_until);

        return $quota;
    }

    /** Whether the row still keeps credits for this plan. */
    private function stillKept(Subscription $row, Plan $plan): bool
    {
        return $row->kept_monthly_chars !== null
            && (int) $row->kept_plan_id === (int) $plan->id
            && (int) $row->plan_id === (int) $plan->id
            && ($row->kept_until === null || $row->kept_until->isFuture());
    }

    /**
     * When the member's paid period ends: the end of what their plan row says
     * was paid for (a yearly plan: its renewal), or, without one, the end of
     * their credit month.
     */
    private function periodEnd(Subscription $row): Carbon
    {
        if ($row->current_period_end !== null && $row->current_period_end->isFuture()) {
            return Carbon::instance($row->current_period_end);
        }

        return Carbon::instance(CreditPeriod::current((int) $row->user_id)['ends_at']);
    }
}
