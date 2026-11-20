<?php

/*
 * For the "Server deploy" workflow's plan-price modes: a plan's monthly price, set the way
 * Admin → Plans → Save sets it. The plan's price, then the Stripe sync: a new monthly price and
 * a new yearly price (the site's yearly discount), the old ones archived. Members who bought at
 * an old price keep paying it (StripePricePlans remembers it as this plan). The local-currency
 * prices (InstaPay, Vodafone Cash) follow the dollar price on their own.
 *
 * PLAN_PRICE is "slug=dollars" (studio_max=120). APPLY=0 shows the plan and what would change,
 * and changes nothing. APPLY=1 changes it, on the live site only: it is the one site that makes
 * Stripe prices (staging runs the live Stripe keys). If Stripe refuses, the old price comes back
 * and Stripe is synced to it again, so the page and the checkout never disagree.
 * Prints no member data: subscriptions as counts.
 *
 *   ssh user@host 'cd ~/public_html && PLAN_PRICE=studio_max=120 APPLY=0 php' < scripts/support/plan_price.php
 */

use App\Models\Plan;
use App\Services\StripeProductSyncService;
use App\Support\LiveServer;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// An error anywhere stops the check as FAILED (left to Laravel, it would be shown and the run would still pass).
set_exception_handler(function (Throwable $e): void {
    fwrite(STDERR, 'The check stopped on an error: '.$e::class.': '.$e->getMessage().' ('.basename($e->getFile()).':'.$e->getLine().")\nFAILED\n");
    exit(1);
});

$fail = function (string $why): never {
    echo "STOPPED: {$why}\n";
    exit(1);
};
[$slug, $dollars] = array_pad(explode('=', (string) getenv('PLAN_PRICE'), 2), 2, '');
if (! preg_match('/^[a-z0-9_]+$/', $slug) || ! preg_match('/^\d{1,4}(\.\d{1,2})?$/', $dollars) || (float) $dollars <= 0) {
    $fail('price: the plan and its monthly price in dollars, such as studio_max=120');
}
$cents = (int) round(((float) $dollars) * 100);
$apply = getenv('APPLY') === '1';
$plan = Plan::where('slug', $slug)->first();
if (! $plan) {
    $fail("No plan {$slug} on this site.");
}
$show = function (Plan $p, string $when): void {
    $yearly = \App\Models\SiteSetting::yearlyDiscountMultiplier();
    printf("%s: %s (#%d) | monthly $%s | yearly $%s ($%s a month) | credits %s | discount %d%% (%s) | on sale: %s\n", $when, $p->slug, $p->id,
        number_format($p->price_cents / 100, 2), number_format(round($p->price_cents * 12 * $yearly) / 100, 2), number_format(round($p->price_cents * $yearly) / 100, 2),
        number_format((int) $p->monthly_chars), (int) $p->discount_percentage, (string) ($p->discount_duration ?: 'once'), $p->is_active ? 'yes' : 'no');
    printf("  Stripe: product %s | monthly price %s | yearly price %s | coupon %s\n",
        $p->stripe_product_id ?: '-', $p->stripe_price_monthly ?: '-', $p->stripe_price_yearly ?: '-', $p->stripe_coupon_id ?: '-');
};
$show($plan, 'Now');
if (Schema::hasTable('subscriptions')) {
    $subs = DB::table('subscriptions')->where('plan_id', $plan->id)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
    echo '  subscriptions: ', $subs->isEmpty() ? 'none' : $subs->map(fn ($n, $s) => "{$s} {$n}")->implode(', '), "\n";
}

if ((int) $plan->price_cents === $cents) {
    echo "The price is already \${$dollars}.\n";
}
if (! $apply) {
    printf("Would set the monthly price from $%s to $%s, then make the Stripe prices for it. Nothing was changed (run plan-price-apply on live to change it).\n",
        number_format($plan->price_cents / 100, 2), number_format($cents / 100, 2));
    exit(0);
}
if (! LiveServer::allowsCustomerActions()) {
    $fail('Only the live site makes Stripe prices (this site has LISTEN_LIVE_ACTIONS off). Nothing was changed.');
}
$sync = app(StripeProductSyncService::class);
if (! $sync->isConfigured()) {
    $fail('Stripe is not set up on this site. Nothing was changed.');
}

$before = (int) $plan->price_cents;
$plan->price_cents = $cents;
$plan->save();
$result = $sync->syncPlan($plan->fresh());
if (empty($result['success'])) {
    echo 'Stripe refused: ', (string) ($result['message'] ?? 'no reason given'), "\n";
    $plan = $plan->fresh();
    $plan->price_cents = $before;
    $plan->save();
    $again = $sync->syncPlan($plan->fresh());
    echo 'Put back at $', number_format($before / 100, 2), '; Stripe synced to it again: ', ! empty($again['success']) ? 'yes' : 'NO ('.($again['message'] ?? '').')', "\n";
    $show($plan->fresh(), 'After');
    exit(1);
}
echo 'Stripe: ', (string) ($result['message'] ?? 'synced'), "\n";
$show($plan->fresh(), 'After');
