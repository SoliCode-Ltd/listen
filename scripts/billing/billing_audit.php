<?php

/*
 * Read-only billing audit: compares every trial, Stripe subscription and the
 * plan the site grants, and lists anything that disagrees. GET requests to
 * Stripe and SELECTs only; nothing is changed. Output has ids, no names,
 * emails or card details.
 */

use App\Models\BillingSubscription;
use App\Models\BillingTrial;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;

ini_set('display_errors', 'stderr');
$root = getcwd();
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$secret = (string) config('services.stripe.secret');
$get = fn (string $path, array $q = []) => Http::withToken($secret)->timeout(30)->get('https://api.stripe.com/v1/'.$path, $q)->json();

$out = ['stripe_mode' => preg_match('/^(sk|rk)_live/', $secret) ? 'live' : 'test'];
$out['trial_settings'] = [];
foreach (['billing_trial_enabled', 'billing_trial_days', 'billing_trial_plan_slugs', 'billing_trial_cycles', 'billing_trial_quota_plan_slug', 'billing_grace_days'] as $key) {
    $out['trial_settings'][$key] = SiteSetting::get($key, null);
}
$out['plans'] = Plan::query()->orderBy('price_cents')->get(['id', 'slug', 'price_cents', 'is_active', 'stripe_price_monthly', 'stripe_price_yearly'])->toArray();
$priceToSlug = [];
foreach ($out['plans'] as $p) {
    foreach (['stripe_price_monthly' => 'monthly', 'stripe_price_yearly' => 'yearly'] as $col => $cycle) {
        if (! empty($p[$col])) {
            $priceToSlug[$p[$col]] = $p['slug'].'/'.$cycle;
        }
    }
}

// Every Stripe subscription that is not finished.
$stripeSubs = [];
foreach (['active', 'trialing', 'past_due', 'unpaid', 'incomplete'] as $status) {
    $after = null;
    do {
        $page = $get('subscriptions', array_filter(['status' => $status, 'limit' => 100, 'starting_after' => $after]));
        foreach ($page['data'] ?? [] as $s) {
            $price = $s['items']['data'][0]['price']['id'] ?? '';
            $stripeSubs[$s['id']] = [
                'id' => $s['id'], 'customer' => $s['customer'], 'status' => $s['status'],
                'price' => $priceToSlug[$price] ?? $price, 'interval' => $s['items']['data'][0]['price']['recurring']['interval'] ?? null,
                'trial_end' => $s['trial_end'] ? date('c', $s['trial_end']) : null,
                'cancel_at_period_end' => $s['cancel_at_period_end'] ?? false,
                'pending_update' => ! empty($s['pending_update']),
                'metadata_cycle' => $s['metadata']['listen_billing_cycle'] ?? null,
            ];
            $after = $s['id'];
        }
    } while (! empty($page['has_more']));
}
$out['stripe_open_subscriptions'] = count($stripeSubs);

$problems = [];
$usersByCustomer = User::query()->whereNotNull('stripe_id')->pluck('id', 'stripe_id');

foreach ($stripeSubs as $s) {
    $userId = $usersByCustomer[$s['customer']] ?? null;
    if (! $userId) {
        $problems[] = ['type' => 'stripe_subscription_without_site_user', 'sub' => $s];
        continue;
    }
    if ($s['metadata_cycle'] && $s['interval'] && ($s['metadata_cycle'] === 'yearly') !== ($s['interval'] === 'year')) {
        $problems[] = ['type' => 'trial_cycle_mismatch (asked '.$s['metadata_cycle'].', Stripe bills per '.$s['interval'].')', 'user_id' => $userId, 'sub' => $s];
    }
    $local = BillingSubscription::query()->where('stripe_id', $s['id'])->first();
    if (! $local || $local->stripe_status !== $s['status']) {
        $problems[] = ['type' => 'local_status_differs', 'user_id' => $userId, 'stripe' => $s['status'], 'local' => $local?->stripe_status];
    }
    $plan = app(\App\Services\PlanAccessService::class)->resolveActivePlanForUserId((int) $userId);
    $paidOrTrial = in_array($s['status'], ['active', 'trialing'], true);
    if ($paidOrTrial && ($plan?->price_cents ?? 0) <= 0) {
        $problems[] = ['type' => 'paying_or_trialing_but_site_gives_free', 'user_id' => $userId, 'sub' => $s];
    }
    if (! $paidOrTrial && ($plan?->price_cents ?? 0) > 0) {
        $problems[] = ['type' => 'unpaid_in_stripe_but_site_gives_paid_plan', 'user_id' => $userId, 'plan' => $plan->slug, 'sub' => $s];
    }
    $openForUser = collect($stripeSubs)->where('customer', $s['customer'])->whereIn('status', ['active', 'trialing', 'past_due'])->count();
    if ($openForUser > 1) {
        $problems[] = ['type' => 'more_than_one_open_subscription (double billing risk)', 'user_id' => $userId, 'customer' => $s['customer']];
    }
}

// Paid access with no Stripe subscription and no manual payment.
$paidRows = Subscription::query()
    ->where('status', 'active')
    ->where(fn ($q) => $q->whereNull('current_period_end')->orWhere('current_period_end', '>=', now()))
    ->whereHas('plan', fn ($q) => $q->where('price_cents', '>', 0))
    ->get(['id', 'user_id', 'plan_id', 'billing_payment_state', 'current_period_end']);
foreach ($paidRows as $row) {
    $latest = Subscription::query()->where('user_id', $row->user_id)->latest('id')->value('id');
    if ((int) $latest !== (int) $row->id) {
        continue;
    }
    $user = User::query()->find($row->user_id);
    $hasOpen = $user && $user->stripe_id && collect($stripeSubs)->where('customer', $user->stripe_id)->whereIn('status', ['active', 'trialing'])->isNotEmpty();
    if (! $hasOpen && $row->billing_payment_state !== 'manual_override') {
        $problems[] = ['type' => 'paid_plan_without_stripe_or_manual_payment', 'user_id' => $row->user_id, 'plan_id' => $row->plan_id, 'until' => (string) $row->current_period_end, 'state' => $row->billing_payment_state];
    }
}

$out['trials'] = BillingTrial::query()->orderBy('id')->get()->map(fn ($t) => [
    'id' => $t->id, 'user_id' => $t->user_id, 'status' => $t->status, 'cycle' => $t->billing_cycle,
    'plan_id' => $t->plan_id, 'sub' => $t->stripe_subscription_id, 'admin_test' => (bool) $t->is_admin_test,
    'started' => (string) $t->started_at, 'ends' => (string) $t->trial_ends_at, 'paid_at' => (string) $t->paid_at,
    'stripe_now' => $t->stripe_subscription_id ? ($stripeSubs[$t->stripe_subscription_id]['status'] ?? 'closed') : null,
    'stripe_price' => $t->stripe_subscription_id ? ($stripeSubs[$t->stripe_subscription_id]['price'] ?? null) : null,
])->all();
foreach ($out['trials'] as $t) {
    if ($t['status'] === 'trialing' && $t['stripe_now'] !== 'trialing') {
        $problems[] = ['type' => 'trial_record_trialing_but_stripe_is_'.$t['stripe_now'], 'trial' => $t];
    }
}

$out['problems'] = $problems;
$out['problem_count'] = count($problems);
echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
