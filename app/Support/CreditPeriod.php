<?php

namespace App\Support;

use App\Models\ApiKey;
use App\Models\ApiKeyUsage;
use App\Models\AudioGeneration;
use App\Models\BillingSubscription;
use App\Models\CharGift;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Services\Billing\RenewalGrace;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * The stretch of time a member's monthly credits belong to.
 *
 * A paid plan's credits follow the plan's own dates: they start when the
 * plan's period starts (the day it was paid for, given or renewed) and start
 * again when a new period starts (a renewal, or subscribing again). A change
 * of plan carries the usage on instead (carryOverPlanChange()). A yearly
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
 *
 * While a failed renewal's grace days run (App\Services\Billing\RenewalGrace),
 * the credits stay in the period that was paid for, counted as at its last
 * moment: the same key and counter whatever the clock or the calendar says,
 * and no fresh allowance until the renewal is paid.
 */
final class CreditPeriod
{
    /** When credits started following plan dates (UTC). Empty: calendar months for everyone. */
    public const SWITCH_SETTING = 'credit_periods_from';

    /** A first stretch of a plan period shorter than this belongs to the month after it. */
    private const SHORTEST_DAYS = 7;

    /** How long after its end a card plan whose renewal is on its way still counts. */
    private const RENEWAL_GRACE_HOURS = 2;

    /** On while a plan paid in full by hand is given (startingAfresh()). */
    private static bool $startingAfresh = false;

    /**
     * The member's credit period at a moment (now by default). grace_ends_at is
     * set while a failed renewal's grace keeps the plan on: the period then
     * lasts until the renewal is paid or, at the latest, until that moment.
     *
     * @return array{key: string, starts_at: Carbon, ends_at: Carbon, follows_plan: bool, plan_ends_at: ?Carbon, grace_ends_at: ?Carbon}
     */
    public static function current(int $userId, ?\DateTimeInterface $at = null): array
    {
        $at = $at !== null ? Carbon::instance($at)->utc() : now()->utc();
        $switchedAt = self::switchedAt();
        $subscription = self::paidSubscription($userId, $at);

        // The grace days of a failed renewal: counted as at the last moment of
        // the period paid for, which the plan's dates were kept on.
        $graceEndsAt = null;
        $countAt = $at;
        if ($subscription !== null && RenewalGrace::keeps($subscription, $at)) {
            $graceEndsAt = Carbon::instance($subscription->billing_grace_ends_at)->utc();
            $paidUntil = $subscription->current_period_end !== null ? Carbon::instance($subscription->current_period_end)->utc() : null;
            if ($paidUntil !== null && $paidUntil->lte($at)) {
                $countAt = $paidUntil->copy()->subSecond();
            }
        }

        return self::periodOf($switchedAt !== null ? $subscription : null, $countAt, $switchedAt)
            + ['grace_ends_at' => $graceEndsAt];
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
     * Keeps a member's usage when a change of plan cuts their credit period
     * short. Called while their subscription row is saved, before it is
     * written, so no request sees the new period before its counter does.
     *
     * Credits start again only at a renewal (the new period starts when the
     * old one ends) or with a plan taken after the old one ended. A change of
     * plan restarts the plan's dates on the day it is made, so without this
     * every change would start a fresh allowance (down a plan and back up
     * again costs about $1). The new period's counter starts with what the
     * old one had used, and its gifts and API key spending go on with it.
     * The counter is made only once (its unique key), so nothing is carried
     * twice. Between calendar months and plan dates nothing is carried.
     */
    public static function carryOverPlanChange(Subscription $subscription): void
    {
        $switchedAt = self::switchedAt();
        if ($switchedAt === null || self::$startingAfresh
            || ! $subscription->isDirty(['plan_id', 'status', 'current_period_start', 'current_period_end'])) {
            return;
        }

        // Only the member's latest row decides their plan.
        $userId = (int) $subscription->user_id;
        if ($subscription->exists && Subscription::query()->where('user_id', $userId)->where('id', '>', $subscription->getKey())->exists()) {
            return;
        }

        $now = now()->utc();
        $before = self::current($userId, $now);
        $after = self::periodOf(self::paidAfterSave($subscription, $now), $now, $switchedAt);
        if (! self::followsPlan($before['key']) || ! self::followsPlan($after['key'])
            || $after['key'] === $before['key']
            // A renewal, or a plan taken after the old one ended: a fresh allowance.
            || $after['starts_at']->gte($before['ends_at'])) {
            return;
        }

        self::carry($userId, $before['key'], $after['key']);
    }

    /**
     * Runs $callback, which gives a plan paid in full for a new period of its
     * own (a payment made by hand): its credits start afresh even when it is
     * paid before the old period ends.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function startingAfresh(callable $callback): mixed
    {
        $previous = self::$startingAfresh;
        self::$startingAfresh = true;

        try {
            return $callback();
        } finally {
            self::$startingAfresh = $previous;
        }
    }

    /**
     * The credit period a paid plan's row gives at a moment (no row: the calendar month).
     *
     * @return array{key: string, starts_at: Carbon, ends_at: Carbon, follows_plan: bool, plan_ends_at: ?Carbon}
     */
    private static function periodOf(?Subscription $subscription, Carbon $at, ?Carbon $switchedAt): array
    {
        if ($subscription === null || $switchedAt === null) {
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
                            ->whereIn('stripe_status', ['active', 'trialing'])
                            // Not one cancelled to end with this period.
                            ->where(fn ($ends) => $ends->whereNull('ends_at')->orWhere('ends_at', '>', $at))))
                    // A failed renewal's grace days, as in PlanAccessService.
                    ->orWhere(fn ($grace) => RenewalGrace::whereKept($grace, $at));
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

    /** The row as it is about to be saved, when that makes a paid plan running now. */
    private static function paidAfterSave(Subscription $subscription, Carbon $at): ?Subscription
    {
        $plan = Plan::query()->find($subscription->plan_id);
        $end = $subscription->current_period_end;
        $running = (string) $subscription->status === 'active' && ($end === null || Carbon::instance($end)->gte($at));

        return $running && $plan && (int) $plan->price_cents > 0 && ! $plan->isPack() ? $subscription : null;
    }

    /**
     * The new period starts with the old one's usage, its gifts that still
     * count (so they can be taken back there) and each API key's spending (so
     * a key's own budget goes on). Made once: if the new period's counter is
     * already there, everything is left as it is.
     */
    private static function carry(int $userId, string $from, string $to): void
    {
        $previous = UsageCounter::query()->where('user_id', $userId)->where('period_ym', $from)->first();
        if (! $previous) {
            return;
        }

        $carried = DB::transaction(function () use ($userId, $previous, $from, $to): bool {
            $made = UsageCounter::query()->insertOrIgnore([
                'user_id' => $userId,
                'period_ym' => $to,
                'total_minutes' => (int) $previous->total_minutes,
                'total_seconds' => (int) $previous->total_seconds,
                'total_chars' => (int) $previous->total_chars,
                'bonus_chars' => (int) $previous->bonus_chars,
                'total_requests' => (int) $previous->total_requests,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            if ($made === 0) {
                return false;
            }

            CharGift::query()
                ->where('user_id', $userId)
                ->where('period_ym', $from)
                ->whereNull('revoked_at')
                ->get()
                ->each(function (CharGift $gift) use ($to): void {
                    $copy = $gift->replicate();
                    $copy->period_ym = $to;
                    // Still shown as given on its own day.
                    $copy->created_at = $gift->created_at;
                    $copy->save();
                });

            // Before the developer API migration there are no keys.
            if (Schema::hasTable('api_key_usages')) {
                ApiKeyUsage::query()
                    ->whereIn('api_key_id', ApiKey::query()->where('user_id', $userId)->select('id'))
                    ->where('period_ym', $from)
                    ->get()
                    ->each(fn (ApiKeyUsage $usage) => ApiKeyUsage::query()->insertOrIgnore([
                        'api_key_id' => $usage->api_key_id,
                        'period_ym' => $to,
                        'requests' => (int) $usage->requests,
                        'credits' => (int) $usage->credits,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]));
            }

            return true;
        });

        if ($carried) {
            Log::info('Credits carried over to the period a plan change started.', [
                'user_id' => $userId,
                'from' => $from,
                'to' => $to,
                'chars' => (int) $previous->total_chars,
            ]);
        }
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
