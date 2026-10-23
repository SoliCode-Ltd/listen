<?php

namespace App\Services\Analytics;

use App\Models\Plan;
use App\Models\SiteSetting;
use App\Services\Billing\StripePricePlans;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Business statistics for the admin dashboard and the downloadable report.
 *
 * Everything is aggregate (no personal data) and read-only. Admin accounts and
 * admin test trials are excluded so they do not skew member numbers. A snapshot
 * is cached for a few minutes because the dashboard renders many widgets from it.
 */
class BusinessStatsService
{
    public const PERIODS = [3, 6, 12, 24];

    public const DEFAULT_MONTHS = 12;

    private const CACHE_TTL_SECONDS = 600;

    private string $driver = '';

    private Carbon $now;

    private Carbon $since;

    /** @var array<string, bool> */
    private array $schemaCache = [];

    /** Members whose paid plan is on now, for this snapshot (planHolders()). */
    private ?Collection $holders = null;

    /** The card subscriptions per plan, from the revenue section, for the plans table. */
    private array $cardsByPlan = [];

    public static function normalizeMonths(mixed $months): int
    {
        $months = (int) $months;

        return in_array($months, self::PERIODS, true) ? $months : self::DEFAULT_MONTHS;
    }

    public function snapshot(int $months = self::DEFAULT_MONTHS, bool $fresh = false): array
    {
        $months = self::normalizeMonths($months);
        $key = "business_stats:v3:{$months}";

        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, self::CACHE_TTL_SECONDS, fn () => $this->build($months));
    }

    public function forget(): void
    {
        foreach (self::PERIODS as $months) {
            Cache::forget("business_stats:v3:{$months}");
        }
        StripeRecurringRevenue::forget();
    }

    public function build(int $months = self::DEFAULT_MONTHS): array
    {
        $this->driver = DB::connection()->getDriverName();
        $this->now = Carbon::now();
        $this->since = $this->now->copy()->subMonths($months - 1)->startOfMonth();
        $this->holders = null;
        $this->cardsByPlan = [];

        $snapshot = [
            'meta' => [
                'generated_at' => $this->now->toIso8601String(),
                'app_env' => app()->environment(),
                'months' => $months,
                'since' => $this->since->toDateString(),
            ],
            'errors' => [],
        ];

        $sections = [
            'members' => fn () => $this->members(),
            'engagement' => fn () => $this->engagement(),
            'generations' => fn () => $this->generations(),
            'revenue' => fn () => $this->revenue(),
            'trials' => fn () => $this->trials(),
            'manual_payments' => fn () => $this->manualPayments(),
            'growth_channels' => fn () => $this->growthChannels(),
            'monthly' => fn () => $this->monthly(),
            'cohorts' => fn () => $this->cohorts(),
            'providers' => fn () => $this->providers(),
            'plans' => fn () => $this->plans(),
            'audience' => fn () => $this->audience(),
            'api_usage' => fn () => (new ApiUsageStats($this->driver, $this->now, $this->since))->build(),
        ];

        foreach ($sections as $name => $build) {
            try {
                $snapshot[$name] = $build();
            } catch (Throwable $e) {
                report($e);
                $snapshot[$name] = [];
                $snapshot['errors'][$name] = $e->getMessage();
            }
        }

        $snapshot['unit_economics'] = $this->unitEconomics($snapshot);

        return $snapshot;
    }

    /**
     * API cost against revenue and usage, from the sections already built.
     */
    private function unitEconomics(array $s): array
    {
        $cost30 = (float) ($s['api_usage']['totals_30d']['cost'] ?? 0);
        $mrr = ($s['revenue']['mrr_cents'] ?? 0) / 100;
        $active30 = (int) ($s['engagement']['active_30d'] ?? 0);
        $paying = (int) ($s['revenue']['members_on_paid_plans'] ?? 0);

        return [
            'api_cost_30d' => round($cost30, 2),
            'mrr_usd' => round($mrr, 2),
            'gross_margin_usd' => round($mrr - $cost30, 2),
            'gross_margin_rate' => $mrr > 0 ? round(($mrr - $cost30) / $mrr, 4) : null,
            'api_cost_per_active_member' => $active30 > 0 ? round(($s['api_usage']['totals_30d']['member_cost'] ?? 0) / $active30, 4) : 0,
            'revenue_per_paying_member' => $paying > 0 ? round($mrr / $paying, 2) : 0,
            'demo_cost_share' => $cost30 > 0 ? round(($s['api_usage']['totals_30d']['demo_cost'] ?? 0) / $cost30, 4) : 0,
        ];
    }

    // ---------------------------------------------------------------------
    // Sections
    // ---------------------------------------------------------------------

    private function members(): array
    {
        $total = $this->memberQuery()->count();
        $google = $this->hasColumn('users', 'google_id')
            ? $this->memberQuery()->whereNotNull('google_id')->count()
            : 0;

        return [
            'total' => $total,
            'new_today' => $this->memberQuery()->where('created_at', '>=', $this->now->copy()->startOfDay())->count(),
            'new_7d' => $this->memberQuery()->where('created_at', '>=', $this->now->copy()->subDays(7))->count(),
            'new_30d' => $this->memberQuery()->where('created_at', '>=', $this->now->copy()->subDays(30))->count(),
            'new_prev_30d' => $this->memberQuery()
                ->where('created_at', '>=', $this->now->copy()->subDays(60))
                ->where('created_at', '<', $this->now->copy()->subDays(30))
                ->count(),
            'email_verified' => $this->memberQuery()->whereNotNull('email_verified_at')->count(),
            'blocked' => $this->hasColumn('users', 'is_blocked') ? $this->memberQuery()->where('is_blocked', true)->count() : 0,
            'admins' => $this->hasColumn('users', 'is_admin') ? DB::table('users')->where('is_admin', true)->count() : 0,
            'signup_method' => [
                'Google' => $google,
                'Email / other' => max(0, $total - $google),
            ],
            'latest_signup_at' => $this->memberQuery()->max('created_at'),
        ];
    }

    private function engagement(): array
    {
        $total = max(1, $this->memberQuery()->count());
        $activated = $this->memberGenerations()->where('audio_generations.status', 'done')
            ->distinct()->count('audio_generations.user_id');

        $perMember = $this->memberGenerations()
            ->selectRaw('audio_generations.user_id as u, count(*) as n')
            ->groupBy('audio_generations.user_id')
            ->pluck('n')
            ->map(fn ($n) => (int) $n);

        $buckets = ['1' => 0, '2-5' => 0, '6-20' => 0, '21-100' => 0, '101+' => 0];
        foreach ($perMember as $n) {
            $key = match (true) {
                $n <= 1 => '1',
                $n <= 5 => '2-5',
                $n <= 20 => '6-20',
                $n <= 100 => '21-100',
                default => '101+',
            };
            $buckets[$key]++;
        }

        $topTenCount = max(1, (int) ceil($perMember->count() * 0.1));
        $active30 = $this->memberGenerations()
            ->where('audio_generations.created_at', '>=', $this->now->copy()->subDays(30))
            ->distinct()->count('audio_generations.user_id');
        $generations30 = $this->memberGenerations()
            ->where('audio_generations.created_at', '>=', $this->now->copy()->subDays(30))
            ->count();

        return [
            'activated' => $activated,
            'activation_rate' => $this->ratio($activated, $total),
            'active_today' => $this->memberGenerations()
                ->where('audio_generations.created_at', '>=', $this->now->copy()->startOfDay())
                ->distinct()->count('audio_generations.user_id'),
            'active_7d' => $this->memberGenerations()
                ->where('audio_generations.created_at', '>=', $this->now->copy()->subDays(7))
                ->distinct()->count('audio_generations.user_id'),
            'active_30d' => $active30,
            'generations_per_active_member_30d' => $active30 > 0 ? round($generations30 / $active30, 1) : 0,
            'generations_per_member' => $buckets,
            'top_10pct_share' => $this->ratio($perMember->sortDesc()->take($topTenCount)->sum(), $perMember->sum()),
        ];
    }

    private function generations(): array
    {
        $c = 'audio_generations.';
        $last30 = fn () => $this->memberGenerations()->where($c.'created_at', '>=', $this->now->copy()->subDays(30));
        $total30 = $last30()->count();

        $data = [
            'total' => $this->memberGenerations()->count(),
            'last_30d' => $total30,
            'failed_30d' => $last30()->where($c.'status', 'failed')->count(),
            'failure_rate_30d' => $this->ratio($last30()->where($c.'status', 'failed')->count(), $total30),
            'audio_minutes_30d' => round(((int) $last30()->sum($c.'duration_seconds')) / 60, 1),
            'billable_chars_30d' => (int) $last30()->sum($c.'billable_chars'),
            'est_cost_usd_30d' => round((float) $last30()->sum($c.'tts_cost_estimate_usd'), 2),
            'translate_share_30d' => $this->ratio($last30()->where($c.'translate', true)->count(), $total30),
            'by_voice_tier_30d' => $this->pairs($last30(), $c.'voice_tier'),
            'by_language_30d' => $this->pairs($last30(), $c.'target_language', 10),
            'by_status' => $this->pairs($this->memberGenerations(), $c.'status'),
        ];

        if ($this->hasColumn('audio_generations', 'performance_style')) {
            $data['by_style_30d'] = $this->pairs($last30(), "coalesce({$c}performance_style, 'neutral')", 10);
        }
        if ($this->hasTable('cloned_voices')) {
            $data['cloned_voices'] = DB::table('cloned_voices')->count();
        }
        if ($this->hasTable('blocked_generations')) {
            $data['blocked_generations_30d'] = DB::table('blocked_generations')
                ->where('created_at', '>=', $this->now->copy()->subDays(30))->count();
        }

        return $data;
    }

    /**
     * Who pays, and how much comes in each month.
     *
     * Paying members are the members whose paid plan is on right now and was
     * paid for, by card or by InstaPay / Vodafone Cash; members in a free
     * trial and plans an admin gave without a payment are counted beside them,
     * never as paying. MRR is what the card subscriptions really charge
     * (after discounts, at the price each member kept), read from Stripe;
     * only when Stripe cannot be read is it estimated from the plans' list
     * prices, and the dashboard says so.
     */
    private function revenue(): array
    {
        $memberIds = $this->memberQuery()->select('id');
        $members = $this->memberQuery()->count();
        $holders = $this->planHolders();
        $kinds = array_merge(['card' => 0, 'manual' => 0, 'trial' => 0, 'granted' => 0], $holders->countBy('kind')->all());
        $paying = $kinds['card'] + $kinds['manual'];

        $planNames = Plan::query()->pluck('name', 'id');
        $freeName = (string) (Plan::query()->where('price_cents', 0)->orderBy('id')->value('name') ?: 'Free');
        $byPlan = $holders->countBy(fn (array $h) => (string) ($planNames[$h['plan_id']] ?? 'Unknown plan'))->sortDesc()->all();

        $data = [
            'members_on_paid_plans' => $paying,
            'paid_conversion' => $this->ratio($paying, max(1, $members)),
            'plan_holders' => $kinds,
            'members_by_plan' => [$freeName => max(0, $members - $holders->count())] + $byPlan,
            'stripe_by_status' => [],
            'paying_stripe' => 0,
            'paying_by_plan' => [],
            'mrr_cents' => 0,
            'mrr_source' => 'none',
            'unmapped_prices' => 0,
            'arpu_cents' => 0,
            'canceled_30d' => 0,
            'recurring' => null,
        ];

        if (! $this->hasTable('billing_subscriptions')) {
            return $data;
        }

        $data['stripe_by_status'] = $this->pairs(
            DB::table('billing_subscriptions')->whereIn('user_id', $memberIds),
            'stripe_status'
        );

        $recurring = StripeRecurringRevenue::configured() ? app(StripeRecurringRevenue::class)->summary() : null;
        if ($recurring['ok'] ?? false) {
            // Members pay now on subscriptions that renew, and on ones that run until their paid time ends.
            $data['recurring'] = $recurring;
            $data['mrr_source'] = 'stripe';
            $data['paying_stripe'] = $recurring['kinds']['renewing']['count'] + $recurring['kinds']['cancelling']['count'];
            $data['mrr_cents'] = (int) (($recurring['kinds']['renewing']['monthly_cents']['usd'] ?? 0)
                + ($recurring['kinds']['cancelling']['monthly_cents']['usd'] ?? 0));
            foreach ($recurring['by_plan'] as $row) {
                $count = $row['renewing'] + $row['cancelling'];
                $data['paying_by_plan'][$row['plan'].' ('.$row['cycle'].')'] = $count;
                $this->addCards($row['plan_id'], $row['plan'], $row['cycle'], $count, (int) ($row['monthly_cents']['usd'] ?? 0));
            }
        } else {
            $data['recurring_error'] = $recurring['error'] ?? 'Stripe is not configured.';
            $data = array_merge($data, $this->listPriceMrr($memberIds));
        }
        arsort($data['paying_by_plan']);

        $data['arpu_cents'] = $data['paying_stripe'] > 0 ? (int) round($data['mrr_cents'] / $data['paying_stripe']) : 0;
        $data['canceled_30d'] = $this->endedPaidSubscriptions()
            ->whereBetween('billing_subscriptions.ends_at', [$this->now->copy()->subDays(30), $this->now])
            ->count();

        return $data;
    }

    /**
     * When Stripe cannot be read: the local copy of the card subscriptions at
     * their plans' list prices. Old prices still count for their plan; a
     * subscription set to cancel counts until it ends. Discounts are not known here.
     */
    private function listPriceMrr(Builder $memberIds): array
    {
        $prices = app(StripePricePlans::class);
        $data = ['mrr_source' => 'list_price', 'mrr_cents' => 0, 'paying_stripe' => 0, 'paying_by_plan' => [], 'unmapped_prices' => 0];
        $paying = DB::table('billing_subscriptions')
            ->whereIn('user_id', $memberIds)
            ->whereIn('stripe_status', ['active', 'past_due'])
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $this->now))
            ->pluck('stripe_price');

        foreach ($paying as $price) {
            $match = rescue(fn () => $prices->planFor((string) $price), null, false);
            $data['paying_stripe']++;
            if (! $match) {
                $data['unmapped_prices']++;
                $this->addCards(null, 'Unknown price', 'monthly', 1, 0);

                continue;
            }
            $plan = $match['plan'];
            $monthly = $match['cycle'] === 'yearly'
                ? (int) round(((int) $plan->price_cents * 12 * (100 - SiteSetting::yearlyDiscountPercent()) / 100) / 12)
                : (int) $plan->price_cents;
            $label = "{$plan->name} ({$match['cycle']})";
            $data['paying_by_plan'][$label] = ($data['paying_by_plan'][$label] ?? 0) + 1;
            $data['mrr_cents'] += $monthly;
            $this->addCards((int) $plan->id, (string) $plan->name, $match['cycle'], 1, $monthly);
        }

        return $data;
    }

    /**
     * Card subscriptions for the plans table: per plan (or per price no plan
     * knows), how many monthly and yearly, and what they bring each month.
     */
    private function addCards(?int $planId, string $name, string $cycle, int $count, int $monthlyCents): void
    {
        $key = $planId ?? 'other:'.$name;
        $row = &$this->cardsByPlan[$key];
        $row ??= ['name' => $name, 'monthly' => 0, 'yearly' => 0, 'mrr' => 0];
        $row[$cycle === 'yearly' ? 'yearly' : 'monthly'] += $count;
        $row['mrr'] += $monthlyCents;
        unset($row);
    }

    /**
     * Members whose paid plan is on right now, decided the way the app decides
     * it (PlanAccessService): their plan row is active and its period has not
     * ended, or a card subscription is still running for them, or a refused
     * renewal is in its grace days. Each is paid by card, paid by InstaPay /
     * Vodafone Cash, in a free trial, or given by an admin without a payment.
     *
     * @return Collection<int, array{plan_id: int, kind: string}> keyed by user id
     */
    private function planHolders(): Collection
    {
        if ($this->holders !== null) {
            return $this->holders;
        }

        $now = $this->now;
        $running = fn (array $statuses) => DB::table('billing_subscriptions')
            ->whereIn('stripe_status', $statuses)
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $now));
        $hasCards = $this->hasTable('billing_subscriptions');

        $rows = DB::table('subscriptions')
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->whereIn('subscriptions.user_id', $this->memberQuery()->select('id'))
            ->whereIn('subscriptions.id', DB::table('subscriptions')->selectRaw('max(id)')->groupBy('user_id'))
            ->where('subscriptions.status', 'active')
            ->where('plans.price_cents', '>', 0)
            ->where(function ($q) use ($now, $running, $hasCards) {
                $q->whereNull('subscriptions.current_period_end')
                    ->orWhere('subscriptions.current_period_end', '>=', $now)
                    ->orWhere(fn ($grace) => $grace->where('subscriptions.billing_payment_state', 'past_due')
                        ->where('subscriptions.billing_grace_ends_at', '>', $now));
                if ($hasCards) {
                    $q->orWhereIn('subscriptions.user_id', $running(['active', 'trialing', 'past_due'])->select('user_id'));
                }
            })
            ->get(['subscriptions.user_id', 'subscriptions.plan_id']);

        $card = $hasCards ? $running(['active', 'past_due'])->pluck('user_id')->flip() : collect();
        $trial = ($hasCards ? $running(['trialing'])->pluck('user_id') : collect())
            ->merge($this->hasTable('billing_trials')
                ? DB::table('billing_trials')->where('status', 'trialing')->whereNull('paid_at')->where('trial_ends_at', '>', $now)->pluck('user_id')
                : [])
            ->flip();
        $manual = $this->hasTable('manual_payment_requests')
            ? DB::table('manual_payment_requests')->where('status', 'approved')->get(['user_id', 'plan_id'])
                ->map(fn ($r) => $r->user_id.':'.$r->plan_id)->flip()
            : collect();

        return $this->holders = $rows->mapWithKeys(fn ($r) => [(int) $r->user_id => [
            'plan_id' => (int) $r->plan_id,
            'kind' => match (true) {
                isset($card[$r->user_id]) => 'card',
                isset($trial[$r->user_id]) => 'trial',
                isset($manual[$r->user_id.':'.$r->plan_id]) => 'manual',
                default => 'granted',
            },
        ]]);
    }

    /**
     * Card subscriptions that were paid for and have ended: not a checkout
     * whose first payment failed, and not a free trial cancelled before it charged.
     */
    private function endedPaidSubscriptions(): Builder
    {
        return $this->paidCardSubscriptions()->whereNotNull('billing_subscriptions.ends_at')
            ->where('billing_subscriptions.ends_at', '<=', $this->now);
    }

    /**
     * Card subscriptions that took a payment: every one except checkouts whose
     * first payment failed and free trials that ended without charging.
     */
    private function paidCardSubscriptions(): Builder
    {
        $query = DB::table('billing_subscriptions')
            ->whereIn('billing_subscriptions.user_id', $this->memberQuery()->select('id'))
            ->whereNotIn('billing_subscriptions.stripe_status', ['incomplete', 'incomplete_expired']);

        if ($this->hasTable('billing_trials')) {
            $query->whereNotExists(fn ($q) => $q->selectRaw('1')->from('billing_trials')
                ->whereColumn('billing_trials.stripe_subscription_id', 'billing_subscriptions.stripe_id')
                ->whereNull('billing_trials.paid_at'));
        }

        return $query;
    }

    /**
     * Members who have paid at least once: a card subscription that charged,
     * a paid trial, or an approved InstaPay / Vodafone Cash payment.
     *
     * @return Collection<int, true> keyed by user id
     */
    private function everPaidMembers(): Collection
    {
        $ids = $this->planHolders()->filter(fn (array $h) => in_array($h['kind'], ['card', 'manual'], true))->keys();
        if ($this->hasTable('billing_subscriptions')) {
            $ids = $ids->merge($this->paidCardSubscriptions()->pluck('billing_subscriptions.user_id'));
        }
        if ($this->hasTable('billing_trials')) {
            $ids = $ids->merge(DB::table('billing_trials')->whereNotNull('paid_at')->pluck('user_id'));
        }
        if ($this->hasTable('manual_payment_requests')) {
            $ids = $ids->merge(DB::table('manual_payment_requests')->where('status', 'approved')->pluck('user_id'));
        }

        return $ids->map(fn ($id) => (int) $id)->unique()->flip()->map(fn () => true);
    }

    private function trials(): array
    {
        if (! $this->hasTable('billing_trials')) {
            return [];
        }

        $trials = fn () => DB::table('billing_trials')
            ->where('is_admin_test', false)
            ->whereIn('user_id', $this->memberQuery()->select('id'));
        $paid = $trials()->whereNotNull('paid_at')->count();
        $finished = $trials()->where(fn ($q) => $q->whereNotNull('paid_at')
            ->orWhereNotNull('canceled_at')
            ->orWhereNotNull('charge_failed_at'))->count();

        return [
            'total' => $trials()->count(),
            'open' => $trials()->where('trial_ends_at', '>=', $this->now)
                ->whereNull('paid_at')->whereNull('canceled_at')->whereNull('charge_failed_at')->count(),
            'paid' => $paid,
            'canceled' => $trials()->whereNotNull('canceled_at')->count(),
            'charge_failed' => $trials()->whereNotNull('charge_failed_at')->count(),
            'conversion' => $this->ratio($paid, $finished),
            'revenue_cents' => (int) $trials()->whereNotNull('paid_at')->sum('amount_paid_cents'),
            'by_status' => $this->pairs($trials(), 'status'),
            'by_plan' => $this->pairs($trials()->join('plans', 'plans.id', '=', 'billing_trials.plan_id'), 'plans.name'),
        ];
    }

    private function manualPayments(): array
    {
        if (! $this->hasTable('manual_payment_requests')) {
            return [];
        }

        return [
            'by_status' => $this->pairs(DB::table('manual_payment_requests'), 'status'),
            'pending' => DB::table('manual_payment_requests')->whereIn('status', ['pending', 'submitted'])->count(),
            'approved_by_currency' => DB::table('manual_payment_requests')
                ->where('status', 'approved')
                ->selectRaw('currency as k, sum(expected_amount_minor) as n')
                ->groupBy('currency')
                ->pluck('n', 'k')
                ->map(fn ($minor) => round(((int) $minor) / 100, 2))
                ->all(),
            'by_method' => $this->pairs(DB::table('manual_payment_requests'), 'payment_method'),
        ];
    }

    private function growthChannels(): array
    {
        $data = [];

        if ($this->hasTable('demo_voice_generations')) {
            $data['demo_generations_total'] = DB::table('demo_voice_generations')->count();
            $data['demo_generations_30d'] = DB::table('demo_voice_generations')
                ->where('created_at', '>=', $this->now->copy()->subDays(30))->count();
            $data['demo_visitors'] = DB::table('demo_voice_generations')->distinct()->count('primary_identity_hash');
            if ($this->hasColumn('demo_voice_generations', 'user_id')) {
                // The demo needs an account, so the step that matters is demo to a payment.
                $demoMembers = DB::table('demo_voice_generations')
                    ->whereIn('user_id', $this->memberQuery()->select('id'))
                    ->distinct()->pluck('user_id')->map(fn ($id) => (int) $id);
                $paid = $this->everPaidMembers();
                $data['demo_members'] = $demoMembers->count();
                $data['demo_members_paid'] = $demoMembers->filter(fn (int $id) => isset($paid[$id]))->count();
                $data['demo_paid_rate'] = $this->ratio($data['demo_members_paid'], $data['demo_members']);
            }
        }

        if ($this->hasTable('referral_conversions')) {
            $data['referral_visits'] = $this->hasTable('referral_visits') ? DB::table('referral_visits')->count() : 0;
            $data['referral_conversions'] = $this->pairs(DB::table('referral_conversions'), 'status');
            $data['referrers'] = DB::table('referral_conversions')->distinct()->count('referrer_user_id');
        }

        if ($this->hasTable('contacts')) {
            $data['contact_messages_30d'] = DB::table('contacts')
                ->where('created_at', '>=', $this->now->copy()->subDays(30))->count();
        }

        return $data;
    }

    /**
     * One row per month: the backbone of the trend charts and the report.
     */
    private function monthly(): array
    {
        $rows = [];
        for ($m = $this->since->copy(); $m <= $this->now; $m->addMonth()) {
            $rows[$m->format('Y-m')] = [
                'month' => $m->format('Y-m'),
                'signups' => 0,
                'active_members' => 0,
                'generations' => 0,
                'failed_generations' => 0,
                'audio_minutes' => 0.0,
                'billable_chars' => 0,
                'est_cost_usd' => 0.0,
                'demo_generations' => 0,
                'stripe_started' => 0,
                'stripe_ended' => 0,
                'trials_started' => 0,
                'trials_paid' => 0,
                'manual_approved' => 0,
            ];
        }

        $fill = function (Builder $query, string $dateColumn, array $columns) use (&$rows) {
            $select = [$this->ym($dateColumn).' as m'];
            foreach ($columns as $alias => $expr) {
                $select[] = "{$expr} as {$alias}";
            }
            $result = $query->where($dateColumn, '>=', $this->since)
                ->selectRaw(implode(', ', $select))
                ->groupBy('m')
                ->get();
            foreach ($result as $r) {
                if (! isset($rows[$r->m])) {
                    continue;
                }
                foreach (array_keys($columns) as $alias) {
                    $rows[$r->m][$alias] = is_numeric($r->{$alias}) ? $r->{$alias} + 0 : 0;
                }
            }
        };

        $fill($this->memberQuery(), 'users.created_at', ['signups' => 'count(*)']);

        $c = 'audio_generations.';
        $fill($this->memberGenerations(), $c.'created_at', [
            'active_members' => "count(distinct {$c}user_id)",
            'generations' => 'count(*)',
            'failed_generations' => "sum(case when {$c}status = 'failed' then 1 else 0 end)",
            'audio_minutes' => "coalesce(sum({$c}duration_seconds), 0) / 60",
            'billable_chars' => "coalesce(sum({$c}billable_chars), 0)",
            'est_cost_usd' => "coalesce(sum({$c}tts_cost_estimate_usd), 0)",
        ]);

        if ($this->hasTable('demo_voice_generations')) {
            $fill(DB::table('demo_voice_generations'), 'created_at', ['demo_generations' => 'count(*)']);
        }

        $memberIds = $this->memberQuery()->select('id');
        if ($this->hasTable('billing_subscriptions')) {
            // Bought straight away (a trial that paid is counted under trials_paid), and paid ones that ended.
            $bought = $this->paidCardSubscriptions();
            if ($this->hasTable('billing_trials')) {
                $bought->whereNotExists(fn ($q) => $q->selectRaw('1')->from('billing_trials')
                    ->whereColumn('billing_trials.stripe_subscription_id', 'billing_subscriptions.stripe_id'));
            }
            $fill($bought, 'billing_subscriptions.created_at', ['stripe_started' => 'count(*)']);
            $fill($this->endedPaidSubscriptions(), 'billing_subscriptions.ends_at', ['stripe_ended' => 'count(*)']);
        }
        if ($this->hasTable('billing_trials')) {
            $trials = fn () => DB::table('billing_trials')->where('is_admin_test', false)->whereIn('user_id', $memberIds);
            $fill($trials(), 'created_at', ['trials_started' => 'count(*)']);
            $fill($trials()->whereNotNull('paid_at'), 'paid_at', ['trials_paid' => 'count(*)']);
        }
        if ($this->hasTable('manual_payment_requests')) {
            $fill(DB::table('manual_payment_requests')->where('status', 'approved')->whereNotNull('reviewed_at'), 'reviewed_at', ['manual_approved' => 'count(*)']);
        }

        return array_values(array_map(function (array $row) {
            $row['audio_minutes'] = round((float) $row['audio_minutes'], 1);
            $row['est_cost_usd'] = round((float) $row['est_cost_usd'], 2);

            return $row;
        }, $rows));
    }

    /**
     * Signup cohorts: of the members who joined in a month, how many ever generated
     * audio and how many were still generating one and two months later.
     */
    private function cohorts(): array
    {
        $signups = $this->memberQuery()->where('created_at', '>=', $this->since)
            ->selectRaw('id, '.$this->ym('created_at').' as m')
            ->get();

        $activeMonths = $this->memberGenerations()
            ->where('audio_generations.created_at', '>=', $this->since)
            ->selectRaw('audio_generations.user_id as u, '.$this->ym('audio_generations.created_at').' as m')
            ->distinct()
            ->get()
            ->groupBy('u')
            ->map(fn (Collection $rows) => $rows->pluck('m')->flip());

        $current = $this->now->format('Y-m');
        $rows = [];
        foreach ($signups->groupBy('m')->sortKeys() as $month => $members) {
            $m1 = Carbon::createFromFormat('Y-m-d', $month.'-01')->addMonth()->format('Y-m');
            $m2 = Carbon::createFromFormat('Y-m-d', $month.'-01')->addMonths(2)->format('Y-m');
            $ever = $inM1 = $inM2 = 0;
            foreach ($members as $member) {
                $seen = $activeMonths->get($member->id);
                if (! $seen) {
                    continue;
                }
                $ever++;
                $inM1 += isset($seen[$m1]) ? 1 : 0;
                $inM2 += isset($seen[$m2]) ? 1 : 0;
            }
            $count = $members->count();
            $rows[] = [
                'month' => $month,
                'signups' => $count,
                'ever_generated' => $ever,
                'activation_rate' => $this->ratio($ever, $count),
                'active_month_1' => $m1 <= $current ? $inM1 : null,
                'retention_month_1' => $m1 <= $current ? $this->ratio($inM1, $count) : null,
                'active_month_2' => $m2 <= $current ? $inM2 : null,
                'retention_month_2' => $m2 <= $current ? $this->ratio($inM2, $count) : null,
            ];
        }

        return $rows;
    }

    private function providers(): array
    {
        $c = 'audio_generations.';

        return $this->memberGenerations()
            ->where($c.'created_at', '>=', $this->now->copy()->subDays(30))
            ->selectRaw("coalesce({$c}tts_provider, 'unknown') as provider, count(*) as n,"
                ." avg({$c}tts_latency_ms) as latency,"
                ." sum(case when {$c}tts_fallback_used then 1 else 0 end) as fallbacks,"
                ." sum(case when {$c}status = 'failed' then 1 else 0 end) as failed,"
                ." coalesce(sum({$c}duration_seconds), 0) as seconds,"
                ." coalesce(sum({$c}tts_cost_estimate_usd), 0) as cost")
            ->groupBy('provider')
            ->orderByDesc('n')
            ->get()
            ->map(fn ($r) => [
                'provider' => $r->provider,
                'generations' => (int) $r->n,
                'avg_latency_s' => $r->latency === null ? null : round($r->latency / 1000, 1),
                'failure_rate' => $this->ratio((int) $r->failed, (int) $r->n),
                'fallback_rate' => $this->ratio((int) $r->fallbacks, (int) $r->n),
                'audio_minutes' => round(((int) $r->seconds) / 60, 1),
                'est_cost_usd' => round((float) $r->cost, 2),
            ])
            ->values()
            ->all();
    }

    /**
     * Who the members are: countries and regions, how they sign up, personal
     * or work email, and which Arabic dialects they ask for. Aggregates only.
     */
    private function audience(): array
    {
        $data = ['countries' => [], 'regions' => [], 'with_country' => 0, 'members' => $this->memberQuery()->count()];

        if ($this->hasColumn('users', 'country_code')) {
            $members = $this->memberQuery()
                ->selectRaw("coalesce(users.country_code, '') as c, count(*) as n")
                ->groupBy('c')->pluck('n', 'c');
            $active = $this->memberGenerations()
                ->where('audio_generations.created_at', '>=', $this->now->copy()->subDays(30))
                ->selectRaw("coalesce(users.country_code, '') as c, count(distinct audio_generations.user_id) as n")
                ->groupBy('c')->pluck('n', 'c');
            // Paying members only (card or InstaPay / Vodafone Cash), as in the revenue section.
            $payingIds = $this->planHolders()->filter(fn (array $h) => in_array($h['kind'], ['card', 'manual'], true))->keys()->all();
            $paying = $this->memberQuery()
                ->whereIn('users.id', $payingIds ?: [0])
                ->selectRaw("coalesce(users.country_code, '') as c, count(*) as n")
                ->groupBy('c')->pluck('n', 'c');

            $rows = [];
            foreach ($members as $code => $n) {
                $rows[] = [
                    'code' => $code === '' ? null : strtoupper($code),
                    'country' => \App\Support\Countries::name($code),
                    'region' => \App\Support\Countries::region($code),
                    'members' => (int) $n,
                    'active_30d' => (int) ($active[$code] ?? 0),
                    'paying' => (int) ($paying[$code] ?? 0),
                ];
            }
            usort($rows, fn ($a, $b) => [$a['code'] === null, -$a['members']] <=> [$b['code'] === null, -$b['members']]);
            $data['countries'] = $rows;
            $data['with_country'] = collect($rows)->whereNotNull('code')->sum('members');
            $data['country_count'] = collect($rows)->whereNotNull('code')->count();
            $data['arab_world_members'] = collect($rows)->filter(fn ($r) => \App\Support\Countries::isArabWorld($r['code']))->sum('members');

            $regions = [];
            foreach ($rows as $row) {
                $r = &$regions[$row['region']];
                $r ??= ['region' => $row['region'], 'members' => 0, 'active_30d' => 0, 'paying' => 0];
                $r['members'] += $row['members'];
                $r['active_30d'] += $row['active_30d'];
                $r['paying'] += $row['paying'];
                unset($r);
            }
            $data['regions'] = collect($regions)->sortBy(fn ($r) => [$r['region'] === \App\Support\Countries::UNKNOWN, -$r['members']])->values()->all();
        }

        // Personal webmail or a work / school domain, from the domain only.
        $personal = ['gmail.com', 'googlemail.com', 'hotmail.com', 'outlook.com', 'live.com', 'msn.com', 'yahoo.com',
            'icloud.com', 'me.com', 'mac.com', 'aol.com', 'proton.me', 'protonmail.com', 'yandex.com', 'mail.ru', 'gmx.com', 'zoho.com'];
        $emailTypes = ['Personal email' => 0, 'Work email' => 0];
        foreach ($this->memberQuery()->pluck('email') as $email) {
            $domain = strtolower((string) substr(strrchr((string) $email, '@') ?: '', 1));
            if ($domain === '') {
                continue;
            }
            $isPersonal = in_array($domain, $personal, true)
                || preg_match('/^(hotmail|outlook|live|yahoo|windowslive)\.[a-z.]+$/', $domain);
            $emailTypes[$isPersonal ? 'Personal email' : 'Work email']++;
        }
        $data['email_types'] = $emailTypes;

        if ($this->hasColumn('audio_generations', 'dialect')) {
            $labels = collect(\App\Support\TtsStudioConfig::dialectCatalogForLanguage('ar'))
                ->mapWithKeys(fn (array $row) => [strtolower((string) $row['value']) => (string) $row['label']]);
            $data['dialects'] = collect($this->pairs(
                $this->memberGenerations()
                    ->where('audio_generations.created_at', '>=', $this->since)
                    ->whereNotNull('audio_generations.dialect')
                    ->where('audio_generations.dialect', 'like', 'ar%'),
                'audio_generations.dialect',
                8
            ))->mapWithKeys(fn ($n, $code) => [$labels[strtolower((string) $code)] ?? strtoupper((string) $code) => $n])->all();
        }

        return $data;
    }

    /**
     * Each plan: who is on it now, who pays for it, its card subscriptions and
     * what they bring each month (from the revenue section: Stripe, or the
     * list-price estimate when Stripe cannot be read).
     */
    private function plans(): array
    {
        $holders = $this->planHolders();
        $members = $this->memberQuery()->count();

        $cards = $this->cardsByPlan;

        $rows = Plan::query()->subscriptionPlans()->orderBy('price_cents')->get()->map(function (Plan $plan) use ($holders, $members, $cards) {
            $on = $holders->where('plan_id', $plan->id);

            return [
                'plan' => $plan->name,
                'active' => (bool) $plan->is_active,
                'price_monthly_usd' => round(((int) $plan->price_cents) / 100, 2),
                'members' => (int) $plan->price_cents > 0 ? $on->count() : max(0, $members - $holders->count()),
                'paying' => $on->whereIn('kind', ['card', 'manual'])->count(),
                'stripe_monthly' => (int) ($cards[$plan->id]['monthly'] ?? 0),
                'stripe_yearly' => (int) ($cards[$plan->id]['yearly'] ?? 0),
                'mrr_usd' => round(((int) ($cards[$plan->id]['mrr'] ?? 0)) / 100, 2),
            ];
        })->values();

        // Card subscriptions on a price no plan knows: listed, never dropped.
        foreach ($cards as $key => $card) {
            if (is_string($key)) {
                $rows->push([
                    'plan' => $card['name'].' (price not linked to a plan)',
                    'active' => false,
                    'price_monthly_usd' => null,
                    'members' => 0,
                    'paying' => 0,
                    'stripe_monthly' => (int) ($card['monthly'] ?? 0),
                    'stripe_yearly' => (int) ($card['yearly'] ?? 0),
                    'mrr_usd' => round(((int) ($card['mrr'] ?? 0)) / 100, 2),
                ]);
            }
        }

        return $rows->all();
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function memberQuery(): Builder
    {
        $query = DB::table('users');
        if ($this->hasColumn('users', 'is_admin')) {
            $query->where('users.is_admin', false);
        }

        return $query;
    }

    private function memberGenerations(): Builder
    {
        $query = DB::table('audio_generations')->join('users', 'users.id', '=', 'audio_generations.user_id');
        if ($this->hasColumn('users', 'is_admin')) {
            $query->where('users.is_admin', false);
        }

        return $query;
    }

    /**
     * @return array<string, int>
     */
    private function pairs(Builder $query, string $expression, ?int $limit = null): array
    {
        $query = (clone $query)
            ->selectRaw("{$expression} as k, count(*) as n")
            ->groupByRaw($expression)
            ->orderByDesc('n');
        if ($limit) {
            $query->limit($limit);
        }

        return $query->get()
            ->mapWithKeys(fn ($r) => [(string) ($r->k ?? 'unknown') => (int) $r->n])
            ->all();
    }

    private function ym(string $column): string
    {
        return match ($this->driver) {
            'sqlite' => "strftime('%Y-%m', {$column})",
            'pgsql' => "to_char({$column}, 'YYYY-MM')",
            default => "DATE_FORMAT({$column}, '%Y-%m')",
        };
    }

    private function ratio(int|float $part, int|float $whole): float
    {
        return $whole > 0 ? round($part / $whole, 4) : 0.0;
    }

    private function hasTable(string $table): bool
    {
        return $this->schemaCache[$table] ??= Schema::hasTable($table);
    }

    private function hasColumn(string $table, string $column): bool
    {
        return $this->schemaCache["{$table}.{$column}"] ??= $this->hasTable($table) && Schema::hasColumn($table, $column);
    }
}
