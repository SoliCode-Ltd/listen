<?php

/*
 * For the "Server deploy" workflow's extra-credits-test mode, on STAGING only:
 * real payments for extra credits in the Stripe SANDBOX, through the app's own
 * code (App\Services\Billing\CharPackCardPayment), for one test member made
 * for it.
 *
 * It first looks at which Stripe keys this server holds. It pays only with
 * test keys (sk_test_ / pk_test_): the keys in use when they are test keys, or
 * else sandbox keys kept beside them in .env, used for this run only (.env and
 * the site are not changed). With no test keys it does nothing at all: with
 * live keys a "test" payment would be a real one. It never prints a secret key.
 * Refuses to run on the live site.
 *
 * What it does, each step printed as passed or FAILED:
 *   1. a card that works (Stripe's test card) pays, and the credits are added;
 *      the payment at Stripe carries our description, statement suffix and notes
 *   2. a declined card charges nothing and adds nothing
 *   3. a card that needs the bank's check is handed back to the browser
 *   4. the same payment asked for again is answered, never charged twice, and
 *      a payment cut off before Stripe answered is finished under the same key
 *   5. a card kept "for future payments" becomes the card on the account
 *   6. a refund at Stripe takes the unused credits back
 *
 * The test member (staging-extra-credits@example.com, on Starter, credits used
 * up) is kept afterwards so the dialog can be tried in a browser with Stripe's
 * test cards. MEMBER_PASSWORD_HASH (a bcrypt hash, never a password) sets its
 * password; ACTION=clean removes the member and everything made for it, and
 * switches "Sell extra credits" off again (it is switched on for the check;
 * staging holds live keys, so nothing is left on sale there).
 *
 *   ssh user@host 'cd ~/public_html && php' < scripts/support/extra_credits_stripe_test.php
 */

use App\Models\CharPackPurchase;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use App\Services\Billing\CharPackCardPayment;
use App\Services\Billing\ExtraCredits;
use App\Services\Billing\StripePackGateway;
use App\Services\PlanAccessService;
use App\Support\CreditPeriod;
use Illuminate\Contracts\Console\Kernel;
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

// An error anywhere stops the check as FAILED (left to Laravel, it would be shown and the run would still pass).
set_exception_handler(function (Throwable $e): void {
    fwrite(STDERR, 'The check stopped on an error: '.$e::class.': '.$e->getMessage().' ('.basename($e->getFile()).':'.$e->getLine().")\nFAILED\n");
    exit(1);
});

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
echo '  webhook signing secret: '.(trim((string) config('services.stripe.webhook_secret')) !== '' ? 'set' : 'MISSING')."\n";
if ($secret !== 'test' || $public !== 'test' || $cashier !== 'test') {
    // The keys in use are not test keys. Sandbox keys kept beside them in .env (under any
    // name, or on a line that is commented out) are used for this run only: .env and the
    // site itself are left exactly as they are.
    $env = (string) @file_get_contents($root.'/.env');
    $find = function (string $prefix) use ($env): ?array {
        return preg_match('/^[ \t]*(#[ \t]*)?([A-Za-z0-9_]+)[ \t]*=[ \t]*["\']?('.$prefix.'[A-Za-z0-9]{16,})/m', $env, $m) === 1
            ? ['name' => $m[2], 'off' => $m[1] !== '', 'value' => $m[3]]
            : null;
    };
    $sk = $find('sk_test_');
    $pk = $find('pk_test_');
    echo '  sandbox secret key in .env: '.($sk ? $sk['name'].($sk['off'] ? ' (commented out)' : '') : 'none')."\n";
    echo '  sandbox publishable key in .env: '.($pk ? $pk['name'].($pk['off'] ? ' (commented out)' : '') : 'none')."\n";
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

const TEST_EMAIL = 'staging-extra-credits@example.com';
$extra = app(ExtraCredits::class);
$card = app(CharPackCardPayment::class);
$gateway = app(StripePackGateway::class);

if (getenv('ACTION') === 'clean') {
    $user = User::query()->where('email', TEST_EMAIL)->first();
    if (! $user) {
        if (ExtraCredits::enabled()) {
            SiteSetting::set(ExtraCredits::ENABLED_SETTING, '0', 'billing');
            echo "\"Sell extra credits\" switched off on staging again.\n";
        }
        echo "No test member to remove.\n";
        exit(0);
    }
    if ($user->stripe_id) {
        try {
            Cashier::stripe()->customers->delete((string) $user->stripe_id);
            echo "Sandbox customer removed at Stripe.\n";
        } catch (Throwable $e) {
            echo 'The sandbox customer could not be removed at Stripe: '.$e->getMessage()."\n";
        }
    }
    CharPackPurchase::query()->where('user_id', $user->id)->delete();
    Payment::query()->where('user_id', $user->id)->delete();
    UsageCounter::query()->where('user_id', $user->id)->delete();
    Subscription::withoutEvents(fn () => Subscription::query()->where('user_id', $user->id)->delete());
    User::withoutEvents(fn () => $user->delete());
    echo "Test member removed.\n";
    // Staging holds live keys and a copy of real members' cards: nothing is left on sale there by this check.
    if (ExtraCredits::enabled()) {
        SiteSetting::set(ExtraCredits::ENABLED_SETTING, '0', 'billing');
        echo "\"Sell extra credits\" switched off on staging again.\n";
    }
    exit(0);
}

if (! ExtraCredits::ready()) {
    fwrite(STDERR, "The database has no place for extra credits yet: run mode migrate first.\n");
    exit(1);
}
if (! ExtraCredits::enabled()) {
    SiteSetting::set(ExtraCredits::ENABLED_SETTING, '1', 'billing');
    echo "\"Sell extra credits\" was off on staging: switched on.\n";
}

// ---------- the test member: on Starter, with this period's credits used up
$starter = Plan::query()->where('slug', 'starter')->first()
    ?? Plan::query()->subscriptionPlans()->where('is_active', true)->where('price_cents', '>', 0)->orderBy('price_cents')->first();
if (! $starter) {
    fwrite(STDERR, "No paid plan on sale.\n");
    exit(1);
}
$user = User::withoutEvents(fn () => User::query()->firstOrCreate(
    ['email' => TEST_EMAIL],
    ['name' => 'Staging extra credits', 'password' => bcrypt(Str::random(40))],
));
$hash = trim((string) getenv('MEMBER_PASSWORD_HASH'));
User::withoutEvents(fn () => $user->forceFill(['email_verified_at' => now(), 'subscription_status_checked_at' => now()])->save());
if (preg_match('/^\$2y\$\d{2}\$[A-Za-z0-9.\/]{53}$/', $hash) === 1) {
    // Written as it is: the model would hash it again, or refuse a hash made with other settings.
    DB::table('users')->where('id', $user->id)->update(['password' => $hash]);
    $user->refresh();
}
CharPackPurchase::query()->where('user_id', $user->id)->delete();
UsageCounter::query()->where('user_id', $user->id)->delete();
$start = now()->subDays(6);
Subscription::withoutEvents(fn () => app(PlanAccessService::class)->assignPlan($user, $starter, 'active', $start->copy()->addMonth(), $start));
Subscription::withoutEvents(fn () => Subscription::query()->where('user_id', $user->id)->update(['billing_payment_state' => 'ok', 'billing_grace_ends_at' => null]));
$limit = (int) $starter->monthly_chars;
$counter = CreditPeriod::counter($user->id);
$counter->forceFill(['total_chars' => $limit, 'total_requests' => 1])->save();
echo "Test member {$user->id} on {$starter->slug}, credits used up (".number_format($limit).'). Status: '.$extra->status($user->id).".\n\n";
if ($extra->status($user->id) !== ExtraCredits::STATUS_OK) {
    fwrite(STDERR, "The test member cannot buy extra credits: stopping.\n");
    exit(1);
}

$failed = 0;
$check = function (string $what, bool $ok, string $detail = '') use (&$failed): void {
    echo '  '.($ok ? 'passed' : 'FAILED').'  '.$what.($detail !== '' ? '  ['.$detail.']' : '')."\n";
    if (! $ok) {
        $failed++;
    }
};
$left = fn (): int => (int) CreditPeriod::counter($user->id)->fresh()->extra_chars;
$price = fn (int $minutes): int => (int) ExtraCredits::priceCents($minutes);

// ---------- 1. a card that works
echo "1. A card that works\n";
$paid = $card->startExtraCredits($user, 10, $price(10), 'pm_card_visa', true);
$purchase = $paid['purchase']->fresh();
$check('the payment went through', $paid['status'] === 'paid', 'status '.$paid['status']);
$check('9,000 credits were added', $left() === 9000, 'extra credits '.$left());
$check('the purchase is saved as paid, with its Stripe payment', $purchase->status === CharPackPurchase::STATUS_PAID && str_starts_with((string) $purchase->stripe_payment_intent_id, 'pi_'));
if ($purchase->stripe_payment_intent_id) {
    $intent = Cashier::stripe()->paymentIntents->retrieve((string) $purchase->stripe_payment_intent_id);
    $check('Stripe shows "Listen Extra Credits (10 minutes)"', (string) $intent->description === ExtraCredits::description(10), (string) $intent->description);
    $check('the statement suffix is LISTEN', (string) $intent->statement_descriptor_suffix === 'LISTEN');
    $check('the amount is the dollars on sale', (int) $intent->amount === $price(10) && (string) $intent->currency === 'usd', $intent->amount.' '.$intent->currency);
    $check('the payment carries the member, purchase and minutes', (string) ($intent->metadata['listen_purchase_id'] ?? '') === (string) $purchase->id && (string) ($intent->metadata['listen_minutes'] ?? '') === '10' && (string) ($intent->metadata['listen_purpose'] ?? '') === ExtraCredits::PURPOSE);
}

// ---------- 2. a declined card
echo "2. A declined card\n";
$before = $left();
$declined = $card->startExtraCredits($user, 20, $price(20), 'pm_card_chargeDeclined', false);
$check('the payment was refused', $declined['status'] === 'failed', 'status '.$declined['status'].', '.($declined['reason'] ?? ''));
$check('nothing was added', $left() === $before);
$check('the member is told the card was declined', ($declined['message'] ?? '') === __('ui.extra_credits.card_declined'));

// ---------- 3. a card that needs the bank's check
echo "3. A card that needs the bank's check (3-D Secure)\n";
$bank = $card->startExtraCredits($user, 20, $price(20), 'pm_card_threeDSecure2Required', false);
$check('it is handed to the browser for the check', $bank['status'] === 'requires_action' && filled($bank['client_secret'] ?? null), 'status '.$bank['status']);
$check('nothing is added before the check is passed', $left() === $before);
$again = $card->confirm($bank['purchase']->fresh());
$browser = ['key' => (string) config('services.stripe.key'), 'payment' => (string) ($bank['client_secret'] ?? '')];
$check('asking again before the check still adds nothing', $again['status'] !== 'paid' && $left() === $before, 'status '.$again['status']);

// ---------- 4. never twice
echo "4. The same payment asked for again\n";
$repeat = $card->resumeExtraCredits($user, $purchase->fresh(), 'pm_card_visa', true);
$check('a paid purchase answers "paid" and adds nothing more', $repeat['status'] === 'paid' && $left() === $before);
$cut = $extra->startPurchase($user, 10, CharPackPurchase::METHOD_CARD, $price(10), 'USD');
$first = $card->resumeExtraCredits($user, $cut, 'pm_card_visa', false);
$second = $card->resumeExtraCredits($user, $cut->fresh(), 'pm_card_visa', false);
$check('a payment cut off before Stripe answered is finished', $first['status'] === 'paid', 'status '.$first['status']);
$check('asked once more, it is the same payment and the same credits', $second['status'] === 'paid' && $left() === $before + 9000
    && (string) $second['purchase']->fresh()->stripe_payment_intent_id === (string) $first['purchase']->fresh()->stripe_payment_intent_id, 'extra credits '.$left());
// Stripe's own guard, which the retry above leans on: the same key and the same request give the same payment.
$key = 'listen-extra-credits-check-'.Str::random(16);
$once = fn (): array => $gateway->chargeCard((string) $user->fresh()->stripe_id, 'pm_card_visa', $price(10), 'usd', ExtraCredits::description(10), ['listen_purpose' => 'staging_check'], $key);
$a = $once();
$b = $once();
$check('Stripe answers the same payment for the same key (one charge)', $a['id'] !== '' && $a['id'] === $b['id'], $a['status']);

// ---------- 5. the card kept for later
echo "5. A card kept for future payments\n";
$user->refresh();
$saved = $user->stripe_id ? $gateway->savedCard((string) $user->stripe_id) : null;
$check('the card on the account is the one just used', is_array($saved) && ($saved['last4'] ?? '') === '4242', is_array($saved) ? ($saved['brand'].' '.$saved['last4']) : 'none');
$bySaved = $card->startExtraCredits($user, 10, $price(10), null, false);
$check('the next payment goes through with it, no card typed', $bySaved['status'] === 'paid', 'status '.$bySaved['status']);

// ---------- 6. a refund
echo "6. A refund\n";
$beforeRefund = $left();
try {
    Cashier::stripe()->refunds->create(['payment_intent' => (string) $purchase->fresh()->stripe_payment_intent_id]);
    $check('refunded at Stripe', true);
} catch (Throwable $e) {
    $check('refunded at Stripe', false, $e->getMessage());
}
// The site hears of a refund through Stripe's webhook; the same step is taken here in case staging has none for the sandbox.
$taken = $extra->refund($purchase->fresh());
$check('the unused credits of that purchase are taken back', $taken === 9000 && $left() === $beforeRefund - 9000, 'taken '.$taken.', left '.$left());

echo "\nExtra credits left on the test member: ".number_format($left()).". Its Stripe customer: sandbox.\n";
echo 'The member is kept for a browser check: '.TEST_EMAIL." (run again with clean to remove it).\n";
// For trying the bank's check in a real browser: the sandbox's public key (it is public by nature) and
// the one sandbox payment still waiting for its check. Neither can touch real money.
echo "For the browser check of the bank's test page: {$browser['key']} {$browser['payment']}\n";
echo $failed === 0 ? "ALL PASSED\n" : "{$failed} FAILED\n";
exit($failed === 0 ? 0 : 1);
