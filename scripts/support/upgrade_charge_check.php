<?php

/*
 * For the "Server deploy" workflow's upgrade-check mode: what one member was charged when they
 * changed plan, line by line as Stripe billed it, beside the credits they had used. Read-only:
 * Stripe is only read (no change, no payment) and the database session is READ ONLY where the
 * server allows it. Prints the member's id, never their name, email or card.
 *
 *   ssh user@host 'cd ~/public_html && MEMBER=someone@example.com php' < scripts/support/upgrade_charge_check.php
 *
 * MEMBER is the member's email or id.
 */

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use App\Services\PlanAccessService;
use App\Support\CreditPeriod;
use App\Support\UsageSnapshot;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

ini_set('display_errors', 'stderr');

$root = getcwd();
if (! is_file($root.'/artisan')) {
    fwrite(STDERR, "Run this from the Laravel app root.\n");
    exit(1);
}

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

set_exception_handler(function (Throwable $e): void {
    fwrite(STDERR, 'The check stopped on an error: '.$e::class.': '.$e->getMessage().' ('.basename($e->getFile()).':'.$e->getLine().")\nFAILED\n");
    exit(1);
});

try {
    DB::statement('SET SESSION TRANSACTION READ ONLY');
} catch (Throwable) {
    // Not a server that takes it: the script only reads all the same.
}

$who = trim((string) getenv('MEMBER'));
$user = ctype_digit($who) ? User::query()->find((int) $who) : User::query()->where('email', $who)->first();
if (! $user) {
    fwrite(STDERR, "No member with that email or id.\nFAILED\n");
    exit(1);
}

$line = fn (string $label, $value) => print '  '.str_pad($label, 40).(string) $value."\n";
$when = fn ($ts) => $ts ? gmdate('Y-m-d H:i', (int) $ts).' UTC' : '-';
$money = fn ($minor, $currency) => number_format(((int) $minor) / 100, 2).' '.strtoupper((string) $currency);

// Which of our plans a Stripe price is.
$priceToPlan = [];
foreach (Plan::query()->get() as $plan) {
    foreach (['stripe_price_monthly' => 'monthly', 'stripe_price_yearly' => 'yearly'] as $column => $cycle) {
        foreach (preg_split('/[\s,]+/', (string) $plan->getAttribute($column)) ?: [] as $price) {
            if ($price !== '') {
                $priceToPlan[$price] = $plan->slug.' '.$cycle.' ($'.number_format(((int) $plan->price_cents) / 100, 2).' a month, '.number_format((int) $plan->monthly_chars).' credits)';
            }
        }
    }
}

$plans = app(PlanAccessService::class);
$active = $plans->resolveActivePlanForUserId((int) $user->id);
echo "== Member {$user->id} ==\n";
$line('Joined', (string) $user->created_at);
$line('Plan now', $active ? $active->slug.' ($'.number_format(((int) $active->price_cents) / 100, 2).', '.number_format((int) $active->monthly_chars).' credits a month)' : 'none');
$period = CreditPeriod::current((int) $user->id);
$snapshot = UsageSnapshot::for((int) $user->id, $plans->resolveQuotaPlanForUserId((int) $user->id), CreditPeriod::counter((int) $user->id));
$line('Credit period now', $period['key'].' to '.$period['ends_at']->utc()->format('Y-m-d H:i').' UTC');
$line('Credits in it: allowance / used / left', number_format((int) ($snapshot['plan_chars'] ?? 0)).' / '.number_format((int) ($snapshot['total_chars'] ?? 0)).' / '.number_format((int) ($snapshot['remaining_chars'] ?? 0)));

echo "\n== Plan rows on the site (newest first) ==\n";
foreach (Subscription::query()->where('user_id', $user->id)->latest('id')->limit(6)->get() as $row) {
    $line('#'.$row->id.' '.(Plan::query()->whereKey($row->plan_id)->value('slug') ?? '?').' '.$row->status, ($row->current_period_start?->utc()->format('Y-m-d H:i') ?? '-').' to '.($row->current_period_end?->utc()->format('Y-m-d H:i') ?? '-').', changed '.$row->updated_at?->utc()->format('Y-m-d H:i'));
}

echo "\n== Credits used, by period (newest first) ==\n";
foreach (UsageCounter::query()->where('user_id', $user->id)->latest('id')->limit(6)->get() as $counter) {
    $line($counter->period_ym, number_format((int) $counter->total_chars).' used'.((int) ($counter->extra_chars ?? 0) > 0 ? ', '.number_format((int) $counter->extra_chars).' extra' : '').', row made '.$counter->created_at?->utc()->format('Y-m-d H:i'));
}

$customer = trim((string) $user->stripe_id);
if ($customer === '') {
    echo "\nNo Stripe customer.\nOK\n";
    exit(0);
}
$stripe = new \Stripe\StripeClient((string) config('services.stripe.secret'));

echo "\n== Stripe subscriptions ==\n";
foreach ($stripe->subscriptions->all(['customer' => $customer, 'status' => 'all', 'limit' => 5])->data as $sub) {
    $item = $sub->items->data[0] ?? null;
    $price = (string) ($item->price->id ?? '');
    $line('How Stripe bills it', 'billing mode '.((string) ($sub->billing_mode->type ?? 'classic')));
    $line($sub->status.', '.($priceToPlan[$price] ?? $price), 'period '.$when($item->current_period_start ?? $sub->current_period_start ?? null).' to '.$when($item->current_period_end ?? $sub->current_period_end ?? null).', anchor '.$when($sub->billing_cycle_anchor ?? null));
}
$balance = (int) ($stripe->customers->retrieve($customer)->balance ?? 0);
$line('Account credit with Stripe', $balance < 0 ? $money(-$balance, 'usd').' to use' : ($balance > 0 ? $money($balance, 'usd').' owed' : 'none'));

echo "\n== Invoices (newest first), line by line ==\n";
foreach ($stripe->invoices->all(['customer' => $customer, 'limit' => 6])->data as $invoice) {
    echo '  '.$when($invoice->created).'  '.$invoice->billing_reason.'  '.$invoice->status
        .'  total '.$money($invoice->total, $invoice->currency).', paid '.$money($invoice->amount_paid, $invoice->currency)
        .((int) ($invoice->starting_balance ?? 0) !== 0 ? ', account credit used '.$money(-((int) $invoice->starting_balance - (int) ($invoice->ending_balance ?? 0)), $invoice->currency) : '')."\n";
    foreach ($stripe->invoices->allLines($invoice->id, ['limit' => 20])->data as $item) {
        $price = (string) ($item->price->id ?? $item->pricing->price_details->price ?? '');
        $proration = $item->proration ?? $item->parent->subscription_item_details->proration ?? false;
        echo '      '.str_pad($money($item->amount, $item->currency), 14, ' ', STR_PAD_LEFT).'  '.($proration ? '[time credit/charge] ' : ((int) $item->amount < 0 ? '[money back] ' : '')).trim((string) $item->description)
            .'  ('.$when($item->period->start ?? null).' to '.$when($item->period->end ?? null).')'.($price !== '' ? '  = '.($priceToPlan[$price] ?? $price) : '')."\n";
    }
}

echo "\nOK\n";
