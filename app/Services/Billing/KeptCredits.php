<?php

namespace App\Services\Billing;

use App\Models\Plan;
use App\Models\Subscription;
use App\Services\PlanAccessService;
use App\Support\Credits;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Lowering a plan's monthly credits (and so its voice minutes) without taking
 * them from the members already paying for it. The admin chooses:
 *
 *   new_only     members on the plan now keep their credits for as long as
 *                they stay on it; only new subscribers get the lower amount.
 *   next_period  members on the plan now keep their credits for the period
 *                they are in (a yearly plan: until it renews), then get the
 *                lower amount like everyone else.
 *
 * What a member keeps is saved on their plan row (subscriptions.kept_*):
 * kept_monthly_chars for good, kept_period_chars for the period that started
 * at kept_period_start. A period lasts as long as the row's own dates do, so
 * a renewal that reaches the site late, or a failed renewal's grace days,
 * stay in it; it ends when the plan takes a new period. Where the member's
 * allowance is worked out (PlanAccessService::resolveQuotaPlanForUserId) they
 * get the most of the plan's amount and what they keep.
 *
 * Leaving the plan is remembered (kept_left_at, the end of the period paid on
 * it). Coming back after that period and its grace days would have run out
 * makes them a new subscriber; sooner (a plan changed and changed back) they
 * carry on. A member keeps credits on one plan at a time: the one they are on.
 */
class KeptCredits
{
    public const NEW_ONLY = 'new_only';

    public const NEXT_PERIOD = 'next_period';

    public const COLUMNS = ['kept_plan_id', 'kept_monthly_chars', 'kept_period_chars', 'kept_period_start', 'kept_left_at'];

    /** The member count in the plan form is worked out once a minute, not on every keystroke. */
    private const COUNT_TTL_SECONDS = 60;

    public function __construct(private readonly PlanAccessService $plans) {}

    /**
     * Members on the plan now keep $previousChars a month: for good (new_only)
     * or for the period they are in (next_period). Returns how many members.
     */
    public function keepForCurrentMembers(Plan $plan, int $previousChars, string $mode): int
    {
        $kept = 0;
        foreach ($this->members($plan) as $row) {
            $samePlan = (int) $row->kept_plan_id === (int) $plan->id;
            $period = $this->periodStart($row);
            $samePeriod = $samePlan && $row->kept_period_start?->getTimestamp() === $period->getTimestamp();

            // Lowered more than once: they keep the most they were promised.
            $forGood = $samePlan ? (int) $row->kept_monthly_chars : 0;
            $forPeriod = $samePeriod ? (int) $row->kept_period_chars : 0;
            if ($mode === self::NEW_ONLY) {
                $forGood = max($forGood, $previousChars);
            } else {
                $forPeriod = max($forPeriod, $previousChars);
            }

            // No model events: nothing else about the plan row changes.
            DB::table('subscriptions')->where('id', $row->id)->update([
                'kept_plan_id' => $plan->id,
                'kept_monthly_chars' => $forGood > 0 ? $forGood : null,
                'kept_period_chars' => $forPeriod > 0 ? $forPeriod : null,
                'kept_period_start' => $forPeriod > 0 ? $period : null,
                'kept_left_at' => null,
            ]);
            $kept++;
        }
        Cache::forget($this->countKey($plan));

        Log::info('Plan credits lowered; members on the plan keep theirs.', [
            'plan' => $plan->slug, 'from' => $previousChars, 'to' => (int) $plan->monthly_chars, 'mode' => $mode, 'members' => $kept,
        ]);

        return $kept;
    }

    /** Members on the plan now, for the plan form's note. */
    public function membersOn(Plan $plan): int
    {
        return (int) Cache::remember($this->countKey($plan), self::COUNT_TTL_SECONDS, fn (): int => iterator_count($this->members($plan)));
    }

    /**
     * The plan as this member's allowance sees it: with the credits they kept,
     * when they kept more than the plan gives now. Not saved.
     */
    public function applyTo(Plan $plan, int $userId): Plan
    {
        // Nobody pays for a period of the free plan or a pack: nothing is kept on them.
        if ((int) $plan->price_cents <= 0 || $plan->isPack()) {
            return $plan;
        }

        $row = Subscription::query()->where('user_id', $userId)->latest('id')->first();
        $chars = $row ? $this->keptChars($row, $plan) : 0;
        if ($chars <= (int) $plan->monthly_chars) {
            return $plan;
        }

        $quota = $plan->replicate();
        $quota->setAttribute($plan->getKeyName(), $plan->getKey());
        $quota->monthly_chars = $chars;
        $quota->monthly_minutes = Credits::minutesFor($chars);
        $quota->setAttribute('kept_credits', true);

        return $quota;
    }

    /**
     * The member's plan row is about to take $plan from $periodStart
     * (PlanAccessService::assignPlan): the kept_* columns that change with it.
     */
    public function onPlanChange(Subscription $row, Plan $plan, \DateTimeInterface $periodStart): array
    {
        // Nothing kept (and before the migration, no columns to change).
        if ($row->kept_plan_id === null) {
            return [];
        }

        $wasOn = (int) $row->plan_id === (int) $row->kept_plan_id;
        $goesOn = (int) $plan->id === (int) $row->kept_plan_id;
        if ($wasOn && ! $goesOn) {
            return ['kept_left_at' => $row->current_period_end ?? now()];
        }
        if (! $goesOn) {
            return [];
        }

        // Back on the plan (or a new period of it) after the one paid for ran out: a new subscriber.
        $paidUntil = $wasOn ? $row->current_period_end : $row->kept_left_at;
        if ($paidUntil !== null && Carbon::instance($periodStart)->gt(Carbon::instance($paidUntil)->addDays(RenewalGrace::graceDays()))) {
            return array_fill_keys(self::COLUMNS, null);
        }

        return $wasOn ? [] : ['kept_left_at' => null];
    }

    /** What the row keeps for this plan now (0: nothing). */
    private function keptChars(Subscription $row, Plan $plan): int
    {
        if ($row->kept_plan_id === null || (int) $row->kept_plan_id !== (int) $plan->id || (int) $row->plan_id !== (int) $plan->id) {
            return 0;
        }

        $forPeriod = $row->kept_period_start?->getTimestamp() === $this->periodStart($row)->getTimestamp()
            ? (int) $row->kept_period_chars
            : 0;

        return max((int) $row->kept_monthly_chars, $forPeriod);
    }

    /** When the row's paid period started: it is a new period once this changes. */
    private function periodStart(Subscription $row): Carbon
    {
        return Carbon::instance($row->current_period_start ?? $row->created_at ?? now());
    }

    /**
     * The members on the plan now: their plan counts (not one that ended), and
     * they are not in a free trial yet to be paid (that is a new subscriber).
     *
     * @return \Generator<int, Subscription>
     */
    private function members(Plan $plan): \Generator
    {
        $rows = Subscription::query()
            ->whereIn('id', Subscription::query()->selectRaw('max(id)')->groupBy('user_id'))
            ->where('plan_id', $plan->id)
            ->where('status', 'active');

        foreach ($rows->lazyById(200) as $row) {
            $userId = (int) $row->user_id;
            if ((int) $this->plans->resolveActivePlanForUserId($userId)?->id === (int) $plan->id
                && ! $this->plans->userHasUnpaidTrialWindow($userId)) {
                yield $row;
            }
        }
    }

    private function countKey(Plan $plan): string
    {
        return 'kept_credits:members:'.$plan->id;
    }
}
