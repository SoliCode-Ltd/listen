<?php

namespace App\Support;

use App\Models\AudioGeneration;
use App\Models\BillingSubscription;
use App\Models\SiteSetting;
use App\Models\Subscription;
use App\Models\UsageCounter;
use Illuminate\Support\Carbon;

/**
 * The stretch of time a member's monthly credits belong to.
 *
 * A paid plan's credits follow the plan's own dates: they start when the
 * plan's period starts (the day it was paid for, given or renewed) and start
 * again when a new period starts (a renewal, or subscribing again). A yearly
 * or multi-month period starts again every month on the day it ends. The
 * free plan counts by calendar month, and so does everyone until the switch
 * is on (the site setting below, set by the migration that turned it on).
 *
 * Each period has a key, kept in usage_counters.period_ym (and in the gifts
 * and API key budgets): "2026-10" for a calendar month, "2026-10-14 08:37"
 * (UTC) for a period that follows a plan. Both start with the month, so
 * reports by month still group them. A credit month that had begun before
 * the switch counts by calendar month until it ends, exactly as before the
 * switch (a fresh count on the 1st), so the switch gives nobody new credits
 * and takes none away; the plan's own dates start with the next one.
 */
final class CreditPeriod
{
    /** When credits started following plan dates (UTC). Empty: calendar months for everyone. */
    public const SWITCH_SETTING = 'credit_periods_from';

    /** A first stretch of a plan period shorter than this belongs to the month after it. */
    private const SHORTEST_DAYS = 7;

    /** How long after its end a card plan whose renewal is on its way still counts. */
    private const RENEWAL_GRACE_HOURS = 2;

    /**
     * The member's credit period at a moment (now by default).
     *
     * @return array{key: string, starts_at: Carbon, ends_at: Carbon, follows_plan: bool, plan_ends_at: ?Carbon}
     */
    public static function current(int $userId, ?\DateTimeInterface $at = null): array
    {
        $at = $at !== null ? Carbon::instance($at)->utc() : now()->utc();
        $switchedAt = self::switchedAt();
        $subscription = $switchedAt !== null ? self::paidSubscription($userId, $at) : null;
        if ($subscription === null) {
            return self::calendarMonth($at);
        }

        $began = $subscription->current_period_start ?? $subscription->created_at;
        $began = $began !== null ? Carbon::instance($began)->utc() : null;
        $end = $subscription->current_period_end !== null ? Carbon::instance($subscription->current_period_end)->utc() : null;
        if ($began === null && $end === null) {
            return self::calendarMonth($at);
        }
        [$start, $until] = self::creditMonth($began, $end, $at);

        // Begun before the switch: the calendar month it is in, as before the
        // switch, until this credit month ends. Its counter may hold the end of
        // the previous paid period, which is why the 1st still starts a new one.
        if ($start->lt($switchedAt)) {
            $month = self::calendarMonth($at);

            return [
                'key' => $month['key'],
                'starts_at' => $month['starts_at'],
                'ends_at' => $month['ends_at']->lt($until) ? $month['ends_at'] : $until,
                'follows_plan' => true,
                'plan_ends_at' => $end,
            ];
        }

        return [
            'key' => $start->format('Y-m-d H:i'),
            'starts_at' => $start,
            'ends_at' => $until,
            'follows_plan' => true,
            'plan_ends_at' => $end,
        ];
    }

    /** The member's usage row for their current credit period (made when missing). */
    public static function counter(int $userId): UsageCounter
    {
        return UsageCounter::query()->firstOrCreate(
            ['user_id' => $userId, 'period_ym' => self::current($userId)['key']],
            ['total_minutes' => 0, 'total_chars' => 0, 'total_requests' => 0]
        );
    }

    /**
     * The period a generation was charged to: kept on it when it was made,
     * else the calendar month it was made in (generations from before).
     */
    public static function ofGeneration(AudioGeneration $generation): string
    {
        $snapshot = is_array($generation->settings_snapshot) ? $generation->settings_snapshot : [];
        $stored = $snapshot['usage_period'] ?? ($snapshot['reservation']['period'] ?? null);

        return is_string($stored) && $stored !== ''
            ? $stored
            : ($generation->created_at?->format('Y-m') ?? now()->format('Y-m'));
    }

    /** Since when paid plans' credits follow the plan's dates, or null (calendar months). */
    public static function switchedAt(): ?Carbon
    {
        try {
            $value = trim((string) SiteSetting::get(self::SWITCH_SETTING, ''));

            return $value === '' ? null : Carbon::parse($value, 'UTC')->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    /** "2026-10" or "2026-10-14 08:37": a key of a period that follows a plan? */
    public static function followsPlan(string $key): bool
    {
        return strlen($key) > 7;
    }

    /**
     * The paid plan the member is on now, as PlanAccessService picks it (the
     * latest active one); the free plan and packs count by calendar month.
     */
    private static function paidSubscription(int $userId, Carbon $at): ?Subscription
    {
        $subscription = Subscription::query()
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->where(function ($query) use ($at, $userId) {
                $query->whereNull('current_period_end')
                    ->orWhere('current_period_end', '>=', $at)
                    // A card renewal reaches us a little after the period ends: while
                    // the card subscription is live, the plan (and so its credit
                    // month) carries on for a short while, as in PlanAccessService.
                    ->orWhere(fn ($renewing) => $renewing
                        ->where('current_period_end', '>=', $at->copy()->subHours(self::RENEWAL_GRACE_HOURS))
                        ->whereExists(fn ($card) => $card->selectRaw('1')
                            ->from((new BillingSubscription)->getTable())
                            ->where('user_id', $userId)
                            ->whereIn('stripe_status', ['active', 'trialing'])));
            })
            ->latest('id')
            ->with('plan')
            ->first();

        $plan = $subscription?->plan;
        if (! $plan || (int) $plan->price_cents <= 0 || $plan->isPack()) {
            return null;
        }

        return $subscription;
    }

    /** @return array{key: string, starts_at: Carbon, ends_at: Carbon, follows_plan: bool, plan_ends_at: ?Carbon} */
    private static function calendarMonth(Carbon $at): array
    {
        $start = $at->copy()->startOfMonth();

        return [
            'key' => $at->format('Y-m'),
            'starts_at' => $start,
            'ends_at' => $start->copy()->addMonthNoOverflow(),
            'follows_plan' => false,
            'plan_ends_at' => null,
        ];
    }

    /**
     * The credit month around $at in a plan period from $began to $end.
     * Months are counted back from the end, so credits start again on the
     * day the plan renews or ends, whatever its start says: a monthly plan, a
     * trial or a plan given for a month is one credit month, and a first
     * stretch shorter than a week joins the month after it. A period with no
     * end counts months on from its start.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private static function creditMonth(?Carbon $began, ?Carbon $end, Carbon $at): array
    {
        if ($end === null) {
            $months = max(0, ($at->year - $began->year) * 12 + ($at->month - $began->month));
            while ($months > 0 && $began->copy()->addMonthsNoOverflow($months)->gt($at)) {
                $months--;
            }

            return [$began->copy()->addMonthsNoOverflow($months), $began->copy()->addMonthsNoOverflow($months + 1)];
        }

        $back = fn (int $months): Carbon => $end->copy()->subMonthsNoOverflow($months);
        if ($began !== null && $began->gte($end)) {
            $began = null;
        }

        // The months that fit after the start (no start known: no limit).
        $whole = null;
        if ($began !== null) {
            $whole = max(0, ($end->year - $began->year) * 12 + ($end->month - $began->month));
            while ($whole > 0 && $back($whole)->lt($began)) {
                $whole--;
            }
            if ($whole > 0 && $began->diffInDays($back($whole)) < self::SHORTEST_DAYS) {
                $whole--;
            }
            if ($whole === 0 || $at->lt($back($whole))) {
                return [$began->copy(), $whole === 0 ? $end->copy() : $back($whole)];
            }
        }

        $months = max(1, ($end->year - $at->year) * 12 + ($end->month - $at->month));
        $months = $whole !== null ? min($months, $whole) : $months;
        while (($whole === null || $months < $whole) && $back($months)->gt($at)) {
            $months++;
        }
        while ($months > 1 && $back($months - 1)->lte($at)) {
            $months--;
        }

        return [$back($months), $back($months - 1)];
    }
}
