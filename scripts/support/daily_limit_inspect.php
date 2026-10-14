<?php

/*
 * Read-only: why a member made more generations in a day than their plan's
 * daily limit. Shows the live code's daily check, the member's plan and its
 * limit, and their generations per day with where each came from.
 *
 * The member is found by the SHA-256 of their lower-cased email
 * (ACCOUNT_EMAIL_SHA256). On MySQL the session is READ ONLY before any query.
 *
 *   ssh user@host 'cd ~/public_html && ACCOUNT_EMAIL_SHA256=<hash> php' < scripts/support/daily_limit_inspect.php
 */

use App\Models\AudioGeneration;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

ini_set('display_errors', 'stderr');

$root = getcwd();
if (! is_file($root.'/artisan')) {
    fwrite(STDERR, "Run this from the Laravel app root.\n");
    exit(1);
}

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
    DB::statement('SET SESSION TRANSACTION READ ONLY');
}

$hash = strtolower(trim((string) getenv('ACCOUNT_EMAIL_SHA256')));
if (! preg_match('/^[0-9a-f]{64}$/', $hash)) {
    fwrite(STDERR, "ACCOUNT_EMAIL_SHA256 must be a sha256 hex digest.\n");
    exit(1);
}

$line = static function (string $label, $value): void {
    echo str_pad($label, 40).(is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value).PHP_EOL;
};
$fileHas = static function (string $path, string $needle) use ($root): string {
    $file = $root.'/'.$path;
    if (! is_file($file)) {
        return 'file missing';
    }

    return (str_contains((string) file_get_contents($file), $needle) ? 'yes' : 'NO').' (modified '.date('Y-m-d H:i', (int) filemtime($file)).' UTC)';
};

echo "== Live code\n";
$line('App timezone / now', config('app.timezone').' / '.now()->toDateTimeString());
$line('Guard has daily check', $fileHas('app/Support/PlanUsageGuard.php', 'function assertWithinDailyLimit'));
$line('Dashboard calls daily check', $fileHas('app/Http/Controllers/DashboardController.php', 'assertWithinDailyLimit'));
$line('Extension calls daily check', $fileHas('app/Http/Controllers/Api/MessageReaderController.php', 'assertWithinDailyLimit'));
$line('Credits code present', is_file($root.'/app/Support/Credits.php') ? 'yes' : 'no');
$line('plans.daily_requests column', Schema::hasColumn('plans', 'daily_requests') ? 'yes' : 'NO');
$line('Migrations table: last 5', '');
foreach (DB::table('migrations')->orderByDesc('id')->limit(5)->pluck('migration') as $m) {
    echo '    '.$m.PHP_EOL;
}

echo "\n== Plans\n";
foreach (Plan::query()->orderBy('id')->get() as $plan) {
    printf("  #%-3d %-12s kind=%-12s active=%s price_cents=%-6s monthly_chars=%-9s monthly_requests=%-5s daily_requests=%s\n",
        $plan->id, $plan->slug, (string) ($plan->kind ?? '-'), $plan->is_active ? 'y' : 'n', $plan->price_cents,
        $plan->monthly_chars, $plan->monthly_requests, Schema::hasColumn('plans', 'daily_requests') ? (string) $plan->daily_requests : 'n/a');
}

$user = null;
User::query()->select('*')->orderBy('id')->chunk(1000, function ($users) use ($hash, &$user) {
    foreach ($users as $candidate) {
        if (hash('sha256', strtolower(trim((string) $candidate->email))) === $hash) {
            $user = $candidate;

            return false;
        }
    }
});
if (! $user) {
    echo "\nNo account with that email on this server.\n";
    exit(0);
}

echo "\n== Member\n";
$line('User id / joined', $user->id.' / '.$user->created_at);
foreach (Subscription::query()->where('user_id', $user->id)->orderBy('id')->get() as $sub) {
    $plan = Plan::query()->find($sub->plan_id);
    $line('Subscription #'.$sub->id, 'plan='.($plan->slug ?? $sub->plan_id).' status='.$sub->status.' created='.$sub->created_at.' updated='.$sub->updated_at
        .' ends='.($sub->ends_at ?? '—'));
}
if (Schema::hasTable('billing_trials')) {
    foreach (DB::table('billing_trials')->where('user_id', $user->id)->get() as $t) {
        $line('Trial #'.$t->id, 'status='.$t->status.' created='.$t->created_at);
    }
}
if (Schema::hasTable('manual_payment_requests')) {
    foreach (DB::table('manual_payment_requests')->where('user_id', $user->id)->get() as $r) {
        $line('Manual payment #'.$r->id, 'status='.($r->status ?? '-').' plan='.($r->plan_id ?? '-').' created='.$r->created_at.' updated='.$r->updated_at);
    }
}

try {
    $access = app(\App\Services\PlanAccessService::class);
    $active = $access->resolveActivePlanForUserId((int) $user->id);
    $quota = $access->resolveQuotaPlanForUserId((int) $user->id, $active);
    $line('Active plan (code)', ($active->slug ?? '—').' daily_requests='.($active->daily_requests ?? 'n/a'));
    $line('Quota plan used for limits', ($quota->slug ?? '—').' daily_requests='.($quota->daily_requests ?? 'n/a'));
    $line('Unpaid trial window', $access->userHasUnpaidTrialWindow((int) $user->id));
    if (method_exists(\App\Support\PlanUsageGuard::class, 'requestsToday')) {
        $line('Counted today by the guard', \App\Support\PlanUsageGuard::requestsToday((int) $user->id));
    }
} catch (\Throwable $e) {
    $line('Plan resolve error', $e->getMessage());
}

echo "\n== Generations per UTC day (last 10 days)\n";
$rows = AudioGeneration::query()
    ->where('user_id', $user->id)
    ->where('created_at', '>=', now()->subDays(10)->startOfDay())
    ->orderBy('id')
    ->get();
foreach ($rows->groupBy(fn ($g) => substr((string) $g->created_at, 0, 10)) as $day => $list) {
    $byStatus = $list->groupBy('status')->map->count()->all();
    $bySource = $list->groupBy(fn ($g) => (string) ($g->source_type ?? $g->source ?? '-'))->map->count()->all();
    printf("  %s  total=%-3d status=%s source=%s\n", $day, $list->count(), json_encode($byStatus), json_encode($bySource));
}

echo "\n== Each generation (last 10 days)\n";
foreach ($rows as $g) {
    printf("  #%-6d %s  status=%-10s source=%-10s tier=%-8s pack=%s len=%ss\n",
        $g->id, $g->created_at, $g->status, (string) ($g->source_type ?? '-'), (string) ($g->voice_tier ?? '-'),
        isset($g->billed_to_pack) ? ($g->billed_to_pack ? 'y' : 'n') : '-', (string) ($g->duration_seconds ?? '-'));
}
