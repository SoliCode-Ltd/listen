<?php

namespace App\Services\Billing;

use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Services\PlanAccessService;
use App\Support\CreditPeriod;
use App\Support\PlanUsageGuard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Laravel\Cashier\Cashier;
use Stripe\Exception\InvalidRequestException;

/**
 * What a member gets back when they move up to a higher plan: the share of what
 * they paid for the plan they leave that its unused credits are worth. The
 * higher plan then starts with its full credits.
 *
 * It counts credits, not days. A member who used all their credits in a week
 * gets nothing back and a full new allowance; one who used half gets half back.
 * Either way nobody pays twice for a credit and nobody gets one free, whenever
 * in the month they move up.
 *
 * Only the plan's own credits count: gifts and extra credits are not paid back,
 * and what is left of them goes on to the new plan as before. On a plan paid
 * for more than a month at once (a year), the months not begun yet come back in
 * full and the month running now by its unused credits.
 *
 * The period the upgrade ends is marked with the plan credits settled this way
 * (mark()), and the new period starts without them (CreditPeriod::carry()).
 * Used by a card upgrade (BillingController) and one paid by hand (ManualUpgrade).
 */
final class UnusedCredits
{
    /** How long a period's mark waits for the upgrade it was made for (a bank's check can take a while). */
    private const MARK_HOURS = 24;

    private const DAYS_A_MONTH = 30.4375;

    /** The line on Stripe's invoice that gives the money back, under one product of its own. */
    private const STRIPE_PRODUCT = 'listen_unused_credits';

    public const STRIPE_PRODUCT_NAME = 'Unused credits on the previous plan';

    private static ?bool $ready = null;

    public function __construct(private readonly PlanAccessService $plans) {}

    /**
     * Whether the database can hold the mark (the migration ran). Until then
     * upgrades work as before. Only a yes is remembered: a queue worker that
     * started before the migration must see it the moment it has run, or it
     * would carry the usage on for an upgrade the site already settled.
     */
    public static function ready(): bool
    {
        if (self::$ready === true) {
            return true;
        }

        try {
            return self::$ready = Schema::hasColumn('usage_counters', 'credited_chars');
        } catch (\Throwable) {
            return false;
        }
    }

    /** Forgets what ready() found (tests that change the table). */
    public static function forgetReady(): void
    {
        self::$ready = null;
    }

    /**
     * Where the member's plan credits stand now, or null when there is nothing
     * to count (no plan credits, credits still counted by calendar month, or the
     * migration has not run).
     *
     * @return array{period:string, used:int, allowance:int, unused:int, months_left:int, months_total:int}|null
     */
    public function standing(int $userId): ?array
    {
        // Credits must follow the plan's dates: counted by calendar month, a change of plan
        // starts no period of its own, so there would be no full new allowance to give.
        if (! self::ready() || CreditPeriod::switchedAt() === null) {
            return null;
        }

        $allowance = PlanUsageGuard::planChars($this->plans->resolveQuotaPlanForUserId($userId));
        if ($allowance <= 0) {
            return null;
        }

        $period = CreditPeriod::current($userId);
        $used = max(0, (int) CreditPeriod::counter($userId)->total_chars);
        [$left, $total] = $this->months($userId, $period);

        return [
            'period' => (string) $period['key'],
            'used' => $used,
            'allowance' => $allowance,
            'unused' => max(0, $allowance - $used),
            'months_left' => $left,
            'months_total' => $total,
        ];
    }

    /**
     * What unused credits are worth, out of what was paid for the whole period
     * (in the smallest unit of its currency). Rounded down, never above what was paid.
     *
     * @param  array{unused:int, allowance:int, months_left:int, months_total:int}  $standing
     */
    public static function worth(int $paidMinor, array $standing): int
    {
        $allowance = max(1, (int) $standing['allowance']);
        $total = max(1, (int) $standing['months_total']);
        $left = min($total - 1, max(0, (int) $standing['months_left']));
        $unused = min($allowance, max(0, (int) $standing['unused']));
        if ($paidMinor <= 0) {
            return 0;
        }

        return min($paidMinor, (int) floor($paidMinor * ($left * $allowance + $unused) / ($total * $allowance)));
    }

    /**
     * Marks the member's period as settled at this standing, just before the
     * upgrade is charged or given: the new period then starts without the
     * plan's own credits used so far (they were paid for, or paid back).
     *
     * @param  array{period:string, used:int, allowance:int}  $standing
     */
    public function mark(int $userId, array $standing): void
    {
        if (! self::ready()) {
            return;
        }

        UsageCounter::query()
            ->where('user_id', $userId)
            ->where('period_ym', (string) $standing['period'])
            ->update([
                'credited_chars' => min(max(0, (int) $standing['used']), max(0, (int) $standing['allowance'])),
                'credited_at' => now(),
            ]);
    }

    /** Takes the mark back: the upgrade was not paid, so the period goes on as it was. */
    public function unmark(int $userId, string $period): void
    {
        if (! self::ready()) {
            return;
        }

        UsageCounter::query()
            ->where('user_id', $userId)
            ->where('period_ym', $period)
            ->whereNotNull('credited_at')
            ->update(['credited_chars' => null, 'credited_at' => null]);
    }

    /** The plan credits a period that a change of plan ends was settled at, or null when it carries on whole. */
    public static function settled(UsageCounter $previous): ?int
    {
        if (! self::ready()) {
            return null;
        }

        $at = $previous->getAttribute('credited_at');
        $chars = $previous->getAttribute('credited_chars');
        if ($at === null || $chars === null || Carbon::parse($at)->lt(now()->subHours(self::MARK_HOURS))) {
            return null;
        }

        return max(0, (int) $chars);
    }

    /**
     * What the member paid by card for the plan period running now: the plan's
     * own line on the invoice that began it, less its discounts. Null when no
     * such paid invoice is found; nothing is then paid back.
     *
     * @return array{minor:int, currency:string}|null
     */
    public function paidOnCard(object $stripeSubscription): ?array
    {
        $id = trim((string) ($stripeSubscription->id ?? ''));
        $item = $stripeSubscription->items->data[0] ?? null;
        $start = (int) ($item->current_period_start ?? $stripeSubscription->current_period_start ?? 0);
        if ($id === '' || $start <= 0) {
            return null;
        }

        $invoices = Cashier::stripe()->invoices->all(['subscription' => $id, 'status' => 'paid', 'limit' => 10]);
        foreach ($invoices->data ?? [] as $invoice) {
            foreach ($invoice->lines->data ?? [] as $line) {
                if (! is_object($line) || ! self::isPlanLine($line) || abs((int) ($line->period->start ?? 0) - $start) > 300) {
                    continue;
                }

                return [
                    'minor' => max(0, (int) $line->amount - self::discountOn($line)),
                    'currency' => strtolower((string) ($line->currency ?? $invoice->currency ?? 'usd')),
                ];
            }
        }

        Log::warning('No paid invoice found for the plan period an upgrade ends: nothing is paid back.', [
            'stripe_subscription' => $id,
            'period_start' => $start,
        ]);

        return null;
    }

    /**
     * The Stripe product the money back is listed under on the invoice. Made
     * once per Stripe account; the plan's own product stands in if it cannot be.
     */
    public function stripeProduct(string $fallback): string
    {
        $account = substr(sha1((string) config('cashier.secret')), 0, 12);
        $known = Cache::get('unused_credits_product:'.$account);
        if (is_string($known) && $known !== '') {
            return $known;
        }

        try {
            try {
                Cashier::stripe()->products->retrieve(self::STRIPE_PRODUCT, []);
            } catch (InvalidRequestException $missing) {
                if ($missing->getHttpStatus() !== 404) {
                    throw $missing;
                }
                Cashier::stripe()->products->create(['id' => self::STRIPE_PRODUCT, 'name' => self::STRIPE_PRODUCT_NAME]);
            }
            Cache::put('unused_credits_product:'.$account, self::STRIPE_PRODUCT, now()->addDays(30));

            return self::STRIPE_PRODUCT;
        } catch (\Throwable $e) {
            Log::warning('The Stripe product for unused credits could not be made; the plan\'s product is used.', ['error' => $e->getMessage()]);

            return $fallback;
        }
    }

    /** Whether an invoice line is the plan itself: not a proration, not a one-off item, and it charges something. */
    private static function isPlanLine(object $line): bool
    {
        if ((int) ($line->amount ?? 0) <= 0
            || (bool) ($line->proration ?? false)
            || (bool) ($line->parent->subscription_item_details->proration ?? false)) {
            return false;
        }

        $kind = (string) ($line->parent->type ?? '');

        return $kind !== '' ? $kind === 'subscription_item_details' : (string) ($line->type ?? 'subscription') === 'subscription';
    }

    /** The discounts taken off an invoice line, in the API versions from before and after they moved. */
    private static function discountOn(object $line): int
    {
        $off = 0;
        foreach ($line->pretax_credit_amounts ?? [] as $credit) {
            if ((string) ($credit->type ?? '') === 'discount') {
                $off += (int) ($credit->amount ?? 0);
            }
        }
        if ($off === 0) {
            foreach ($line->discount_amounts ?? [] as $discount) {
                $off += (int) ($discount->amount ?? 0);
            }
        }

        return max(0, $off);
    }

    /**
     * The credit months of the plan's paid period that have not begun yet, and
     * how many it has in all (a monthly plan: none left, of one).
     *
     * @param  array{ends_at: Carbon, plan_ends_at: ?Carbon}  $period
     * @return array{0:int, 1:int}
     */
    private function months(int $userId, array $period): array
    {
        $row = Subscription::query()->where('user_id', $userId)->latest('id')->first();
        $start = $row?->current_period_start ?? null;
        $end = $period['plan_ends_at'] ?? null;
        if ($start === null || $end === null || Carbon::instance($start)->gte($end)) {
            return [0, 1];
        }

        $months = fn (Carbon $from, Carbon $to): int => (int) round(max(0, $to->getTimestamp() - $from->getTimestamp()) / 86400 / self::DAYS_A_MONTH);
        $total = max(1, $months(Carbon::instance($start), $end));
        $left = min($total - 1, $months($period['ends_at'], $end));

        return [$left, $total];
    }
}
