<?php

/*
 * For the "Server deploy" workflow's upgrade-test mode, on STAGING only: real
 * moves up to a higher plan in the Stripe SANDBOX, through the app's own code
 * (BillingController's order details and charge, App\Services\Billing\UnusedCredits),
 * to prove Stripe takes exactly what the site sends and charges what the page shows.
 *
 * A move up gives back what the unused credits of the plan being left are worth,
 * and the higher plan starts with its full credits.
 *
 * It first looks at which Stripe keys this server holds. It pays only with test
 * keys (sk_test_ / pk_test_): the keys in use when they are test keys, or else
 * sandbox keys kept beside them in .env, used for this run only (.env and the site
 * are not changed). With no test keys it does nothing at all. It never prints a
 * secret key. Refuses to run on the live site.
 *
 * Staging keeps no trace: everything written on the site (the test members, their
 * plans and usage) is inside one database transaction that is rolled back at the
 * end, and the sandbox customers made at Stripe are deleted. The plans on sale are
 * not touched: the moves are between two sandbox prices made for the run ($9 and
 * $22 a month), with the test members on the site's own Starter and Creator plans
 * for their credits.
 *
 * Each step is printed as passed or FAILED:
 *   A. a quarter of the credits used: three quarters of the $9 paid comes back
 *   B. the first month bought at half price, and credits used after the price was shown
 *   C. every credit used: nothing back, the full price, a full new plan
 *   D. a declined card: nothing charged, nothing owed, the plan and credits as they were
 *   E. an offer on the new plan takes nothing off the money back
 *
 *   ssh user@host 'cd ~/public_html && php' < scripts/support/upgrade_stripe_test.php
 */

use App\Http\Controllers\BillingController;
use App\Models\BillingSubscription;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\UnusedCredits;
use App\Services\BillingSyncService;
use App\Services\PlanAccessService;
use App\Services\StripeWebhookEmailService;
use App\Support\CreditPeriod;
use App\Support\UsageSnapshot;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Cashier\Cashier;

ini_set('display_errors', 'stderr');

$root = getcwd();
if (! is_file($root.'/artisan')) {
    fwrite(STDERR, "Run this from the Laravel app root.\n");
    exit(1);
}

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (str_contains(strtolower((string) config('app.url')), 'listenai.studio')) {
    fwrite(STDERR, "This is the live site: the payment test runs on staging only.\n");
    exit(1);
}

$kind = fn (string $key, string $test, string $live): string => match (true) {
    $key === '' => 'MISSING',
    str_starts_with($key, $test) => 'test',
    str_starts_with($key, $live), str_starts_with($key, 'rk_live_') => 'LIVE',
    default => 'unknown',
};
$secret = $kind(trim((string) config('services.stripe.secret')), 'sk_test_', 'sk_live_');
$public = $kind(trim((string) config('services.stripe.key')), 'pk_test_', 'pk_live_');
$cashier = $kind(trim((string) config('cashier.secret')), 'sk_test_', 'sk_live_');
echo "== Stripe keys on this server ==\n";
echo "  secret key: {$secret}   publishable key: {$public}   the key payments use: {$cashier}\n";
if ($secret !== 'test' || $public !== 'test' || $cashier !== 'test') {
    // Sandbox keys kept beside the keys in use (under any name, or on a commented-out
    // line) are used for this run only: .env and the site are left exactly as they are.
    $env = (string) @file_get_contents($root.'/.env');
    $find = function (string $prefix) use ($env): ?array {
        return preg_match('/^[ \t]*(#[ \t]*)?([A-Za-z0-9_]+)[ \t]*=[ \t]*["\']?('.$prefix.'[A-Za-z0-9]{16,})/m', $env, $m) === 1
            ? ['name' => $m[2], 'off' => $m[1] !== '', 'value' => $m[3]]
            : null;
    };
    $sk = $find('sk_test_');
    $pk = $find('pk_test_');
    echo '  sandbox secret key in .env: '.($sk ? $sk['name'].($sk['off'] ? ' (commented out)' : '') : 'none')."\n";
    if (! $sk || ! $pk) {
        fwrite(STDERR, "This server pays with keys that are not test keys, and holds no sandbox keys: no payment is made. Nothing was changed.\n");
        exit(1);
    }
    config(['cashier.secret' => $sk['value'], 'cashier.key' => $pk['value'], 'services.stripe.secret' => $sk['value'], 'services.stripe.key' => $pk['value']]);
    echo "  Using the sandbox keys for this run only.\n";
}
if (! str_starts_with((string) config('cashier.secret'), 'sk_test_')) {
    fwrite(STDERR, "The key payments would use is not a test key: stopping.\n");
    exit(1);
}

if (! UnusedCredits::ready()) {
    fwrite(STDERR, "The database cannot mark a settled period yet: run mode migrate first.\n");
    exit(1);
}
if (CreditPeriod::switchedAt() === null) {
    fwrite(STDERR, "Credits are still counted by calendar month here, so moves up are counted in time: nothing to test.\n");
    exit(1);
}

$starter = Plan::query()->where('slug', 'starter')->first();
$creator = Plan::query()->where('slug', 'creator')->first();
if (! $starter || ! $creator || (int) $starter->monthly_chars <= 0 || (int) $creator->monthly_chars <= 0) {
    fwrite(STDERR, "The Starter and Creator plans are needed for their credits.\n");
    exit(1);
}
$allowance = (int) $starter->monthly_chars;

$stripe = Cashier::stripe();
$failed = 0;
$check = function (string $what, bool $ok, string $detail = '') use (&$failed): void {
    echo '  '.($ok ? 'passed' : 'FAILED').'  '.$what.($detail !== '' ? '  ['.$detail.']' : '')."\n";
    if (! $ok) {
        $failed++;
    }
};
$usd = fn (int $cents): string => ($cents < 0 ? '-' : '').'$'.number_format(abs($cents) / 100, 2);

// The page's own code. Looking up the plan at Stripe again after a refused card is left
// out: the sandbox prices are no plan of the site's, so it would find none.
$app->instance(BillingSyncService::class, new class extends BillingSyncService
{
    public function __construct() {}

    public function syncIfStale(User $user, bool $force = false): void {}
});
$controller = $app->make(BillingController::class);
$private = function (object $on, string $method): ReflectionMethod {
    $m = new ReflectionMethod($on, $method);
    $m->setAccessible(true);

    return $m;
};

$customers = [];
$product = null;
$coupon = null;
DB::beginTransaction();

try {
    // ---------- sandbox prices for the run: $9 and $22 a month
    $product = $stripe->products->create(['name' => 'Staging upgrade test '.gmdate('Y-m-d H:i:s')]);
    $low = $stripe->prices->create(['product' => $product->id, 'currency' => 'usd', 'unit_amount' => 900, 'recurring' => ['interval' => 'month']]);
    $high = $stripe->prices->create(['product' => $product->id, 'currency' => 'usd', 'unit_amount' => 2200, 'recurring' => ['interval' => 'month']]);
    $coupon = $stripe->coupons->create(['percent_off' => 50, 'duration' => 'once', 'name' => 'Staging upgrade test']);
    $subscriptionOf = fn (string $id) => $stripe->subscriptions->retrieve($id, []);

    /** A member who paid for the $9 plan by card and has used $used of its credits. */
    $member = function (string $label, int $used, bool $halfPrice = false) use ($stripe, $low, $coupon, $starter, &$customers): array {
        $customer = $stripe->customers->create(['name' => 'Staging upgrade test '.$label]);
        $customers[] = $customer->id;
        $card = $stripe->paymentMethods->attach('pm_card_visa', ['customer' => $customer->id]);
        $stripe->customers->update($customer->id, ['invoice_settings' => ['default_payment_method' => $card->id]]);
        $sub = $stripe->subscriptions->create(array_filter([
            'customer' => $customer->id,
            'items' => [['price' => $low->id]],
            'default_payment_method' => $card->id,
            'discounts' => $halfPrice ? [['coupon' => $coupon->id]] : null,
        ]));
        $item = $sub->items->data[0];
        $start = Carbon::createFromTimestamp((int) ($item->current_period_start ?? $sub->current_period_start));
        $end = Carbon::createFromTimestamp((int) ($item->current_period_end ?? $sub->current_period_end));

        $user = User::withoutEvents(fn () => User::query()->create([
            'name' => 'Staging upgrade '.$label,
            'email' => 'staging-upgrade-'.$label.'-'.Str::lower(Str::random(8)).'@example.com',
            'password' => bcrypt(Str::random(40)),
        ]));
        User::withoutEvents(fn () => $user->forceFill(['stripe_id' => $customer->id, 'email_verified_at' => now(), 'subscription_status_checked_at' => now()])->save());
        $local = BillingSubscription::query()->create([
            'user_id' => $user->id, 'type' => 'default', 'stripe_id' => $sub->id,
            'stripe_status' => (string) $sub->status, 'stripe_price' => $low->id, 'quantity' => 1,
        ]);
        $local->items()->create(['stripe_id' => $item->id, 'stripe_product' => (string) $item->price->product, 'stripe_price' => $low->id, 'quantity' => 1]);
        // On the site the month began ten days ago, as for a member part-way through it. (Stripe's
        // sandbox starts it now; begun in the same minute as the move up, the site would see one
        // period, not two, and there would be no new month to start full.)
        Subscription::withoutEvents(fn () => app(PlanAccessService::class)->assignPlan($user, $starter, 'active', $end->copy()->subDays(10), $start->copy()->subDays(10)));
        CreditPeriod::counter((int) $user->id)->forceFill(['total_chars' => $used, 'total_requests' => 3])->save();

        return ['user' => $user, 'local' => $local, 'sub' => (string) $sub->id, 'customer' => (string) $customer->id];
    };

    /** The order details as the page works them out: the money back, and Stripe's own total. */
    $orderDetails = function (array $m, int $shownAt, ?string $offer = null) use ($controller, $private, $stripe, $subscriptionOf, $high): array {
        $current = $subscriptionOf($m['sub']);
        $credit = $private($controller, 'upgradeCredit')->invoke($controller, $m['user'], $current, $high->id, $shownAt, true);
        $preview = $stripe->invoices->createPreview(
            $private($controller, 'planChangePreviewParams')->invoke($controller, $m['user'], $current, $high->id, $shownAt, $credit, $offer)
        );

        return ['credit' => $credit, 'total' => (int) $preview->total, 'due' => (int) $preview->amount_due, 'lines' => $preview->lines->data ?? []];
    };

    /** The payment itself, as the page makes it. Null when paid, or the answer sent to the browser. */
    $pay = function (array $m, int $shownAt, ?string $offer = null) use ($controller, $private, $high) {
        $charged = false;
        $args = [$m['user'], $m['local'], $high->id, $offer, $shownAt, &$charged];

        return $private($controller, 'changePlanAndCharge')->invokeArgs($controller, $args);
    };

    /** What the site does when Stripe tells it of the new plan: the plan's row, from Stripe's dates. */
    $siteHears = function (array $m) use ($subscriptionOf, $creator): void {
        $item = $subscriptionOf($m['sub'])->items->data[0];
        app(PlanAccessService::class)->assignPlan($m['user'], $creator, 'active',
            Carbon::createFromTimestamp((int) $item->current_period_end), Carbon::createFromTimestamp((int) $item->current_period_start));
    };
    $usage = fn (User $user): array => UsageSnapshot::for((int) $user->id, app(PlanAccessService::class)->resolveQuotaPlanForUserId((int) $user->id), CreditPeriod::counter((int) $user->id));
    $invoiceOf = fn (string $sub) => $stripe->subscriptions->retrieve($sub, ['expand' => ['latest_invoice']])->latest_invoice;
    $linesOf = fn (string $invoice): array => $stripe->invoices->allLines($invoice, ['limit' => 20])->data;
    $nothingOwed = function (string $customer) use ($stripe, $check): void {
        $check('no item is left waiting for a later invoice', count($stripe->invoiceItems->all(['customer' => $customer, 'pending' => true, 'limit' => 5])->data) === 0);
        $check('the account holds no credit and owes nothing', (int) $stripe->customers->retrieve($customer)->balance === 0);
    };

    $paidOn = fn (array $m): ?array => app(UnusedCredits::class)->paidOnCard($subscriptionOf($m['sub']));

    // ---------- A. a quarter of the credits used
    $quarter = intdiv($allowance, 4);
    echo "\nA. A quarter of Starter's credits used (".number_format($quarter).' of '.number_format($allowance).")\n";
    $a = $member('a', $quarter);
    $paid = $paidOn($a);
    $check('the site reads from Stripe that $9.00 was paid for this month', ($paid['minor'] ?? null) === 900 && ($paid['currency'] ?? '') === 'usd', json_encode($paid));
    $shownAt = time();
    $order = $orderDetails($a, $shownAt);
    $check('three quarters of it comes back: $6.75', ($order['credit']['minor'] ?? null) === 675, $usd((int) ($order['credit']['minor'] ?? 0)));
    $check('Stripe works the order out as $22.00 less $6.75: $15.25 due today', $order['total'] === 1525 && $order['due'] === 1525, $usd($order['total']));
    $check('the order lists the money back as a line of its own', count(array_filter($order['lines'], fn ($l) => (int) $l->amount === -675)) === 1);
    $answer = $pay($a, $shownAt);
    $check('the payment went through', $answer === null, $answer ? (string) $answer->getContent() : '');
    $live = $subscriptionOf($a['sub']);
    $check('Stripe moved the plan to the $22 price, with nothing left waiting', (string) $live->items->data[0]->price->id === $high->id && empty($live->pending_update));
    $check('the billing date is today', abs((int) $live->items->data[0]->current_period_start - time()) < 600);
    $invoice = $invoiceOf($a['sub']);
    $check('the card was charged what the order details showed', (string) $invoice->status === 'paid' && (int) $invoice->amount_paid === $order['due'], $usd((int) $invoice->amount_paid));
    $lines = $linesOf((string) $invoice->id);
    $back = array_values(array_filter($lines, fn ($l) => (int) $l->amount < 0));
    $check('the invoice gives the money back as "'.UnusedCredits::STRIPE_PRODUCT_NAME.'"', count($back) === 1 && (int) $back[0]->amount === -675 && str_contains((string) $back[0]->description, UnusedCredits::STRIPE_PRODUCT_NAME), (string) ($back[0]->description ?? ''));
    $check('Stripe itself gave nothing back for the time left', count(array_filter($lines, fn ($l) => (bool) ($l->proration ?? $l->parent->subscription_item_details->proration ?? false))) === 0);
    $invoice->lines = (object) ['data' => $lines];
    $planLine = $private($emails = app(StripeWebhookEmailService::class), 'planLine')->invoke($emails, $invoice);
    $check('emails and the plan sync read the plan from its own line, not the money back', (int) ($planLine->amount ?? 0) === 2200, $usd((int) ($planLine->amount ?? 0)));
    $check('the month that ended is marked as settled at '.number_format($quarter).' credits', (int) CreditPeriod::counter((int) $a['user']->id)->credited_chars === $quarter);
    $siteHears($a);
    $now = $usage($a['user']);
    $check('Creator starts with its full '.number_format((int) $creator->monthly_chars).' credits', $now['total_chars'] === 0 && $now['remaining_chars'] === (int) $creator->monthly_chars, 'used '.$now['total_chars'].', left '.$now['remaining_chars']);
    $paid = $paidOn($a);
    $check('a later move up would count from the $22.00 now paid, not from the money back', ($paid['minor'] ?? null) === 2200, json_encode($paid));
    $nothingOwed($a['customer']);

    // ---------- B. half price for the first month, and credits used after the price was shown
    $half = intdiv($allowance, 2);
    echo "\nB. The first month at half price, half the credits used, then 5,000 more after the price was shown\n";
    $b = $member('b', $half, true);
    $paid = $paidOn($b);
    $check('the site reads that $4.50 was really paid, not $9.00', ($paid['minor'] ?? null) === 450, json_encode($paid));
    $shownAt = time();
    $order = $orderDetails($b, $shownAt);
    $check('half of what was paid comes back: $2.25, so $19.75 is due', ($order['credit']['minor'] ?? null) === 225 && $order['due'] === 1975, $usd((int) ($order['credit']['minor'] ?? 0)).', due '.$usd($order['due']));
    CreditPeriod::counter((int) $b['user']->id)->increment('total_chars', 5000);
    $answer = $pay($b, $shownAt);
    $invoice = $invoiceOf($b['sub']);
    $check('the card was still charged the $19.75 shown', $answer === null && (int) $invoice->amount_paid === 1975, $usd((int) $invoice->amount_paid));
    $siteHears($b);
    $now = $usage($b['user']);
    $check('the 5,000 used after the price was shown count on the new plan', $now['total_chars'] === 5000 && $now['remaining_chars'] === (int) $creator->monthly_chars - 5000, 'used '.$now['total_chars']);

    // ---------- C. every credit used
    echo "\nC. Every credit used (the member this rule was made for)\n";
    $c = $member('c', $allowance);
    $shownAt = time();
    $order = $orderDetails($c, $shownAt);
    $check('nothing comes back, and the full $22.00 is due', ($order['credit']['minor'] ?? null) === 0 && $order['due'] === 2200 && count(array_filter($order['lines'], fn ($l) => (int) $l->amount < 0)) === 0, 'due '.$usd($order['due']));
    $answer = $pay($c, $shownAt);
    $invoice = $invoiceOf($c['sub']);
    $check('the card was charged $22.00', $answer === null && (int) $invoice->amount_paid === 2200, $usd((int) $invoice->amount_paid));
    $siteHears($c);
    $now = $usage($c['user']);
    $check('Creator starts with its full credits, none of the '.number_format($allowance).' used on Starter counted', $now['total_chars'] === 0 && $now['remaining_chars'] === (int) $creator->monthly_chars, 'used '.$now['total_chars']);

    // ---------- D. a declined card
    echo "\nD. A declined card\n";
    $d = $member('d', $quarter);
    $refused = $stripe->paymentMethods->attach('pm_card_chargeCustomerFail', ['customer' => $d['customer']]);
    $stripe->customers->update($d['customer'], ['invoice_settings' => ['default_payment_method' => $refused->id]]);
    $stripe->subscriptions->update($d['sub'], ['default_payment_method' => $refused->id]);
    $answer = $pay($d, time());
    $check('the member is told the card was refused', $answer !== null && $answer->getStatusCode() === 422, $answer ? 'HTTP '.$answer->getStatusCode() : 'paid');
    $live = $subscriptionOf($d['sub']);
    $check('the plan at Stripe is still the $9 one, with no change waiting', (string) $live->items->data[0]->price->id === $low->id && empty($live->pending_update));
    $check('the unpaid invoice was cancelled', (string) $invoiceOf($d['sub'])->status === 'void');
    $counter = CreditPeriod::counter((int) $d['user']->id);
    $check('the credits are as they were, and the month is not marked as settled', (int) $counter->total_chars === $quarter && $counter->credited_at === null);
    $nothingOwed($d['customer']);

    // ---------- E. an offer on the new plan
    echo "\nE. Half off the new plan's first month, with a quarter of the credits used\n";
    $e = $member('e', $quarter);
    $shownAt = time();
    $order = $orderDetails($e, $shownAt, $coupon->id);
    $check('the offer halves the new plan only: $11.00 less the $6.75 back, $4.25 due', ($order['credit']['minor'] ?? null) === 675 && $order['due'] === 425, 'due '.$usd($order['due']));
    $answer = $pay($e, $shownAt, $coupon->id);
    $invoice = $invoiceOf($e['sub']);
    $check('the card was charged the $4.25 shown', $answer === null && (int) $invoice->amount_paid === 425, $usd((int) $invoice->amount_paid));
} catch (Throwable $e) {
    $failed++;
    fwrite(STDERR, "\nThe check stopped on an error: ".$e::class.': '.$e->getMessage().' ('.basename($e->getFile()).':'.$e->getLine().")\n");
} finally {
    // Staging keeps nothing: the members, plans and usage written above are undone.
    DB::rollBack();
    foreach ($customers as $customer) {
        try {
            $stripe->customers->delete($customer);
        } catch (Throwable) {
            // A sandbox customer left behind harms nothing.
        }
    }
    try {
        if ($coupon) {
            $stripe->coupons->delete($coupon->id);
        }
        if ($product) {
            $stripe->products->update($product->id, ['active' => false]);
        }
    } catch (Throwable) {
        // Sandbox leftovers.
    }
    echo "\nThe test members were undone on the site, and their sandbox customers removed at Stripe.\n";
}

echo $failed === 0 ? "\nOK\n" : "\n{$failed} FAILED\n";
exit($failed === 0 ? 0 : 1);
