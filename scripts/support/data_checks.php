<?php

/*
 * One-off, read-only (READ ONLY database session), run through the "Server deploy"
 * workflow's data-checks mode from this branch only: is the server ready for the
 * cancellation reasons (solicode-ltd/listen#419)? Column types the new table links to,
 * the email switches and the plans. Nothing written, no secret printed.
 */
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
    DB::statement('SET SESSION TRANSACTION READ ONLY');
}
echo 'Now (UTC): ', now()->utc()->toDateTimeString(), "\n";
echo 'database: ', DB::connection()->getDriverName(), ' ', DB::selectOne('select version() as v')->v, "\n";
foreach (['users', 'plans'] as $table) {
    $col = DB::selectOne("show columns from `{$table}` where Field = 'id'");
    $engine = DB::selectOne('select engine, table_collation from information_schema.tables where table_schema = database() and table_name = ?', [$table]);
    echo "{$table}.id: ", $col->Type, ' ', $col->Extra, ' | engine ', $engine->engine ?? $engine->ENGINE, ' | ', $engine->table_collation ?? $engine->TABLE_COLLATION, "\n";
}
echo 'subscription_cancellations exists already: ', Schema::hasTable('subscription_cancellations') ? 'YES' : 'no', "\n";
echo 'migration recorded: ', DB::table('migrations')->where('migration', '2026_12_14_090000_create_subscription_cancellations_table')->exists() ? 'YES' : 'no', "\n";
$set = fn (string $key) => DB::table('site_settings')->where('key', $key)->value('value');
echo 'billing_webhook_emails setting: ', var_export($set('billing_webhook_emails'), true), ' | config default: ', var_export(config('listen.billing.webhook_email_enabled'), true), "\n";
echo 'yearly discount setting: ', var_export($set('billing_yearly_discount_percentage'), true), "\n";
echo 'cancellation_email settings saved: ', DB::table('site_settings')->where('key', 'like', 'cancellation_email_%')->count(), "\n";
echo 'help mailbox password set: ', App\Support\TitanMailbox::hasPassword() ? 'yes' : 'NO', "\n";
echo 'live customer actions allowed: ', App\Support\LiveServer::allowsCustomerActions() ? 'yes' : 'no', "\n";
echo "== subscription plans (active, paid) ==\n";
foreach (App\Models\Plan::query()->subscriptionPlans()->where('is_active', true)->where('price_cents', '>', 0)->orderBy('price_cents')->get() as $p) {
    echo '  ', $p->slug, ' | ', $p->name, ' / ', $p->name_ar, ' | $', number_format($p->price_cents / 100, 2), ' | yearly price: ', trim((string) $p->stripe_price_yearly) !== '' ? 'yes' : 'no', "\n";
}
echo "== card subscriptions now (by cycle) ==\n";
$plans = App\Models\Plan::query()->get(['id', 'stripe_price_monthly', 'stripe_price_yearly']);
$monthly = $plans->pluck('stripe_price_monthly')->filter()->all();
$yearly = $plans->pluck('stripe_price_yearly')->filter()->all();
$active = DB::table('billing_subscriptions')->whereIn('stripe_status', ['active', 'trialing'])->whereNull('ends_at');
echo '  active: ', (clone $active)->count(), ' | monthly prices: ', (clone $active)->whereIn('stripe_price', $monthly)->count(), ' | yearly prices: ', (clone $active)->whereIn('stripe_price', $yearly)->count(), "\n";
echo '  set to cancel (ends_at in the future): ', DB::table('billing_subscriptions')->where('ends_at', '>', now())->count(), "\n";
