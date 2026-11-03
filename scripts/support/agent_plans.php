<?php

/*
 * For the "Server deploy" workflow's agent-plans mode, on either site: every
 * plan as it is, to choose its voice agent settings. In a READ ONLY database
 * session it prints each plan's price, credits, voice agent settings (when the
 * site has them), how its pricing card is written (the automatic lines or the
 * plan's own lists, label and value), and how many subscriptions it has (a
 * count). Then the call rate the site follows. Prints no member data, and
 * writes nothing.
 *
 *   ssh user@host 'cd ~/public_html && php' < scripts/support/agent_plans.php
 */

use App\Models\Plan;
use App\Models\SiteSetting;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
    DB::statement('SET SESSION TRANSACTION READ ONLY');
}
echo 'Now (UTC): ', now()->utc()->toDateTimeString(), "\n";

$has = fn (string $column): bool => Schema::hasColumn('plans', $column);
$agentColumns = array_values(array_filter(['agents_enabled', 'agents_max', 'agent_minutes_per_month', 'agent_concurrent_calls', 'agent_max_call_minutes'], $has));
$subscriptions = Schema::hasTable('subscriptions')
    ? DB::table('subscriptions')->selectRaw('plan_id, status, count(*) as n')->groupBy('plan_id', 'status')->get()->groupBy('plan_id')
    : collect();
$setting = function (string $key): ?string {
    return rescue(fn () => (string) (SiteSetting::query()->where('key', $key)->value('value') ?? ''), null, false);
};

echo "\n== Plans ==\n";
foreach (Plan::query()->orderBy('kind')->orderBy('price_cents')->get() as $plan) {
    printf("\n#%d %s | %s / %s | kind %s | %s\n", $plan->id, $plan->slug, (string) ($plan->name_en ?? $plan->name), (string) ($plan->name_ar ?? ''),
        (string) ($plan->kind ?? 'subscription'), $plan->is_active ? 'on sale' : 'not on sale');
    printf("  price %s cents %s | credits a month %s | yearly price %s | discount %s%%\n",
        number_format((int) $plan->price_cents), (string) ($plan->currency ?? ''), number_format((int) $plan->monthly_chars),
        filled($plan->stripe_price_yearly ?? null) ? 'yes' : 'no', (int) ($plan->discount_percentage ?? 0));
    if ($agentColumns !== []) {
        echo '  agents: ', implode(', ', array_map(fn ($c) => $c.'='.var_export($plan->{$c}, true), $agentColumns)), "\n";
    } else {
        echo "  agents: the site has no voice agent settings yet\n";
    }
    $subs = $subscriptions->get($plan->id, collect())->map(fn ($r) => $r->status.' '.$r->n)->implode(', ');
    echo '  subscriptions: ', $subs !== '' ? $subs : 'none', "\n";
    if ($has('pricing_use_custom_text')) {
        echo '  pricing card: ', $plan->pricing_use_custom_text ? 'the plan\'s own lists' : 'the automatic lines', "\n";
    }
    if ($has('pricing_lines') && filled($plan->pricing_lines)) {
        echo '  automatic lines switched: ', json_encode($plan->pricing_lines, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    }
    foreach (['features_en', 'features_ar', 'pricing_extra_lines_en', 'pricing_extra_lines_ar'] as $column) {
        if (! $has($column)) {
            continue;
        }
        $lines = (array) ($plan->{$column} ?? []);
        if ($lines === []) {
            continue;
        }
        echo "  {$column}:\n";
        foreach ($lines as $i => $line) {
            $text = is_array($line)
                ? trim(((string) ($line['label'] ?? $line['text'] ?? '')).' | '.((string) ($line['value'] ?? '')).' | '.((string) ($line['icon'] ?? '')), ' |')
                : (string) $line;
            printf("    %d. %s\n", $i + 1, mb_strimwidth((string) preg_replace('/\s+/u', ' ', $text), 0, 160, '…'));
        }
    }
}

echo "\n== Call rate ==\n";
foreach (['agents_audience', 'agents_credits_per_minute', 'billing_yearly_discount_percentage', 'agents_free_test_seconds'] as $key) {
    $value = $setting($key);
    echo "  {$key}: ", $value === null ? '(no settings table)' : ($value === '' ? '(not set)' : $value), "\n";
}
if (class_exists(\App\Support\Agents\AgentAccess::class)) {
    echo '  credits a call minute (as the site works it out): ', \App\Support\Agents\AgentAccess::creditsPerMinute(), "\n";
} else {
    echo "  this site's code has no voice agents yet\n";
}
