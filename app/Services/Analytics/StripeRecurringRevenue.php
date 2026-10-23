<?php

namespace App\Services\Analytics;

use App\Services\Billing\StripePricePlans;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Cashier;
use Throwable;

/**
 * The card subscriptions as Stripe has them, and the money they bring each
 * month: what each one really charges at its next bill (after its discount,
 * at the price the member kept), with yearly plans counted at one twelfth.
 *
 *   renewing    active, not set to cancel: the income to expect
 *   cancelling  still active, set to cancel when its paid time ends
 *   trialing    in a free trial that will charge when it ends
 *   past_due    a renewal the bank refused, not paid yet
 *   ended_trial in a trial set to cancel: it will never charge
 *   paused      payment collection paused
 *
 * Net is after Stripe's fees, at the rate this account really paid on its
 * card charges in the last 90 days. Admin accounts are left out, like
 * everywhere else on the dashboard. Read-only; kept for ten minutes, and
 * forgotten whenever Stripe tells the site a subscription or invoice changed.
 */
class StripeRecurringRevenue
{
    public const CACHE_KEY = 'stripe_recurring_revenue:v1';

    private const CACHE_TTL_SECONDS = 600;

    /** A failed read is retried soon, not kept for the full ten minutes. */
    private const FAILURE_TTL_SECONDS = 120;

    private const STATUSES = ['active', 'trialing', 'past_due', 'unpaid'];

    private const FEE_DAYS = 90;

    public const KINDS = ['renewing', 'cancelling', 'trialing', 'past_due', 'ended_trial', 'paused'];

    /** @var array<string, object|null> */
    private array $coupons = [];

    public function __construct(private readonly StripePricePlans $prices) {}

    public static function configured(): bool
    {
        return trim((string) config('services.stripe.secret', '')) !== '';
    }

    public function summary(bool $fresh = false): array
    {
        if ($fresh) {
            static::forget();
        }

        $cached = Cache::get(self::CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        $summary = $this->build();
        Cache::put(self::CACHE_KEY, $summary, $summary['ok'] ? self::CACHE_TTL_SECONDS : self::FAILURE_TTL_SECONDS);

        return $summary;
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public function build(): array
    {
        $now = Carbon::now();
        if (! static::configured()) {
            return ['ok' => false, 'error' => 'Stripe is not configured.', 'generated_at' => $now->toIso8601String()];
        }

        try {
            $stripe = Cashier::stripe();
            $admins = $this->adminCustomers();

            $rows = [];
            foreach (self::STATUSES as $status) {
                $list = $stripe->subscriptions->all(['status' => $status, 'limit' => 100, 'expand' => ['data.discounts']]);
                foreach ($list->autoPagingIterator() as $subscription) {
                    if (isset($admins[(string) ($subscription->customer ?? '')])) {
                        continue;
                    }
                    $rows[] = $this->row($subscription, $now);
                }
            }

            return $this->totals($rows, $this->feeRate($stripe, $now), $now);
        } catch (Throwable $e) {
            Log::warning('Could not read the card subscriptions from Stripe for the dashboard.', ['error' => $e->getMessage()]);

            return ['ok' => false, 'error' => $e->getMessage(), 'generated_at' => $now->toIso8601String()];
        }
    }

    /**
     * One subscription: its kind, plan, next bill and its monthly value.
     */
    private function row(object $subscription, Carbon $now): array
    {
        $status = (string) ($subscription->status ?? '');
        $cancelling = (bool) ($subscription->cancel_at_period_end ?? false) || ! empty($subscription->cancel_at);
        $kind = match (true) {
            ! empty($subscription->pause_collection) => 'paused',
            $status === 'trialing' => $cancelling ? 'ended_trial' : 'trialing',
            $cancelling => 'cancelling',
            in_array($status, ['past_due', 'unpaid'], true) => 'past_due',
            default => 'renewing',
        };

        $items = $subscription->items->data ?? [];
        $first = $items[0] ?? null;
        $nextBill = $status === 'trialing' && ! empty($subscription->trial_end)
            ? (int) $subscription->trial_end
            : (int) ($first->current_period_end ?? $subscription->current_period_end ?? 0);

        $currency = strtolower((string) ($first->price->currency ?? $subscription->currency ?? 'usd'));
        $cycleCents = 0;   // the next bill, before discounts
        $monthlyFactor = 0.0;
        foreach ($items as $item) {
            $price = $item->price ?? null;
            $unit = $price->unit_amount ?? null;
            if ($unit === null && isset($price->unit_amount_decimal)) {
                $unit = (float) $price->unit_amount_decimal;
            }
            $cycleCents += (float) $unit * max(1, (int) ($item->quantity ?? 1));
            $monthlyFactor = $monthlyFactor ?: $this->monthsPerBill($price->recurring ?? null);
        }

        $billCents = $this->afterDiscounts($cycleCents, $subscription, $status, $nextBill, $currency);
        $plan = $first ? rescue(fn () => $this->prices->planFor($first->price ?? null), null, false) : null;

        return [
            'kind' => $kind,
            'currency' => $currency,
            'plan' => $plan ? (string) $plan['plan']->name : (string) ($first->price->nickname ?? 'Other price'),
            'plan_id' => $plan ? (int) $plan['plan']->id : null,
            'cycle' => $plan['cycle'] ?? (($first->price->recurring->interval ?? 'month') === 'year' ? 'yearly' : 'monthly'),
            'bill_cents' => (int) round($billCents),
            'monthly_cents' => $monthlyFactor > 0 ? (int) round($billCents / $monthlyFactor) : 0,
            'next_bill_at' => $nextBill,
        ];
    }

    /**
     * How many months one bill covers: 1 for monthly, 12 for yearly.
     */
    private function monthsPerBill(?object $recurring): float
    {
        $count = max(1, (int) ($recurring->interval_count ?? 1));

        return match ((string) ($recurring->interval ?? 'month')) {
            'year' => 12.0 * $count,
            'week' => $count * 7 * 12 / 365,
            'day' => $count * 12 / 365,
            default => (float) $count,
        };
    }

    /**
     * The next bill after the subscription's discounts that still apply to it:
     * "forever" always, "repeating" until its end date, and "once" only on the
     * first bill (the one a trial ends with); an active subscription already used it.
     */
    private function afterDiscounts(float $cents, object $subscription, string $status, int $nextBill, string $currency): float
    {
        $discounts = $subscription->discounts ?? [];
        if (empty($discounts) && ! empty($subscription->discount)) {
            $discounts = [$subscription->discount];
        }

        foreach ($discounts as $discount) {
            if (! is_object($discount)) {
                continue;
            }
            $coupon = $this->coupon($discount->source->coupon ?? $discount->coupon ?? null);
            if (! $coupon) {
                continue;
            }
            $applies = match ((string) ($coupon->duration ?? 'once')) {
                'forever' => true,
                'repeating' => empty($discount->end) || (int) $discount->end > $nextBill,
                default => $status === 'trialing',
            };
            if (! $applies) {
                continue;
            }
            if (! empty($coupon->percent_off)) {
                $cents *= max(0, 1 - ((float) $coupon->percent_off / 100));
            } elseif (! empty($coupon->amount_off) && strtolower((string) ($coupon->currency ?? $currency)) === $currency) {
                $cents = max(0, $cents - (int) $coupon->amount_off);
            }
        }

        return $cents;
    }

    private function coupon(mixed $coupon): ?object
    {
        if (is_object($coupon)) {
            return $coupon;
        }
        $id = trim((string) $coupon);
        if ($id === '') {
            return null;
        }

        return $this->coupons[$id] ??= rescue(fn () => Cashier::stripe()->coupons->retrieve($id, []), null, false);
    }

    /**
     * Stripe's fees as a share of what was charged by card, over the last 90 days.
     */
    private function feeRate(object $stripe, Carbon $now): array
    {
        $amount = 0;
        $fee = 0;
        $count = 0;
        foreach (['charge', 'payment'] as $type) {
            $list = $stripe->balanceTransactions->all([
                'type' => $type,
                'created' => ['gte' => $now->copy()->subDays(self::FEE_DAYS)->getTimestamp()],
                'limit' => 100,
            ]);
            foreach ($list->autoPagingIterator() as $transaction) {
                $amount += (int) ($transaction->amount ?? 0);
                $fee += (int) ($transaction->fee ?? 0);
                $count++;
            }
        }

        return ['rate' => $amount > 0 ? round($fee / $amount, 4) : null, 'charges' => $count, 'days' => self::FEE_DAYS];
    }

    /**
     * Customers of admin accounts, left out like admins everywhere on the dashboard.
     *
     * @return array<string, true>
     */
    private function adminCustomers(): array
    {
        return DB::table('users')
            ->where('is_admin', true)
            ->whereNotNull('stripe_id')
            ->pluck('stripe_id')
            ->filter()
            ->mapWithKeys(fn ($id) => [(string) $id => true])
            ->all();
    }

    private function totals(array $rows, array $fees, Carbon $now): array
    {
        $kinds = array_fill_keys(self::KINDS, ['count' => 0, 'monthly_cents' => []]);
        $byPlan = [];
        $due30 = ['count' => 0, 'cents' => []];
        $soon = $now->copy()->addDays(30)->getTimestamp();

        foreach ($rows as $row) {
            $k = &$kinds[$row['kind']];
            $k['count']++;
            $k['monthly_cents'][$row['currency']] = ($k['monthly_cents'][$row['currency']] ?? 0) + $row['monthly_cents'];
            unset($k);

            // The plans members pay for now: renewing or still running until they end.
            if (in_array($row['kind'], ['renewing', 'cancelling'], true)) {
                $key = $row['plan'].' ('.$row['cycle'].')';
                $plan = &$byPlan[$key];
                $plan ??= ['plan' => $row['plan'], 'plan_id' => $row['plan_id'], 'cycle' => $row['cycle'], 'renewing' => 0, 'cancelling' => 0, 'monthly_cents' => []];
                $plan[$row['kind']]++;
                $plan['monthly_cents'][$row['currency']] = ($plan['monthly_cents'][$row['currency']] ?? 0) + $row['monthly_cents'];
                unset($plan);
            }

            if ($row['kind'] === 'renewing' && $row['next_bill_at'] > 0 && $row['next_bill_at'] <= $soon) {
                $due30['count']++;
                $due30['cents'][$row['currency']] = ($due30['cents'][$row['currency']] ?? 0) + $row['bill_cents'];
            }
        }

        $rate = $fees['rate'];
        $net = fn (array $amounts) => array_map(fn ($cents) => (int) round($cents * (1 - ($rate ?? 0))), $amounts);
        uasort($byPlan, fn ($a, $b) => array_sum($b['monthly_cents']) <=> array_sum($a['monthly_cents']));

        return [
            'ok' => true,
            'generated_at' => $now->toIso8601String(),
            'kinds' => $kinds,
            'expected' => [
                'count' => $kinds['renewing']['count'],
                'gross_cents' => $kinds['renewing']['monthly_cents'],
                'net_cents' => $net($kinds['renewing']['monthly_cents']),
            ],
            'due_30d' => $due30 + ['net_cents' => $net($due30['cents'])],
            'fees' => $fees,
            'by_plan' => array_values($byPlan),
        ];
    }
}
