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
    $subs = Subscription::query()->where('status', 'active')->with('plan')->get()
        ->filter(fn ($s) => $s->plan && (int) $s->plan->price_cents > 0);
    echo '  Active paid subscriptions: '.$subs->count()."\n";
    foreach ($subs as $sub) {
        $user = User::query()->find($sub->user_id);
        if (! $user) {
            continue;
        }
        $active = $access->resolveActivePlanForUserId((int) $user->id);
        $quota = $access->resolveQuotaPlanForUserId((int) $user->id, $active);
        $counter = UsageCounter::query()->where('user_id', $user->id)->where('period_ym', $ym)->first();
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
        ->get(['id', 'created_at', 'status', 'dialect', 'voice_name', 'tts_provider', 'tts_model', 'tts_fallback_used', 'style_prompt', 'is_dialogue', 'translate', 'input_text']);
    foreach ($rows as $g) {
        $text = trim(\App\Support\GeminiVocalTags::normalizeMarkup(trim((string) $g->input_text)));
        $rule = (string) $instruction->invoke($cloning, $g->dialect);
        $rewritten = $rule === '' ? 'no rule' : (\Illuminate\Support\Facades\Cache::has('listen:clone-dialect:v3:'.sha1($rule.'|'.$text)) ? 'rewritten' : 'NOT rewritten');
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

$section('PROBE (temporary): dialogues and the rewrite request', function () use ($line) {
    $line('Queue connection', config('queue.default'));
    $line('Queue retry_after', config('queue.connections.'.config('queue.default').'.retry_after'));
    $line('Voice proxy set', trim((string) config('services.gemini_tts.voice_proxy', '')) !== '');
    $eg = '/(?<!\p{L})(عايز|عايزة|دلوقتي|إزاي|ازاي|إزيك|ازيك|كده|أوي|اوي|مش|ليه|فين|بتاع|خالص|ده|دي|إيه|ايه)(?!\p{L})/u';
    $fu = '/(?<!\p{L})(لماذا|ماذا|الآن|سوف|لكن|لكنني|ليس|هذا|هذه|أريد|كيف|جدًا|جدا)(?!\p{L})/u';
    foreach (AudioGeneration::query()->where('is_dialogue', true)->where('dialect', 'ar-EG')->latest('id')->limit(8)->get() as $g) {
        $speakers = (array) (($g->dialogue_metadata ?? [])['speakers'] ?? []);
        $names = array_map(fn ($s) => trim((string) ($s['name'] ?? $s['id'] ?? '')), $speakers);
        $lines = array_values(array_filter(array_map('trim', preg_split("/\r\n|\n|\r/", (string) $g->input_text) ?: [])));
        $labelled = count(array_filter($lines, function ($l) use ($names) {
            foreach ($names as $n) {
                if ($n !== '' && str_starts_with($l, $n.':')) {
                    return true;
                }
            }

            return false;
        }));
        echo sprintf("  #%d speakers=%s lines=%d labelled=%d egyptian_words=%d fusha_words=%d\n", $g->id,
            implode(', ', array_map(fn ($s) => trim((string) ($s['name'] ?? '?')).'/'.($s['voice_name'] ?? $s['voice'] ?? '?').'/'.(($s['dialect'] ?? '') ?: 'script'), $speakers)),
            count($lines), $labelled, preg_match_all($eg, (string) $g->input_text), preg_match_all($fu, (string) $g->input_text));
    }

    $cloning = app(\App\Services\VoiceCloningService::class);
    $call = function (string $name, ...$args) use ($cloning) {
        $m = new \ReflectionMethod($cloning, $name);
        $m->setAccessible(true);

        return $m->invoke($cloning, ...$args);
    };
    $block = "مرحبًا يا صديقي، كيف حالك اليوم؟\nأنا بخير، لكنني متعب قليلًا من العمل.\nهذا رائع جدًا <ضحكة> لم أتوقع ذلك.\nهل تريد أن نذهب لنشرب القهوة الآن؟\nنعم بالتأكيد، انتظرني عشر دقائق فقط.";
    $protected = \App\Support\GeminiVocalTags::protect(trim(\App\Support\GeminiVocalTags::normalizeMarkup($block)));
    $prompt = $call('rewritePrompt', $protected['text'], $call('rewriteInstruction', 'ar-EG'));
    $prompt = str_replace("- Do not add a title", "- Keep the line breaks: one output line for each input line, in the same order.\n- Do not add a title", $prompt);
    foreach ([['gemini-3.5-flash', true], ['gemini-3.5-flash', false], ['gemini-3.6-flash', true]] as [$model, $minimal]) {
        $config = ['temperature' => 0.2] + ($minimal ? ['thinkingConfig' => ['thinkingLevel' => 'minimal']] : []);
        $t = microtime(true);
        try {
            $r = $call('request', 'POST', '/models/'.$model.':generateContent', ['contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]], 'generationConfig' => $config], 25);
            $ms = (int) round((microtime(true) - $t) * 1000);
            $raw = (string) data_get($r->json(), 'candidates.0.content.parts.0.text', '');
            $clean = $raw !== '' ? $call('cleanSpokenTranscript', $raw, $protected['text']) : '';
            $out = array_values(array_filter(array_map('trim', preg_split("/\r\n|\n|\r/", $clean) ?: [])));
            echo sprintf("  %s minimal=%s: HTTP %d in %d ms, lines %d/5, tag kept=%s, thoughts=%s\n", $model, $minimal ? 'yes' : 'no', $r->status(), $ms, count($out),
                \App\Support\GeminiVocalTags::placeholdersIntact($clean, count($protected['tags'])) ? 'yes' : 'NO', data_get($r->json(), 'usageMetadata.thoughtsTokenCount', 0));
            if ($r->status() !== 200) {
                echo '     '.mb_substr((string) $r->body(), 0, 300)."\n";
            }
            foreach ($out as $o) {
                echo "     | {$o}\n";
            }
        } catch (\Throwable $e) {
            echo sprintf("  %s minimal=%s: FAILED after %d ms: %s\n", $model, $minimal ? 'yes' : 'no', (int) round((microtime(true) - $t) * 1000), mb_substr($e->getMessage(), 0, 160));
        }
    }
});

echo "\n== Summary\n";
echo $problems === [] ? "  No problems found.\n" : '  '.count($problems)." problem(s) flagged above (lines starting with !!).\n";
