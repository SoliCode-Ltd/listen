<?php

/*
 * Read-only: the data one member's Manage Subscription page is built from,
 * so the page can be previewed without signing in as them. Reads the
 * database (READ ONLY session) and Stripe (retrieve and list calls only);
 * nothing is synced or written. The member is found by the SHA-256 of their
 * lower-cased email (ACCOUNT_EMAIL_SHA256). Prints one JSON line.
 */

use App\Models\BillingSubscription;
use App\Models\BillingTrial;
use App\Models\ManualPaymentRequest;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Laravel\Cashier\Cashier;

ini_set('display_errors', 'stderr');

$root = getcwd();
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
    DB::statement('SET SESSION TRANSACTION READ ONLY');
}

$hash = strtolower(trim((string) getenv('ACCOUNT_EMAIL_SHA256')));
$user = null;
User::query()->orderBy('id')->chunk(1000, function ($users) use ($hash, &$user) {
    foreach ($users as $candidate) {
        if (hash('sha256', strtolower(trim((string) $candidate->email))) === $hash) {
            $user = $candidate;

            return false;
        }
    }
});
if (! $user) {
    echo "No account with that email on this server.\n";
    exit(0);
}

$errors = [];
$try = function (string $what, callable $fn) use (&$errors) {
    try {
        return $fn();
    } catch (\Throwable $e) {
        $errors[] = $what.': '.get_class($e).': '.mb_substr($e->getMessage(), 0, 160);

        return null;
    }
};

$access = app(\App\Services\PlanAccessService::class);
$accessPlan = $access->resolveAccessPlanForUserId((int) $user->id);
$pricing = app(\App\Services\LocalizedPricingService::class);
$country = ['country_code' => strtoupper((string) ($user->country_code ?: 'EG'))];
$local = fn (int $usdMinor) => $try('egp', fn () => (string) ($pricing->convertedQuoteFromUsd(abs($usdMinor), 'monthly', $country, 'stripe', 'en')['amount_formatted'] ?? ''));
$localAr = fn (int $usdMinor) => $try('egp_ar', fn () => (string) ($pricing->convertedQuoteFromUsd(abs($usdMinor), 'monthly', $country, 'stripe', 'ar')['amount_formatted'] ?? ''));

$out = [
    'name' => (string) $user->name,
    'country' => $country['country_code'],
    'stripe_customer' => $user->hasStripeId(),
    'access_plan' => $accessPlan ? ['slug' => $accessPlan->slug, 'en' => $accessPlan->getLocalizedName('en'), 'ar' => $accessPlan->getLocalizedName('ar'), 'tier' => $accessPlan->voice_tier, 'price_cents' => (int) $accessPlan->price_cents] : null,
];

$localSub = Subscription::query()->where('user_id', $user->id)->latest('id')->with('plan')->first();
$out['local_subscription'] = $localSub ? [
    'status' => $localSub->status, 'payment_state' => $localSub->billing_payment_state, 'plan' => $localSub->plan?->slug,
    'plan_price_cents' => (int) ($localSub->plan?->price_cents ?? 0),
    'period_start' => (string) $localSub->current_period_start, 'period_end' => (string) $localSub->current_period_end,
] : null;

$sub = BillingSubscription::query()->where('user_id', $user->id)->where('type', 'default')->latest('id')->first();
if ($sub) {
    $plans = Plan::query()->get();
    $mapped = $plans->first(fn ($p) => in_array((string) $sub->stripe_price, array_filter([(string) $p->getStripePriceId('monthly'), (string) $p->getStripePriceId('yearly')]), true));
    $out['stripe_subscription'] = [
        'status' => $sub->stripe_status, 'valid' => $sub->valid(), 'grace' => $sub->onGracePeriod(), 'ended' => $sub->ended(),
        'ends_at' => (string) $sub->ends_at, 'created_at' => (string) $sub->created_at,
        'plan' => $mapped?->slug, 'cycle' => $mapped && (string) $sub->stripe_price === (string) $mapped->getStripePriceId('yearly') ? 'yearly' : 'monthly',
        'period_end' => $try('period_end', fn () => (string) $sub->currentPeriodEnd()?->toDateTimeString()),
    ];
    $out['card'] = $try('card', function () use ($sub, $user) {
        $own = Cashier::stripe()->subscriptions->retrieve((string) $sub->stripe_id, ['expand' => ['default_payment_method']])->default_payment_method ?? null;
        $card = is_object($own) && isset($own->card) ? $own->card : ($user->defaultPaymentMethod()?->card ?? null);

        return $card ? ['brand' => (string) $card->brand, 'last4' => (string) $card->last4] : null;
    });
    $out['upcoming'] = $sub->valid() && ! $sub->onGracePeriod() ? $try('upcoming', function () use ($sub, $local, $localAr) {
        $inv = $sub->upcomingInvoice()?->asStripeInvoice();
        if (! $inv) {
            return null;
        }
        $discount = 0;
        foreach ((array) ($inv->total_discount_amounts ?? []) as $row) {
            $discount += (int) ($row->amount ?? 0);
        }

        return ['total' => (int) $inv->total, 'subtotal' => (int) $inv->subtotal, 'discount' => $discount, 'currency' => strtoupper((string) $inv->currency),
            'period_end' => (int) ($inv->period_end ?? 0), 'local_en' => $local((int) $inv->total), 'local_ar' => $localAr((int) $inv->total)];
    }) : null;
}

$out['invoices'] = $user->hasStripeId() ? $try('invoices', function () use ($user, $local, $localAr) {
    return collect($user->invoices(true))->map(function ($invoice) use ($local, $localAr) {
        $inv = $invoice->asStripeInvoice();
        $discount = 0;
        foreach ((array) ($inv->total_discount_amounts ?? []) as $row) {
            $discount += (int) ($row->amount ?? 0);
        }
        $slug = '';
        foreach (($inv->lines->data ?? []) as $line) {
            $slug = (string) ($line->metadata->listen_plan_slug ?? $line->price->metadata->listen_plan_slug ?? '');
            if ($slug !== '') {
                break;
            }
        }

        return ['status' => (string) $inv->status, 'created' => (int) $inv->created, 'paid_at' => (int) ($inv->status_transitions->paid_at ?? 0),
            'total' => (int) $inv->total, 'amount_paid' => (int) ($inv->amount_paid ?? 0), 'subtotal' => (int) $inv->subtotal, 'discount' => $discount,
            'currency' => strtoupper((string) $inv->currency), 'plan' => $slug, 'description' => (string) ($inv->lines->data[0]->description ?? ''),
            'local_en' => $local((int) $inv->total), 'local_ar' => $localAr((int) $inv->total), 'open_url' => (string) $inv->status === 'open'];
    })->values()->all();
}) : [];

$out['manual_payments'] = ManualPaymentRequest::query()->where('user_id', $user->id)->orderBy('id')->get()
    ->map(fn ($p) => ['status' => $p->status, 'plan' => $p->plan?->slug, 'cycle' => $p->billing_cycle, 'method' => $p->payment_method,
        'amount_minor' => (int) $p->expected_amount_minor, 'currency' => $p->currency, 'submitted' => (string) $p->submitted_at, 'reviewed' => (string) $p->reviewed_at,
        'snapshot' => array_intersect_key((array) $p->price_snapshot, array_flip(['full_amount_minor', 'discount_percent', 'amount_minor']))])->all();
$out['trial'] = BillingTrial::query()->where('user_id', $user->id)->latest('id')->first()?->only(['status', 'trial_ends_at', 'paid_at']);
$out['errors'] = $errors;

echo "PREVIEW_JSON=".json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL;
