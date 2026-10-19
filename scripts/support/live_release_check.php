<?php

/*
 * Read-only: is the server running main, and are credits, daily limits and
 * plans working as intended? The workflow puts a checksum list of main's
 * files in $MANIFEST ahead of this script ("sha1  path" per line).
 * On MySQL the session is READ ONLY before any query. Emails are masked.
 */

use App\Models\AudioGeneration;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use App\Support\Credits;
use App\Support\PlanUsageGuard;
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

$mask = static function (?string $email): string {
    $email = (string) $email;
    if (! str_contains($email, '@')) {
        return $email === '' ? '—' : '***';
    }
    [$local, $domain] = explode('@', $email, 2);

    return mb_substr($local, 0, 3).'***@'.$domain;
};
$line = static function (string $label, $value): void {
    echo '  '.str_pad($label, 44).(is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value).PHP_EOL;
};
$problems = [];
$problem = static function (string $text) use (&$problems): void {
    $problems[] = $text;
    echo "  !! {$text}\n";
};
$section = static function (string $title, callable $body) use ($problem): void {
    echo "\n== {$title}\n";
    try {
        $body();
    } catch (\Throwable $e) {
        $problem("{$title}: check failed: ".get_class($e).': '.mb_substr($e->getMessage(), 0, 200));
    }
};

// ---------------------------------------------------------------- code
$deployedAt = null;
$section('Code on the server vs main', function () use ($root, $line, $problem, &$deployedAt) {
    global $MANIFEST;
    $expected = [];
    foreach (preg_split('/\R/', trim((string) ($MANIFEST ?? ''))) as $row) {
        if (preg_match('/^([0-9a-f]{40})\s+\*?(.+)$/', trim($row), $m)) {
            $expected[$m[2]] = $m[1];
        }
    }
    if ($expected === []) {
        $problem('No checksum list was sent: code not compared.');

        return;
    }
    $missing = $different = [];
    foreach ($expected as $path => $sha) {
        $file = $root.'/'.$path;
        if (! is_file($file)) {
            $missing[] = $path;
        } elseif (sha1_file($file) !== $sha) {
            $different[] = $path;
        }
    }
    $line('Files on main checked', count($expected));
    $line('Same as main', count($expected) - count($missing) - count($different));
    if ($different) {
        $problem(count($different).' file(s) differ from main:');
        foreach (array_slice($different, 0, 60) as $p) {
            echo "       ~ {$p}\n";
        }
    }
    if ($missing) {
        $problem(count($missing).' file(s) from main are missing:');
        foreach (array_slice($missing, 0, 60) as $p) {
            echo "       - {$p}\n";
        }
    }
    $guard = $root.'/app/Support/PlanUsageGuard.php';
    $deployedAt = is_file($guard) ? \Illuminate\Support\Carbon::createFromTimestamp((int) filemtime($guard)) : null;
    $line('PlanUsageGuard.php last uploaded (UTC)', $deployedAt?->toDateTimeString() ?? '—');
});

$section('Migrations', function () use ($root, $line, $problem) {
    $files = collect(glob($root.'/database/migrations/*.php'))->map(fn ($f) => basename($f, '.php'));
    $ran = DB::table('migrations')->pluck('migration');
    $pending = $files->diff($ran)->values();
    $line('Migration files / ran', $files->count().' / '.$ran->count());
    if ($pending->isNotEmpty()) {
        $problem('Pending migrations (run php8.4 artisan migrate --force):');
        foreach ($pending as $m) {
            echo "       - {$m}\n";
        }
    } else {
        $line('Pending migrations', 'none');
    }
});

$section('Settings', function () use ($root, $line, $problem) {
    $line('APP_ENV / debug', config('app.env').' / '.(config('app.debug') ? 'ON' : 'off'));
    if (config('app.debug')) {
        $problem('APP_DEBUG is on in live.');
    }
    // With APP_ENV local or testing, a payment event nobody signed is believed.
    if (in_array(config('app.env'), ['local', 'testing'], true)) {
        $problem('APP_ENV is '.config('app.env').': set it to production, or unsigned payment events are accepted.');
    }
    $line('Stripe webhook signing secret', trim((string) config('services.stripe.webhook_secret', '')) !== '' ? 'set' : 'MISSING');
    if (trim((string) config('services.stripe.webhook_secret', '')) === '') {
        $problem('STRIPE_WEBHOOK_SECRET is empty: Stripe\'s payment events are refused (503).');
    }
    $line('App timezone / now (UTC)', config('app.timezone').' / '.now()->toDateTimeString());
    $line('Daily limit time zone', (string) config('listen.billing.daily_limit_timezone', '(not set: code falls back to Africa/Cairo)'));
    if (method_exists(PlanUsageGuard::class, 'startOfMemberDay')) {
        $line('Members\' day started at (UTC)', PlanUsageGuard::startOfMemberDay()->toDateTimeString());
    } else {
        $problem('PlanUsageGuard has no startOfMemberDay(): the Cairo-day fix is not on the server.');
    }
    $line('Config cached', is_file($root.'/bootstrap/cache/config.php') ? 'yes ('.date('Y-m-d H:i', (int) filemtime($root.'/bootstrap/cache/config.php')).' UTC)' : 'no');
    $line('Credits class', class_exists(Credits::class) ? Credits::PER_SECOND.'/s, '.Credits::PER_MINUTE.'/min' : 'MISSING');
    if (! class_exists(Credits::class)) {
        $problem('App\\Support\\Credits is missing: credits code not deployed.');
    }
    $line('Overage allowed for any plan', PlanUsageGuard::allowsOverage(Plan::query()->first()) ? 'YES' : 'no');
    $line('Audio processing / queue', config('listen.audio.processing').' / '.config('queue.default'));
    if (class_exists(\App\Support\FreeAllowance::class)) {
        $line('Free plan gives monthly credits', \App\Support\FreeAllowance::freePlanGivesMonthlyChars());
    }
    if (class_exists(\App\Support\AudioFormats::class)) {
        $line('Server can convert audio (ffmpeg)', \App\Support\AudioFormats::serverCanConvert());
    }
});

// ---------------------------------------------------------------- plans
$section('Plans (credits, minutes, limits, profit at full use)', function () use ($line, $problem) {
    foreach (Plan::query()->orderBy('price_cents')->get() as $plan) {
        $credits = (int) $plan->monthly_chars;
        $minutes = class_exists(Credits::class) ? Credits::minutesFor($credits) : null;
        $price = (int) $plan->price_cents / 100;
        $profit = '';
        if ($price > 0 && class_exists(\App\Support\PlanCostEstimate::class) && method_exists(\App\Support\PlanCostEstimate::class, 'fullUse')) {
            $e = \App\Support\PlanCostEstimate::fullUse($credits, $price);
            $profitNow = $price > 0 ? round(($e['net'] - $e['cost_now']) / $price * 100, 1) : 0;
            $profit = "profit full use now {$profitNow}% / 2027 {$e['percent']}%";
            if ($e['percent'] < 20 && $plan->is_active) {
                $problem("Plan {$plan->slug}: below 20% profit at full use from Jan 2027 ({$e['percent']}%).");
            }
        }
        printf("  #%-2d %-11s %-12s %s $%-6s credits=%-9s =%s min  daily=%-4s monthly_req=%-6s max/req=%-5s overage=%s formats=%s %s\n",
            $plan->id, $plan->slug, (string) ($plan->kind ?? 'subscription'), $plan->is_active ? 'active ' : 'hidden ', number_format($price, 2),
            number_format($credits), $minutes === null ? '?' : number_format($minutes), (int) ($plan->daily_requests ?? 0),
            (int) $plan->monthly_requests, (int) $plan->max_chars_per_request, $plan->allow_overage ? 'ON' : 'off',
            json_encode($plan->download_formats ?? null), $profit);
        printf("       voices=%s dialogue=%s sound_environments=%s\n", (string) ($plan->voice_tier ?? 'standard'),
            ($plan->dialogue_enabled ?? false) ? 'ON' : 'off', ($plan->sound_effects_enabled ?? false) ? 'ON' : 'off');
        if ($plan->allow_overage) {
            $problem("Plan {$plan->slug} still has overage switched on in the database (the code ignores it).");
        }
        if ($price > 0 && $plan->is_active && $credits >= 400000 && $minutes !== null && $minutes > 3000) {
            $problem("Plan {$plan->slug}: {$credits} credits = {$minutes} minutes a month. Was the old character number left in?");
        }
    }
});

// ---------------------------------------------------------------- paid members
$section('Paying members this month', function () use ($mask, $problem) {
    $access = app(\App\Services\PlanAccessService::class);
    $ym = now()->format('Y-m');
    $all = Subscription::query()->where('status', 'active')->with('plan')->get()
        ->filter(fn ($s) => $s->plan && (int) $s->plan->price_cents > 0);
    // A plan whose period ended (a month paid by hand, a plan given for a while) is the free plan now, as it should be.
    $subs = $all->filter(fn ($s) => $s->current_period_end === null || $s->current_period_end->gte(now()->subHours(2)));
    echo '  Active paid subscriptions: '.$subs->count().($all->count() > $subs->count() ? ' (and '.($all->count() - $subs->count()).' whose period ended, now on the free plan)' : '')."\n";
    foreach ($subs as $sub) {
        $user = User::query()->find($sub->user_id);
        if (! $user) {
            continue;
        }
        $active = $access->resolveActivePlanForUserId((int) $user->id);
        $quota = $access->resolveQuotaPlanForUserId((int) $user->id, $active);
        // The member's own credit period (their plan's month) where the app has them.
        $key = class_exists(\App\Support\CreditPeriod::class) ? \App\Support\CreditPeriod::current((int) $user->id)['key'] : $ym;
        $counter = UsageCounter::query()->where('user_id', $user->id)->where('period_ym', $key)->first();
        $limit = PlanUsageGuard::charsLimit($quota, $counter);
        $used = (int) ($counter->total_chars ?? 0);
        $seconds = (int) ($counter->total_seconds ?? 0);
        $req = (int) ($counter->total_requests ?? 0);
        $today = PlanUsageGuard::requestsToday((int) $user->id);
        $daily = PlanUsageGuard::dailyRequests($quota);
        printf("  user %-5d %-28s %-10s credits %s of %s (%s min used of %s)  requests %d/%s  today %d/%s\n",
            $user->id, $mask($user->email), $active->slug ?? '-', number_format($used), number_format($limit),
            number_format($seconds / 60, 1), class_exists(Credits::class) ? number_format(Credits::minutesFor($limit)) : '?',
            $req, PlanUsageGuard::hasUnlimitedRequests($quota) ? '∞' : PlanUsageGuard::planRequests($quota), $today, $daily ?: '∞');
        if (($active->slug ?? null) !== ($sub->plan->slug ?? null)) {
            $problem("user {$user->id}: subscription plan {$sub->plan->slug} but the code resolves {$active?->slug}.");
        }
        if ($used > $limit) {
            $problem("user {$user->id}: used {$used} credits, over the {$limit} limit.");
        }
        if ($daily > 0 && $today > $daily) {
            $problem("user {$user->id}: {$today} generations today, over the daily {$daily}.");
        }
    }
});

// ---------------------------------------------------------------- daily rule audit
$section('Daily limit audit (Cairo days, last 3 days, all members with a daily limit)', function () use ($mask, $problem, $line) {
    $zone = (string) config('listen.billing.daily_limit_timezone', 'Africa/Cairo');
    $access = app(\App\Services\PlanAccessService::class);
    $since = now($zone)->subDays(2)->startOfDay()->setTimezone('UTC');
    $rows = AudioGeneration::query()->where('created_at', '>=', $since)->where('status', '!=', 'failed')->whereNotNull('user_id')
        ->get(['id', 'user_id', 'created_at']);
    $byUserDay = $rows->groupBy(fn ($g) => $g->user_id.'|'.$g->created_at->copy()->setTimezone($zone)->format('Y-m-d'));
    $over = 0;
    foreach ($byUserDay as $key => $list) {
        [$userId, $day] = explode('|', $key);
        $quota = $access->resolveQuotaPlanForUserId((int) $userId);
        $daily = PlanUsageGuard::dailyRequests($quota);
        if ($daily > 0 && $list->count() > $daily) {
            $over++;
            $user = User::query()->find($userId);
            $problem(sprintf('user %d %s on %s (Cairo): %d generations, daily limit %d (first %s, last %s UTC)',
                $userId, $mask($user?->email), $day, $list->count(), $daily, $list->min('created_at'), $list->max('created_at')));
        }
    }
    $line('Member-days checked', $byUserDay->count());
    $line('Member-days over the daily limit', $over);
});

// ---------------------------------------------------------------- every member's limits
// Credits charged by audio length, and no monthly credits on the free plan, apply from
// RULES_SINCE (live from Oct 14 2026): earlier use this month followed the old rules.
$section('Every member (free, trial, pack and paid): anything made past a limit since the current rules?', function () use ($mask, $problem, $line) {
    $access = app(\App\Services\PlanAccessService::class);
    $rulesSince = max(now()->startOfMonth(), \Carbon\Carbon::parse('2026-10-14 06:00:00'));
    $line('Checked from (UTC)', $rulesSince->toDateTimeString());
    // Each member's current credit period (their plan's own month where the app has them).
    $periodOf = fn (int $userId): array => class_exists(\App\Support\CreditPeriod::class)
        ? \App\Support\CreditPeriod::current($userId)
        : ['key' => now()->format('Y-m'), 'starts_at' => now()->startOfMonth()];
    $gens = AudioGeneration::query()->where('created_at', '>=', $rulesSince)->whereNotNull('user_id')->where('status', '!=', 'failed')
        ->get(['id', 'user_id', 'status', 'duration_seconds', 'billed_to_pack', 'input_text', 'voice_tier', 'created_at']);
    $line('Generations since then / members', $gens->count().' / '.$gens->pluck('user_id')->unique()->count());
    $flagged = 0;
    foreach ($gens->groupBy('user_id') as $userId => $mine) {
        $user = User::query()->find($userId);
        $quota = $access->resolveQuotaPlanForUserId((int) $userId);
        $period = $periodOf((int) $userId);
        $counter = UsageCounter::query()->where('user_id', $userId)->where('period_ym', $period['key'])->first();
        $limit = PlanUsageGuard::charsLimit($quota, $counter);
        $used = (int) ($counter->total_chars ?? 0);
        // Only what the current period's counter holds: made since it started.
        $mine = $mine->filter(fn ($g) => $g->created_at === null || $g->created_at->gte($period['starts_at']));
        $monthly = $mine->where('billed_to_pack', '!=', true);
        $tag = sprintf('user %d %s (%s)', $userId, $mask($user?->email), $quota->slug ?? '-');
        $earned = class_exists(Credits::class) ? Credits::forSeconds((int) $monthly->where('status', 'done')->sum('duration_seconds')) : 0;
        $issues = [];
        if ($monthly->isNotEmpty() && $limit <= 0) {
            $issues[] = $monthly->count().' made on the monthly allowance with no credits on the plan (not a pack)';
        }
        if ($used > $limit) {
            $issues[] = "{$used} credits used of {$limit}";
        }
        if ($earned > $used + 450) {
            $issues[] = "audio since then is worth {$earned} credits but the period's counter holds {$used}";
        }
        if (! PlanUsageGuard::hasUnlimitedRequests($quota) && (int) ($counter->total_requests ?? 0) > PlanUsageGuard::planRequests($quota)) {
            $issues[] = (int) $counter->total_requests.' generations of '.PlanUsageGuard::planRequests($quota).' a month';
        }
        $tooLong = $mine->filter(fn ($g) => mb_strlen((string) $g->input_text) > PlanUsageGuard::maxCharsPerRequest($quota) + 50)->count();
        if ($tooLong > 0) {
            $issues[] = "{$tooLong} script(s) over ".PlanUsageGuard::maxCharsPerRequest($quota).' characters';
        }
        if ($mine->where('voice_tier', 'hd')->isNotEmpty() && $mine->where('voice_tier', 'hd')->where('billed_to_pack', '!=', true)->isNotEmpty()
            && \App\Support\VoiceTier::normalize((string) ($access->resolveAccessPlanForUserId((int) $userId)?->voice_tier ?? 'standard')) !== \App\Support\VoiceTier::HD) {
            $issues[] = 'premium voices on a plan without them (not a pack)';
        }
        if ($issues !== []) {
            $flagged++;
            $problem("{$tag}: ".implode('; ', $issues).'.');
        }
    }
    $line('Members past a limit since then', $flagged);
    if (Schema::hasTable('char_pack_wallets')) {
        $line('Pack wallets overdrawn', \App\Models\CharPackWallet::query()->whereColumn('chars_used', '>', 'chars_total')->count());
    }
});

// ---------------------------------------------------------------- generations
$section('Generations since the last upload of the usage code', function () use ($line, $problem, &$deployedAt) {
    $since = $deployedAt ?? now()->subDay();
    $q = AudioGeneration::query()->where('created_at', '>=', $since);
    $line('Since (UTC)', $since->toDateTimeString());
    $line('By status', json_encode((clone $q)->selectRaw('status, count(*) n')->groupBy('status')->pluck('n', 'status')));
    $done = (clone $q)->where('status', 'done');
    $line('Done', (clone $done)->count());
    if (Schema::hasColumn('audio_generations', 'duration_measured')) {
        $unmeasured = (clone $done)->where('duration_measured', false)->count();
        $line('Done without a measured length', $unmeasured);
        if ($unmeasured > 0) {
            $problem("{$unmeasured} generation(s) since the upload were charged from an estimate, not the real audio length.");
        }
        $old = AudioGeneration::query()->where('status', 'done')->whereNotNull('audio_path')->where('duration_measured', false)->count();
        $line('Older done generations not yet measured', $old.($old > 0 ? ' (run: php8.4 artisan listen:measure-audio-lengths --apply)' : ''));
    }
    $line('Stuck in queued/processing > 15 min', AudioGeneration::query()->whereIn('status', ['queued', 'processing', 'pending'])->where('created_at', '<', now()->subMinutes(15))->where('created_at', '>=', now()->subDays(2))->count());
    if (Schema::hasTable('generation_errors')) {
        $errors = DB::table('generation_errors')->where('created_at', '>=', $since)->get(['source', 'exception_class', 'message', 'occurrences'])
            ->groupBy(fn ($e) => '['.$e->source.'] '.class_basename((string) $e->exception_class).': '.preg_replace('/\S+@\S+/', '***', mb_substr((string) $e->message, 0, 140)))
            ->map(fn ($list) => (int) $list->sum('occurrences'))->sortDesc();
        $line('Generation errors since (grouped)', $errors->sum());
        foreach ($errors->take(12) as $text => $n) {
            echo "       {$n}× {$text}\n";
        }
    }
    if (Schema::hasTable('failed_jobs')) {
        $line('Failed queue jobs since', DB::table('failed_jobs')->where('failed_at', '>=', $since)->count());
    }
});

$section('Generation speed and reliability (last 7 days)', function () use ($root, $line) {
    $since = now()->subDays(7);
    $pct = static function ($values, float $p): string {
        $v = collect($values)->filter(fn ($x) => $x !== null)->sort()->values();

        return $v->isEmpty() ? '—' : (string) $v[(int) min($v->count() - 1, floor($p * ($v->count() - 1) + 0.5))];
    };
    $gens = AudioGeneration::query()->where('created_at', '>=', $since)
        ->get(['id', 'status', 'is_dialogue', 'tts_provider', 'tts_model', 'tts_latency_ms', 'tts_fallback_used', 'voice_tier', 'input_text', 'created_at', 'updated_at', 'error_message']);
    $line('Generations', $gens->count().' '.json_encode($gens->countBy('status')));
    $line('Served from cache', $gens->where('tts_provider', 'cache')->count());
    $line('Fallback engine used', $gens->where('tts_fallback_used', true)->count());
    $made = $gens->where('status', 'done')->where('tts_provider', '!=', 'cache');
    echo "  Made (not cache), seconds — speech request p50/p90/max | whole wait (click to done) p50/p90/max | chars p50\n";
    foreach ($made->groupBy(fn ($g) => ($g->is_dialogue ? 'dialogue' : 'single').' '.$g->voice_tier.' '.$g->tts_provider.'/'.$g->tts_model) as $key => $list) {
        $tts = $list->map(fn ($g) => $g->tts_latency_ms !== null ? round($g->tts_latency_ms / 1000, 1) : null);
        $wait = $list->map(fn ($g) => $g->updated_at && $g->created_at ? $g->created_at->diffInSeconds($g->updated_at) : null);
        echo sprintf("    %-58s n=%-4d %s/%s/%s | %s/%s/%s | %s\n", $key, $list->count(), $pct($tts, .5), $pct($tts, .9), $pct($tts, 1),
            $pct($wait, .5), $pct($wait, .9), $pct($wait, 1), $pct($list->map(fn ($g) => mb_strlen((string) $g->input_text)), .5));
    }
    echo "  Premium single voice by length (speech request p50 / whole wait p50, seconds):\n";
    $premium = $made->where('voice_tier', '!=', 'standard')->where('is_dialogue', false);
    foreach ([[0, 300], [300, 900], [900, 2000], [2000, 100000]] as [$lo, $hi]) {
        $list = $premium->filter(fn ($g) => ($n = mb_strlen((string) $g->input_text)) >= $lo && $n < $hi);
        echo sprintf("    %5d-%-6d n=%-4d %s / %s\n", $lo, $hi, $list->count(),
            $pct($list->map(fn ($g) => $g->tts_latency_ms !== null ? round($g->tts_latency_ms / 1000, 1) : null), .5),
            $pct($list->map(fn ($g) => $g->updated_at && $g->created_at ? $g->created_at->diffInSeconds($g->updated_at) : null), .5));
    }
    echo "  Failed, by message:\n";
    foreach ($gens->where('status', 'failed')->countBy(fn ($g) => mb_substr(preg_replace('/\d{3,}/', 'N', (string) $g->error_message), 0, 110))->sortDesc()->take(8) as $msg => $n) {
        echo "       {$n}× {$msg}\n";
    }
    if (Schema::hasTable('tts_logs')) {
        $logs = DB::table('tts_logs')->where('created_at', '>=', $since)->get(['provider', 'status', 'failure_reason', 'fallback_used']);
        $line('Speech requests logged', $logs->count().' '.json_encode($logs->countBy(fn ($l) => $l->provider.':'.$l->status)));
        foreach ($logs->whereNotNull('failure_reason')->countBy(fn ($l) => mb_substr(preg_replace('/\d{3,}/', 'N', (string) $l->failure_reason), 0, 110))->sortDesc()->take(8) as $msg => $n) {
            echo "       {$n}× {$msg}\n";
        }
    }
    if (Schema::hasTable('jobs')) {
        $oldest = DB::table('jobs')->min('available_at');
        $line('Jobs waiting now', DB::table('jobs')->count().($oldest ? ', oldest '.(time() - (int) $oldest).' s' : ''));
    }
    if (Schema::hasTable('failed_jobs')) {
        $failed = DB::table('failed_jobs')->where('failed_at', '>=', $since)->get(['exception']);
        $line('Failed queue jobs', $failed->count());
        foreach ($failed->countBy(fn ($f) => mb_substr(strtok((string) $f->exception, "\n"), 0, 140))->sortDesc()->take(5) as $msg => $n) {
            echo "       {$n}× {$msg}\n";
        }
    }
    $since3 = now()->subDays(3)->toDateTimeString();
    $counts = [];
    foreach (array_filter(array_merge([$root.'/storage/logs/laravel.log'], glob($root.'/storage/logs/laravel-*.log') ?: []), 'is_file') as $file) {
        if (filemtime($file) < strtotime($since3)) {
            continue;
        }
        $fh = fopen($file, 'r');
        while (($row = fgets($fh)) !== false) {
            if (preg_match('/^\[(\d{4}-\d\d-\d\d[ T]\d\d:\d\d:\d\d)[^\]]*\] \w+\.(WARNING|ERROR): ([^{]{0,90})(\{.{0,120})?/', $row, $m) && $m[1] >= $since3
                && preg_match('/gemini|tts|speech|voice|rewrite|audio|key|quota|timed? ?out/i', $m[3].($m[4] ?? ''))) {
                $key = $m[2].' '.trim($m[3]).' '.preg_replace(['/\d{3,}/', '/\S+@\S+/'], ['N', '***'], (string) ($m[4] ?? ''));
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }
        fclose($fh);
    }
    arsort($counts);
    $line('Speech warnings/errors in the log, 3 days', array_sum($counts));
    foreach (array_slice($counts, 0, 12, true) as $msg => $n) {
        echo "       {$n}× {$msg}\n";
    }
    foreach (['max_chars_per_request', 'max_parallel_requests', 'timeout_seconds', 'retry_budget_seconds'] as $key) {
        $line('gemini_tts.'.$key, json_encode(config('services.gemini_tts.'.$key)));
    }
    $line('Speech model setting', (string) \App\Support\GeminiTtsModel::resolve());
});

$section('Credits charged vs audio made (this month, done, not pack)', function () use ($line, $problem, &$deployedAt) {
    $since = $deployedAt ?? now()->startOfMonth();
    // After the upload every finished generation should cost 15 credits a second of its audio.
    $gens = AudioGeneration::query()->where('created_at', '>=', $since)->where('status', 'done')
        ->where(fn ($q) => $q->whereNull('billed_to_pack')->orWhere('billed_to_pack', false))->get(['user_id', 'duration_seconds']);
    $seconds = (int) $gens->sum('duration_seconds');
    $line('Audio seconds made since upload', $seconds.' s = '.number_format($seconds / 60, 1).' min');
    $line('Credits that should have been charged', class_exists(Credits::class) ? number_format(Credits::forSeconds($seconds)) : '?');
});

$section('Laravel log since the upload', function () use ($root, $line, &$deployedAt) {
    $since = ($deployedAt ?? now()->subDay())->toDateTimeString();
    $files = array_filter(array_merge([$root.'/storage/logs/laravel.log'], glob($root.'/storage/logs/laravel-*.log') ?: []), 'is_file');
    $counts = [];
    $total = 0;
    foreach ($files as $file) {
        if (filemtime($file) < strtotime($since)) {
            continue;
        }
        $fh = fopen($file, 'r');
        while (($row = fgets($fh)) !== false) {
            if (! preg_match('/^\[(\d{4}-\d\d-\d\d[ T]\d\d:\d\d:\d\d)[^\]]*\] \w+\.(ERROR|CRITICAL|ALERT|EMERGENCY): (.{0,150})/', $row, $m) || $m[1] < $since) {
                continue;
            }
            $total++;
            $key = preg_replace(['/\S+@\S+/', '/\d{3,}/'], ['***', 'N'], $m[3]);
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        fclose($fh);
    }
    arsort($counts);
    $line('Errors logged since', $total);
    foreach (array_slice($counts, 0, 12, true) as $msg => $n) {
        echo "       {$n}× {$msg}\n";
    }
});

$section('Arabic dialect path (last 15 premium Arabic generations with a dialect)', function () use ($root, $line, $problem) {
    $line('Dialect rewrite enabled', (bool) config('services.gemini_tts.dialect_rewrite', true));
    $line('Admin voice mappings (override dialect)', \App\Models\SiteSetting::query()->where('key', 'like', 'voice_mapping_%')->count());
    $cloning = app(\App\Services\VoiceCloningService::class);
    $instruction = new \ReflectionMethod($cloning, 'rewriteInstruction');
    $instruction->setAccessible(true);
    $rows = AudioGeneration::query()->whereIn('target_language', ['ar', 'ar-XA', 'ar-xa'])->whereNotNull('dialect')
        ->where('voice_tier', '!=', 'standard')->latest('id')->limit(15)
        ->get(['id', 'created_at', 'status', 'dialect', 'voice_name', 'tts_provider', 'tts_model', 'tts_fallback_used', 'style_prompt', 'is_dialogue', 'translate', 'input_text', 'dialogue_metadata']);
    // Whether the script (a dialogue: each speaker's lines, per dialect) went through the rewrite.
    $state = function (string $text, string $dialect) use ($cloning, $instruction): string {
        $text = trim(\App\Support\GeminiVocalTags::normalizeMarkup(trim($text)));
        $rule = (string) $instruction->invoke($cloning, $dialect);
        if ($rule === '' || $text === '') {
            return 'no rule';
        }
        if (method_exists(\App\Support\ArabicDialects::class, 'isPlainFusha') && \App\Support\ArabicDialects::isPlainFusha($dialect) && ! \App\Support\ArabicDialects::looksColloquial($text)) {
            return 'fusha, none needed';
        }

        return \Illuminate\Support\Facades\Cache::has('listen:clone-dialect:v3:'.sha1($rule.'|'.$text)) ? 'rewritten' : 'NOT rewritten';
    };
    foreach ($rows as $g) {
        if (! $g->is_dialogue) {
            $rewritten = $state((string) $g->input_text, (string) $g->dialect);
        } else {
            $own = [];
            foreach ((array) (($g->dialogue_metadata ?? [])['speakers'] ?? []) as $sp) {
                $name = trim((string) ($sp['name'] ?? ''));
                if ($name !== '') {
                    $own[$name] = trim((string) ($sp['dialect'] ?? '')) ?: (string) $g->dialect;
                }
            }
            $current = array_key_first($own);
            $groups = [];
            foreach (preg_split("/\r\n|\n|\r/", (string) $g->input_text) ?: [] as $l) {
                $spoken = trim($l);
                foreach (array_keys($own) as $name) {
                    if (str_starts_with($spoken, $name.':')) {
                        [$current, $spoken] = [$name, trim(substr($spoken, strlen($name) + 1))];
                        break;
                    }
                }
                if ($spoken !== '') {
                    $groups[$current !== null ? $own[$current] : (string) $g->dialect][] = $spoken;
                }
            }
            $rewritten = implode(', ', array_map(fn ($d) => $d.': '.$state(implode("\n", $groups[$d]), $d), array_keys($groups)));
        }
        echo sprintf("  #%d %s %s %s voice=%s via=%s/%s fallback=%s custom_prompt=%s dialogue=%s translate=%s %s\n",
            $g->id, $g->created_at?->format('m-d H:i'), $g->status, $g->dialect, $g->voice_name, $g->tts_provider ?: '?', $g->tts_model ?: '?',
            $g->tts_fallback_used ? 'YES' : 'no', trim((string) $g->style_prompt) !== '' ? 'YES' : 'no', $g->is_dialogue ? 'yes' : 'no',
            $g->translate ? 'yes' : 'no', $rewritten);
    }
    $since = now()->subDays(3)->toDateTimeString();
    $counts = [];
    foreach (array_filter(array_merge([$root.'/storage/logs/laravel.log'], glob($root.'/storage/logs/laravel-*.log') ?: []), 'is_file') as $file) {
        if (filemtime($file) < strtotime($since)) {
            continue;
        }
        $fh = fopen($file, 'r');
        while (($row = fgets($fh)) !== false) {
            if (preg_match('/^\[(\d{4}-\d\d-\d\d[ T]\d\d:\d\d:\d\d)[^\]]*\] \w+\.WARNING: ((?:Cloned-voice dialect rewrite|Text request)[^{]{0,80})(.{0,160})/', $row, $m) && $m[1] >= $since) {
                $key = $m[2].' '.preg_replace('/\d{3,}/', 'N', $m[3]);
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }
        fclose($fh);
    }
    $line('Rewrite warnings, last 3 days', array_sum($counts));
    foreach (array_slice($counts, 0, 6, true) as $msg => $n) {
        echo "       {$n}× {$msg}\n";
    }
});

echo "\n== Summary\n";
echo $problems === [] ? "  No problems found.\n" : '  '.count($problems)." problem(s) flagged above (lines starting with !!).\n";
