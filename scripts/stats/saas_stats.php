<?php

/*
 * Read-only SaaS statistics snapshot.
 *
 * Boots the Laravel app in the current directory and prints one JSON document of
 * aggregate numbers (no emails, names or other personal data). Nothing is written:
 * on MySQL the session is switched to READ ONLY before any query runs.
 *
 * Run from the app root, either directly or piped over SSH:
 *   php scripts/stats/saas_stats.php
 *   ssh user@host 'cd ~/public_html && STATS_MONTHS=12 php' < scripts/stats/saas_stats.php
 */

use App\Models\Plan;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

ini_set('display_errors', 'stderr');

$root = getcwd();
if (! is_file($root.'/artisan')) {
    fwrite(STDERR, "Run this from the Laravel app root (no artisan file in {$root}).\n");
    exit(1);
}

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$driver = DB::connection()->getDriverName();
if (in_array($driver, ['mysql', 'mariadb'], true)) {
    DB::statement('SET SESSION TRANSACTION READ ONLY');
}

$months = max(1, min(36, (int) (getenv('STATS_MONTHS') ?: 12)));
$now = Carbon::now();
$since = $now->copy()->subMonths($months - 1)->startOfMonth();

$out = [
    'meta' => [
        'generated_at' => $now->toIso8601String(),
        'app_env' => app()->environment(),
        'app_url' => config('app.url'),
        'db_driver' => $driver,
        'months' => $months,
        'since' => $since->toDateString(),
    ],
    'errors' => [],
];

$ym = fn (string $col): string => $driver === 'sqlite'
    ? "strftime('%Y-%m', {$col})"
    : "DATE_FORMAT({$col}, '%Y-%m')";

$has = fn (string $table, ?string $column = null): bool => $column === null
    ? Schema::hasTable($table)
    : Schema::hasTable($table) && Schema::hasColumn($table, $column);

// Run one section; a failure is recorded instead of aborting the whole report.
$section = function (string $name, callable $fn) use (&$out) {
    try {
        $out[$name] = $fn();
    } catch (Throwable $e) {
        $out['errors'][$name] = get_class($e).': '.$e->getMessage();
    }
};

$pairs = fn ($rows, string $key = 'k', string $val = 'n') => collect($rows)
    ->mapWithKeys(fn ($r) => [(string) ($r->{$key} ?? 'null') => is_numeric($r->{$val}) ? $r->{$val} + 0 : $r->{$val}])
    ->all();

// Members only: admins and staff accounts would skew every number.
$members = function () use ($has) {
    $q = DB::table('users');
    if ($has('users', 'is_admin')) {
        $q->where('users.is_admin', false);
    }

    return $q;
};

$memberGenerations = function () use ($has) {
    $q = DB::table('audio_generations')->join('users', 'users.id', '=', 'audio_generations.user_id');
    if ($has('users', 'is_admin')) {
        $q->where('users.is_admin', false);
    }

    return $q;
};

$section('users', function () use ($members, $has, $ym, $since, $now, $pairs) {
    $data = [
        'total_members' => $members()->count(),
        'admins' => $has('users', 'is_admin') ? DB::table('users')->where('is_admin', true)->count() : null,
        'email_verified' => $members()->whereNotNull('email_verified_at')->count(),
        'new_last_7d' => $members()->where('created_at', '>=', $now->copy()->subDays(7))->count(),
        'new_last_30d' => $members()->where('created_at', '>=', $now->copy()->subDays(30))->count(),
        'latest_signup_at' => $members()->max('created_at'),
        'signups_by_month' => $pairs($members()
            ->where('created_at', '>=', $since)
            ->selectRaw($ym('created_at').' as k, count(*) as n')
            ->groupBy('k')->orderBy('k')->get()),
    ];
    if ($has('users', 'is_blocked')) {
        $data['blocked'] = $members()->where('is_blocked', true)->count();
    }
    if ($has('users', 'google_id')) {
        $data['google_linked'] = $members()->whereNotNull('google_id')->count();
    }
    if ($has('users', 'phone_number')) {
        $data['with_phone'] = $members()->whereNotNull('phone_number')->count();
    }
    if ($has('users', 'auth_provider')) {
        $data['by_auth_provider'] = $pairs($members()
            ->selectRaw("coalesce(auth_provider, 'email') as k, count(*) as n")
            ->groupBy('k')->orderByDesc('n')->get());
    }

    return $data;
});

$section('engagement', function () use ($members, $memberGenerations, $ym, $since, $now, $pairs) {
    $totalMembers = max(1, $members()->count());
    $activated = $memberGenerations()->where('audio_generations.status', 'done')
        ->distinct()->count('audio_generations.user_id');

    // Generations per member, bucketed, to see how concentrated usage is.
    $perUser = $memberGenerations()
        ->selectRaw('audio_generations.user_id as u, count(*) as n')
        ->groupBy('audio_generations.user_id')->pluck('n');
    $buckets = ['1' => 0, '2-5' => 0, '6-20' => 0, '21-100' => 0, '101+' => 0];
    foreach ($perUser as $n) {
        $key = $n <= 1 ? '1' : ($n <= 5 ? '2-5' : ($n <= 20 ? '6-20' : ($n <= 100 ? '21-100' : '101+')));
        $buckets[$key]++;
    }
    $topTenShare = $perUser->sortDesc()->take(max(1, (int) ceil($perUser->count() * 0.1)))->sum()
        / max(1, $perUser->sum());

    // Cohorts: of members who signed up in month M, how many ever generated,
    // and how many generated again in the month after signup (M+1).
    $signups = $members()->where('created_at', '>=', $since)
        ->selectRaw('id, '.$ym('created_at').' as m')->get();
    $activeMonths = $memberGenerations()->where('audio_generations.created_at', '>=', $since)
        ->selectRaw('audio_generations.user_id as u, '.$ym('audio_generations.created_at').' as m')
        ->distinct()->get()->groupBy('u')->map(fn ($rows) => $rows->pluck('m')->flip());
    $cohorts = [];
    foreach ($signups->groupBy('m') as $month => $rows) {
        $next = Carbon::createFromFormat('Y-m-d', $month.'-01')->addMonth()->format('Y-m');
        $everActive = 0;
        $activeNext = 0;
        foreach ($rows as $r) {
            $seen = $activeMonths->get($r->id);
            if ($seen) {
                $everActive++;
                if (isset($seen[$next])) {
                    $activeNext++;
                }
            }
        }
        $cohorts[$month] = [
            'signups' => $rows->count(),
            'ever_generated' => $everActive,
            'active_month_after' => $next <= $now->format('Y-m') ? $activeNext : null,
        ];
    }
    ksort($cohorts);

    return [
        'activated_members' => $activated,
        'activation_rate' => round($activated / $totalMembers, 4),
        'active_last_7d' => $memberGenerations()->where('audio_generations.created_at', '>=', $now->copy()->subDays(7))
            ->distinct()->count('audio_generations.user_id'),
        'active_last_30d' => $memberGenerations()->where('audio_generations.created_at', '>=', $now->copy()->subDays(30))
            ->distinct()->count('audio_generations.user_id'),
        'monthly_active_members' => $pairs($memberGenerations()
            ->where('audio_generations.created_at', '>=', $since)
            ->selectRaw($ym('audio_generations.created_at').' as k, count(distinct audio_generations.user_id) as n')
            ->groupBy('k')->orderBy('k')->get()),
        'generations_per_member' => $buckets,
        'top_10pct_members_share_of_generations' => round($topTenShare, 4),
        'signup_cohorts' => $cohorts,
    ];
});

$section('generations', function () use ($memberGenerations, $has, $ym, $since, $now, $pairs) {
    $c = 'audio_generations.';
    $monthly = $memberGenerations()->where($c.'created_at', '>=', $since)
        ->selectRaw($ym($c.'created_at').' as m, count(*) as total,'
            ." sum(case when {$c}status = 'done' then 1 else 0 end) as done,"
            ." sum(case when {$c}status = 'failed' then 1 else 0 end) as failed,"
            ." coalesce(sum({$c}billable_chars), 0) as billable_chars,"
            ." coalesce(sum({$c}duration_seconds), 0) as audio_seconds,"
            ." coalesce(sum({$c}tts_cost_estimate_usd), 0) as est_cost_usd")
        ->groupBy('m')->orderBy('m')->get()
        ->mapWithKeys(fn ($r) => [$r->m => [
            'total' => (int) $r->total,
            'done' => (int) $r->done,
            'failed' => (int) $r->failed,
            'billable_chars' => (int) $r->billable_chars,
            'audio_minutes' => round($r->audio_seconds / 60, 1),
            'est_cost_usd' => round((float) $r->est_cost_usd, 2),
        ]]);

    $last30 = fn () => $memberGenerations()->where($c.'created_at', '>=', $now->copy()->subDays(30));

    $data = [
        'total' => $memberGenerations()->count(),
        'by_status' => $pairs($memberGenerations()->selectRaw($c.'status as k, count(*) as n')->groupBy('k')->get()),
        'by_month' => $monthly,
        'last_30d_by_provider' => $last30()
            ->selectRaw("coalesce({$c}tts_provider, 'unknown') as provider, count(*) as n,"
                ." avg({$c}tts_latency_ms) as avg_latency_ms,"
                ." sum(case when {$c}tts_fallback_used then 1 else 0 end) as fallbacks,"
                ." sum(case when {$c}status = 'failed' then 1 else 0 end) as failed,"
                ." coalesce(sum({$c}tts_cost_estimate_usd), 0) as est_cost_usd")
            ->groupBy('provider')->orderByDesc('n')->get()
            ->map(fn ($r) => [
                'provider' => $r->provider,
                'generations' => (int) $r->n,
                'avg_latency_ms' => $r->avg_latency_ms === null ? null : (int) round($r->avg_latency_ms),
                'fallback_rate' => round($r->fallbacks / max(1, $r->n), 4),
                'failure_rate' => round($r->failed / max(1, $r->n), 4),
                'est_cost_usd' => round((float) $r->est_cost_usd, 2),
            ])->values(),
        'last_30d_by_voice_tier' => $pairs($last30()->selectRaw($c.'voice_tier as k, count(*) as n')->groupBy('k')->orderByDesc('n')->get()),
        'last_30d_top_target_languages' => $pairs($last30()->selectRaw($c.'target_language as k, count(*) as n')->groupBy('k')->orderByDesc('n')->limit(10)->get()),
        'last_30d_translate_share' => round($last30()->where($c.'translate', true)->count() / max(1, $last30()->count()), 4),
        'last_30d_dialogue_share' => round($last30()->where($c.'is_dialogue', true)->count() / max(1, $last30()->count()), 4),
    ];
    if ($has('audio_generations', 'performance_style')) {
        $data['last_30d_top_performance_styles'] = $pairs($last30()
            ->selectRaw("coalesce({$c}performance_style, 'none') as k, count(*) as n")
            ->groupBy('k')->orderByDesc('n')->limit(10)->get());
    }

    return $data;
});

$section('plans', fn () => Plan::query()->orderBy('price_cents')->get()->map(fn (Plan $p) => [
    'id' => $p->id,
    'slug' => $p->slug,
    'name' => $p->name,
    'is_active' => (bool) $p->is_active,
    'price_cents_monthly' => (int) $p->price_cents,
    'price_cents_yearly' => rescue(fn () => $p->getEffectiveYearlyPriceCents(), null, false),
])->values());

$section('subscriptions', function () use ($members, $has, $ym, $since, $pairs) {
    $memberIds = $members()->select('id');

    // App-side subscription rows (includes free plans).
    $data = [
        'app_active_by_plan' => $pairs(DB::table('subscriptions')
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->whereIn('subscriptions.user_id', $memberIds)
            ->where('subscriptions.status', 'active')
            ->selectRaw('plans.slug as k, count(*) as n')->groupBy('k')->orderByDesc('n')->get()),
        'app_by_status' => $pairs(DB::table('subscriptions')->whereIn('user_id', $memberIds)
            ->selectRaw('status as k, count(*) as n')->groupBy('k')->get()),
    ];
    if ($has('subscriptions', 'billing_payment_state')) {
        $data['app_by_payment_state'] = $pairs(DB::table('subscriptions')->whereIn('user_id', $memberIds)
            ->selectRaw('billing_payment_state as k, count(*) as n')->groupBy('k')->get());
    }

    if (! $has('billing_subscriptions')) {
        return $data;
    }

    // Stripe subscriptions mirrored by Cashier.
    $data['stripe_by_status'] = $pairs(DB::table('billing_subscriptions')->whereIn('user_id', $memberIds)
        ->selectRaw('stripe_status as k, count(*) as n')->groupBy('k')->get());
    $data['stripe_started_by_month'] = $pairs(DB::table('billing_subscriptions')->whereIn('user_id', $memberIds)
        ->where('created_at', '>=', $since)
        ->selectRaw($ym('created_at').' as k, count(*) as n')->groupBy('k')->orderBy('k')->get());
    $data['stripe_ending_by_month'] = $pairs(DB::table('billing_subscriptions')->whereIn('user_id', $memberIds)
        ->whereNotNull('ends_at')->where('ends_at', '>=', $since)
        ->selectRaw($ym('ends_at').' as k, count(*) as n')->groupBy('k')->orderBy('k')->get());

    // List-price MRR from paying Stripe subscriptions (ignores coupons and taxes).
    $priceMap = [];
    foreach (Plan::all() as $plan) {
        if ($plan->stripe_price_monthly) {
            $priceMap[$plan->stripe_price_monthly] = [$plan->slug, 'monthly', (int) $plan->price_cents];
        }
        if ($plan->stripe_price_yearly) {
            $yearly = rescue(fn () => $plan->getEffectiveYearlyPriceCents(), (int) $plan->price_cents * 12, false);
            $priceMap[$plan->stripe_price_yearly] = [$plan->slug, 'yearly', (int) round($yearly / 12)];
        }
    }
    $paying = DB::table('billing_subscriptions')->whereIn('user_id', $memberIds)
        ->whereIn('stripe_status', ['active', 'past_due'])
        ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
        ->pluck('stripe_price');
    $mrr = 0;
    $byPlan = [];
    $unknownPrices = 0;
    foreach ($paying as $price) {
        if (! isset($priceMap[$price])) {
            $unknownPrices++;

            continue;
        }
        [$slug, $cycle, $monthlyCents] = $priceMap[$price];
        $key = "{$slug} ({$cycle})";
        $byPlan[$key] = ($byPlan[$key] ?? 0) + 1;
        $mrr += $monthlyCents;
    }
    arsort($byPlan);
    $data['paying_stripe_subscriptions'] = $paying->count();
    $data['paying_by_plan_and_cycle'] = $byPlan;
    $data['paying_with_unmapped_price'] = $unknownPrices;
    $data['est_mrr_cents_list_price'] = $mrr;

    return $data;
});

$section('trials', function () use ($has, $members, $ym, $since, $pairs) {
    if (! $has('billing_trials')) {
        return null;
    }
    $trials = fn () => DB::table('billing_trials')->where('is_admin_test', false)
        ->whereIn('user_id', $members()->select('id'));
    $started = $trials()->whereNotNull('started_at')->count();
    $paid = $trials()->whereNotNull('paid_at')->count();
    $ended = $trials()->whereNotNull('trial_ends_at')->where('trial_ends_at', '<', now())->count();

    return [
        'total' => $trials()->count(),
        'started' => $started,
        'paid' => $paid,
        'canceled' => $trials()->whereNotNull('canceled_at')->count(),
        'charge_failed' => $trials()->whereNotNull('charge_failed_at')->count(),
        'currently_open' => $trials()->whereNotNull('trial_ends_at')->where('trial_ends_at', '>=', now())
            ->whereNull('paid_at')->whereNull('canceled_at')->count(),
        'paid_over_started' => round($paid / max(1, $started), 4),
        'paid_over_ended' => round($trials()->whereNotNull('paid_at')->where('trial_ends_at', '<', now())->count() / max(1, $ended), 4),
        'by_status' => $pairs($trials()->selectRaw('status as k, count(*) as n')->groupBy('k')->orderByDesc('n')->get()),
        'by_billing_cycle' => $has('billing_trials', 'billing_cycle')
            ? $pairs($trials()->selectRaw('billing_cycle as k, count(*) as n')->groupBy('k')->get())
            : null,
        'by_plan' => $pairs($trials()->join('plans', 'plans.id', '=', 'billing_trials.plan_id')
            ->selectRaw('plans.slug as k, count(*) as n')->groupBy('k')->orderByDesc('n')->get()),
        'started_by_month' => $pairs($trials()->where('created_at', '>=', $since)
            ->selectRaw($ym('created_at').' as k, count(*) as n')->groupBy('k')->orderBy('k')->get()),
        'paid_by_month' => $pairs($trials()->whereNotNull('paid_at')->where('paid_at', '>=', $since)
            ->selectRaw($ym('paid_at').' as k, count(*) as n')->groupBy('k')->orderBy('k')->get()),
        'with_gclid' => $has('billing_trials', 'gclid') ? $trials()->whereNotNull('gclid')->count() : null,
    ];
});

$section('manual_payments', function () use ($has, $ym, $since, $pairs) {
    if (! $has('manual_payment_requests')) {
        return null;
    }

    return [
        'by_status' => $pairs(DB::table('manual_payment_requests')
            ->selectRaw('status as k, count(*) as n')->groupBy('k')->get()),
        'approved_amount_minor_by_currency' => $pairs(DB::table('manual_payment_requests')->where('status', 'approved')
            ->selectRaw('currency as k, sum(expected_amount_minor) as n')->groupBy('k')->get()),
        'approved_by_month' => $pairs(DB::table('manual_payment_requests')->where('status', 'approved')
            ->whereNotNull('reviewed_at')->where('reviewed_at', '>=', $since)
            ->selectRaw($ym('reviewed_at').' as k, count(*) as n')->groupBy('k')->orderBy('k')->get()),
        'by_payment_method' => $pairs(DB::table('manual_payment_requests')
            ->selectRaw('payment_method as k, count(*) as n')->groupBy('k')->orderByDesc('n')->get()),
    ];
});

// The one-time premium pack, side by side with Starter.
$section('char_packs', function () use ($has, $ym, $since, $now, $pairs) {
    if (! $has('char_pack_purchases')) {
        return null;
    }

    $paid = fn () => DB::table('char_pack_purchases')->where('status', 'paid');
    $buyerIds = $paid()->distinct()->pluck('user_id');

    return [
        'by_status' => $pairs(DB::table('char_pack_purchases')
            ->selectRaw('status as k, count(*) as n')->groupBy('k')->get()),
        'paid_by_method' => $pairs($paid()->selectRaw('method as k, count(*) as n')->groupBy('k')->get()),
        'paid_by_month' => $pairs($paid()->whereNotNull('paid_at')->where('paid_at', '>=', $since)
            ->selectRaw($ym('paid_at').' as k, count(*) as n')->groupBy('k')->orderBy('k')->get()),
        'revenue_minor_by_currency' => $pairs($paid()->selectRaw('currency as k, sum(amount_minor) as n')->groupBy('k')->get()),
        'buyers' => $buyerIds->count(),
        'repeat_buyers' => DB::table('char_pack_purchases')->where('status', 'paid')
            ->select('user_id')->groupBy('user_id')->havingRaw('count(*) > 1')->get()->count(),
        // Pack buyers now on a paid monthly plan: the pack's job is to lead there.
        'buyers_now_on_paid_plan' => $buyerIds->isEmpty() ? 0 : DB::table('subscriptions')
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->whereIn('subscriptions.user_id', $buyerIds)
            ->where('subscriptions.status', 'active')
            ->where('plans.price_cents', '>', 0)
            ->where('plans.kind', '!=', 'pack')
            ->where(fn ($q) => $q->whereNull('subscriptions.current_period_end')->orWhere('subscriptions.current_period_end', '>=', $now))
            ->distinct()->count('subscriptions.user_id'),
        'active_packs' => $has('char_pack_wallets') ? DB::table('char_pack_wallets')
            ->where('expires_at', '>', $now)->whereColumn('chars_total', '>', 'chars_used')->count() : null,
        'chars_bought' => (int) $paid()->sum('chars'),
        'chars_generated_from_packs' => $has('audio_generations', 'billed_to_pack') ? (int) DB::table('audio_generations')
            ->where('billed_to_pack', true)->where('status', 'done')->sum('billable_chars') : null,
        'members_without_free_monthly_chars' => $has('users', 'free_monthly_chars')
            ? DB::table('users')->where('free_monthly_chars', false)->where('is_admin', false)->count() : null,
    ];
});

$section('referrals', function () use ($has, $pairs) {
    if (! $has('referral_conversions')) {
        return null;
    }

    return [
        'visits' => $has('referral_visits') ? DB::table('referral_visits')->count() : null,
        'referrers_with_conversions' => DB::table('referral_conversions')->distinct()->count('referrer_user_id'),
        'conversions_by_status' => $pairs(DB::table('referral_conversions')
            ->selectRaw('status as k, count(*) as n')->groupBy('k')->get()),
        'reward_chars_approved' => (int) DB::table('referral_conversions')->where('status', 'approved')->sum('reward_chars'),
    ];
});

$section('demo', function () use ($has, $ym, $since, $pairs) {
    if (! $has('demo_voice_generations')) {
        return null;
    }
    $data = [
        'generations_total' => DB::table('demo_voice_generations')->count(),
        'generations_by_month' => $pairs(DB::table('demo_voice_generations')->where('created_at', '>=', $since)
            ->selectRaw($ym('created_at').' as k, count(*) as n')->groupBy('k')->orderBy('k')->get()),
        'distinct_visitors' => DB::table('demo_voice_generations')->distinct()->count('primary_identity_hash'),
    ];
    if ($has('demo_voice_generations', 'user_id')) {
        $data['visitors_later_linked_to_account'] = DB::table('demo_voice_generations')
            ->whereNotNull('user_id')->distinct()->count('user_id');
    }

    return $data;
});

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
