<?php

/*
 * Read-only: what recent sign-ups did on the site, and why so many never
 * make a single voice. Looks at accounts created in the last SIGNUP_DAYS
 * days (default 30) and, for each one, every trace the app keeps: demo and
 * dashboard generations, errors and blocked texts, sessions (device, how
 * long they stayed, the last page they opened), API key, trial, referral,
 * and other accounts on the same IP or device.
 *
 * No names, emails or full IPs are printed: an email shows only its domain,
 * an IP its first two parts. On MySQL the session is READ ONLY before any
 * query runs; nothing is written.
 *
 *   ssh user@host 'cd ~/public_html && SIGNUP_DAYS=30 php' < scripts/support/inactive_signups_inspect.php
 */

use App\Models\Plan;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

ini_set('display_errors', 'stderr');

$root = getcwd();
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
    DB::statement('SET SESSION TRANSACTION READ ONLY');
}

$days = max(1, min(180, (int) (getenv('SIGNUP_DAYS') ?: 30)));
$since = now()->subDays($days);
$has = static fn (string $table): bool => Schema::hasTable($table);
$col = static fn (string $table, string $column): bool => Schema::hasTable($table) && Schema::hasColumn($table, $column);
$maskIp = static function (?string $ip): string {
    if (! $ip) {
        return '-';
    }
    $parts = preg_split('/[.:]/', $ip);

    return ($parts[0] ?? '?').'.'.($parts[1] ?? '?').'.x.x';
};
$domain = static fn (?string $email): string => str_contains((string) $email, '@') ? strtolower(substr((string) $email, strpos((string) $email, '@') + 1)) : '-';
$device = static function (?string $ua): string {
    $ua = (string) $ua;
    if ($ua === '') {
        return '-';
    }
    if (preg_match('/bot|crawl|spider|headless|python|curl|wget|okhttp|java\//i', $ua)) {
        return 'bot/script';
    }
    $kind = preg_match('/iPad|Tablet/i', $ua) ? 'tablet' : (preg_match('/Mobile|Android|iPhone/i', $ua) ? 'mobile' : 'desktop');
    $os = match (true) {
        (bool) preg_match('/iPhone|iPad|iOS/i', $ua) => 'iOS',
        (bool) preg_match('/Android/i', $ua) => 'Android',
        (bool) preg_match('/Windows/i', $ua) => 'Windows',
        (bool) preg_match('/Mac OS X|Macintosh/i', $ua) => 'macOS',
        (bool) preg_match('/Linux/i', $ua) => 'Linux',
        default => 'other',
    };
    $browser = match (true) {
        (bool) preg_match('/FBAN|FBAV|FB_IAB|Instagram/i', $ua) => 'Facebook/Instagram in-app',
        (bool) preg_match('/TikTok|musical_ly|Bytedance/i', $ua) => 'TikTok in-app',
        (bool) preg_match('/Snapchat/i', $ua) => 'Snapchat in-app',
        (bool) preg_match('/; wv\)|WebView/i', $ua) => 'Android WebView',
        (bool) preg_match('/Edg\//i', $ua) => 'Edge',
        (bool) preg_match('/OPR\/|Opera/i', $ua) => 'Opera',
        (bool) preg_match('/SamsungBrowser/i', $ua) => 'Samsung',
        (bool) preg_match('/CriOS|Chrome\//i', $ua) => 'Chrome',
        (bool) preg_match('/FxiOS|Firefox/i', $ua) => 'Firefox',
        (bool) preg_match('/Safari/i', $ua) => 'Safari',
        default => 'other',
    };

    return "{$kind} {$os} {$browser}";
};
$path = static function (?string $url): string {
    if (! $url) {
        return '-';
    }
    $p = (string) (parse_url($url, PHP_URL_PATH) ?: '/');
    $q = (string) (parse_url($url, PHP_URL_QUERY) ?: '');

    return $p.($q !== '' ? '?'.mb_substr(preg_replace('/(token|code|state|email|key)=[^&]*/i', '$1=…', $q), 0, 60) : '');
};
$ago = static fn ($from, $to): string => $from && $to ? Carbon::parse($from)->diffForHumans(Carbon::parse($to), ['syntax' => Carbon::DIFF_ABSOLUTE, 'parts' => 2]) : '-';

// ---------------------------------------------------------------- settings
$out = ['window_days' => $days, 'now_utc' => now()->utc()->toDateTimeString()];
$free = Plan::query()->where('slug', 'free')->first();
$out['settings'] = [
    'session_driver' => config('session.driver'),
    'session_lifetime_min' => config('session.lifetime'),
    'demo_allowance_chars' => class_exists(\App\Support\DemoAllowance::class) ? \App\Support\DemoAllowance::chars() : null,
    'free_plan' => $free ? array_intersect_key($free->toArray(), array_flip(['is_active', 'monthly_chars', 'monthly_minutes', 'monthly_requests', 'daily_requests', 'max_chars_per_request', 'voice_tier'])) : null,
];
try {
    $auth = app(\App\Services\Auth\AuthSettingsService::class);
    $out['settings']['auth'] = ['email' => $auth->emailEnabled(), 'google' => $auth->googleEnabled(), 'phone' => $auth->phoneEnabled()];
} catch (\Throwable $e) {
    $out['settings']['auth'] = 'n/a: '.class_basename($e);
}

// ------------------------------------------------------------- sign-ups
$users = User::query()->where('created_at', '>=', $since)->where('is_admin', false)->orderByDesc('id')->get();
$ids = $users->pluck('id')->all();
$countBy = static function (string $table, string $column = 'user_id') use ($ids, $has): array {
    if (! $has($table) || $ids === []) {
        return [];
    }

    return DB::table($table)->whereIn($column, $ids)->groupBy($column)->selectRaw("{$column} as uid, count(*) as n")->pluck('n', 'uid')->map(fn ($n) => (int) $n)->all();
};

$demo = $countBy('demo_voice_generations');
$demoChars = $has('demo_voice_generations') && $ids ? DB::table('demo_voice_generations')->whereIn('user_id', $ids)->groupBy('user_id')->selectRaw('user_id as uid, sum(billable_chars) as n')->pluck('n', 'uid')->all() : [];
$gens = $countBy('audio_generations');
$gensDone = $has('audio_generations') && $ids ? DB::table('audio_generations')->whereIn('user_id', $ids)->where('status', 'done')->groupBy('user_id')->selectRaw('user_id as uid, count(*) as n')->pluck('n', 'uid')->all() : [];
$errors = $countBy('generation_errors');
$blockedTexts = $countBy('blocked_generations');
$texts = $countBy('text_items');
$tts = $countBy('tts_logs');
$trials = $countBy('billing_trials');
$manual = $countBy('manual_payment_requests');

// Sessions: database driver keeps user_id, the device and the last page.
$sessions = [];
if (config('session.driver') === 'database' && $has('sessions') && $ids) {
    foreach (DB::table('sessions')->whereIn('user_id', $ids)->orderBy('last_activity')->get() as $s) {
        $last = null;
        try {
            $raw = base64_decode((string) $s->payload, true);
            $data = $raw !== false ? @unserialize($raw, ['allowed_classes' => false]) : false;
            if (! is_array($data) && config('session.encrypt')) {
                $data = @unserialize(decrypt((string) $raw, false), ['allowed_classes' => false]);
            }
            $last = is_array($data) ? ($data['_previous']['url'] ?? null) : null;
        } catch (\Throwable) {
        }
        $sessions[(int) $s->user_id][] = ['last_activity' => (int) $s->last_activity, 'ua' => (string) $s->user_agent, 'last_url' => $last, 'ip' => (string) $s->ip_address];
    }
}

// Other accounts on the same registration IP or device.
$sameIp = [];
$sameDevice = [];
$ips = $users->pluck('registration_ip')->filter()->unique()->values()->all();
if ($ips) {
    $sameIp = User::query()->whereIn('registration_ip', $ips)->groupBy('registration_ip')->selectRaw('registration_ip as ip, count(*) as n')->pluck('n', 'ip')->all();
}
$devs = $col('users', 'registration_device_id') ? $users->pluck('registration_device_id')->filter()->unique()->values()->all() : [];
if ($devs) {
    $sameDevice = User::query()->whereIn('registration_device_id', $devs)->groupBy('registration_device_id')->selectRaw('registration_device_id as d, count(*) as n')->pluck('n', 'd')->all();
}

$referrers = [];
if ($has('referral_visits') && $ids) {
    foreach (DB::table('referral_visits')->whereIn('converted_user_id', $ids)->get(['converted_user_id', 'http_referer', 'landed_locale']) as $v) {
        $referrers[(int) $v->converted_user_id] = trim((parse_url((string) $v->http_referer, PHP_URL_HOST) ?: 'direct').' '.$v->landed_locale);
    }
}
$gclid = $col('billing_trials', 'gclid') && $ids ? DB::table('billing_trials')->whereIn('user_id', $ids)->whereNotNull('gclid')->pluck('user_id')->flip()->all() : [];

$rows = [];
$summary = ['signups' => 0, 'used_demo' => 0, 'used_dashboard' => 0, 'made_a_voice_anywhere' => 0, 'never_made_a_voice' => 0, 'blocked' => 0];
$byGroup = [];
foreach ($users as $u) {
    $uid = (int) $u->id;
    $usedDemo = ($demo[$uid] ?? 0) > 0;
    $usedDash = ($gens[$uid] ?? 0) > 0;
    $any = $usedDemo || $usedDash || ($tts[$uid] ?? 0) > 0;
    $summary['signups']++;
    $summary['used_demo'] += $usedDemo ? 1 : 0;
    $summary['used_dashboard'] += $usedDash ? 1 : 0;
    $summary[$any ? 'made_a_voice_anywhere' : 'never_made_a_voice']++;
    $summary['blocked'] += $u->is_blocked ? 1 : 0;

    $provider = (string) ($u->auth_provider ?: ($u->google_id ? 'google' : ($u->phone_number ? 'phone' : 'email')));
    $s = $sessions[$uid] ?? [];
    $lastSeen = $s ? Carbon::createFromTimestamp(max(array_column($s, 'last_activity'))) : null;
    $latest = $s ? $s[array_key_last($s)] : null;
    $stay = $lastSeen ? $ago($u->created_at, $lastSeen) : '-';
    $bucket = match (true) {
        (bool) $u->is_blocked => 'blocked',
        $any => 'made a voice',
        ($errors[$uid] ?? 0) + ($blockedTexts[$uid] ?? 0) > 0 => 'tried but failed',
        ($texts[$uid] ?? 0) > 0 => 'saved text, no voice',
        default => 'did nothing',
    };
    foreach (['provider' => $provider, 'country' => strtoupper((string) ($u->country_code ?: '?')), 'device' => $latest ? explode(' ', $device($latest['ua']))[0] : 'no session', 'week' => Carbon::parse($u->created_at)->startOfWeek()->toDateString()] as $dim => $value) {
        $byGroup[$dim][$value][$bucket] = ($byGroup[$dim][$value][$bucket] ?? 0) + 1;
        $byGroup[$dim][$value]['total'] = ($byGroup[$dim][$value]['total'] ?? 0) + 1;
    }

    if ($any) {
        continue;
    }
    $rows[] = [
        'id' => $uid,
        'joined_utc' => Carbon::parse($u->created_at)->utc()->format('Y-m-d H:i'),
        'country' => $u->country_code,
        'via' => $provider,
        'email_domain' => $domain($u->email),
        'verified' => $u->email_verified_at || $u->phone_verified_at ? 'yes' : 'no',
        'result' => $bucket,
        'blocked' => $u->is_blocked ? mb_substr((string) ($u->blocked_reason_en ?: $u->blocked_reason), 0, 80) : null,
        'reg_ip' => $maskIp($u->registration_ip),
        'accounts_same_ip' => (int) ($sameIp[$u->registration_ip] ?? 0),
        'accounts_same_device' => $u->registration_device_id ? (int) ($sameDevice[$u->registration_device_id] ?? 0) : 0,
        'login_ip_changed' => $u->last_login_ip && $u->registration_ip && $u->last_login_ip !== $u->registration_ip,
        'sessions' => count($s),
        'stayed_after_signup' => $stay,
        'device' => $latest ? $device($latest['ua']) : '-',
        'last_page' => $latest ? $path($latest['last_url']) : '-',
        'errors' => (int) ($errors[$uid] ?? 0),
        'blocked_texts' => (int) ($blockedTexts[$uid] ?? 0),
        'saved_texts' => (int) ($texts[$uid] ?? 0),
        'api_key' => (bool) $u->api_key_hash,
        'trial' => (int) ($trials[$uid] ?? 0),
        'manual_payment' => (int) ($manual[$uid] ?? 0),
        'came_from' => $referrers[$uid] ?? (isset($gclid[$uid]) ? 'google ads' : null),
    ];
}
$out['summary'] = $summary;
$out['by'] = $byGroup;

// What the ones who never made a voice saw last, and on which device.
$pages = [];
$devicesSeen = [];
foreach ($rows as $r) {
    $p = preg_replace('#^/(en|ar)(?=/|$)#', '', strtok((string) $r['last_page'], '?')) ?: '/';
    $pages[$p] = ($pages[$p] ?? 0) + 1;
    $devicesSeen[$r['device']] = ($devicesSeen[$r['device']] ?? 0) + 1;
}
arsort($pages);
arsort($devicesSeen);
$stays = ['no session' => 0, 'under 1 min' => 0, '1-10 min' => 0, '10-60 min' => 0, 'over 1 hour' => 0];
foreach ($rows as $r) {
    $u = $users->firstWhere('id', $r['id']);
    $s = $sessions[$r['id']] ?? [];
    if (! $s) {
        $stays['no session']++;

        continue;
    }
    $secs = max(array_column($s, 'last_activity')) - Carbon::parse($u->created_at)->getTimestamp();
    $stays[match (true) { $secs < 60 => 'under 1 min', $secs < 600 => '1-10 min', $secs < 3600 => '10-60 min', default => 'over 1 hour' }]++;
}
$out['never_made_a_voice'] = ['last_page' => array_slice($pages, 0, 15, true), 'device' => array_slice($devicesSeen, 0, 12, true), 'time_on_site_after_signup' => $stays];

// Errors that new members hit, most common first.
if ($has('generation_errors') && $ids) {
    $out['errors_new_members_hit'] = DB::table('generation_errors')->whereIn('user_id', $ids)
        ->selectRaw('source, exception_class, substr(coalesce(user_message, message), 1, 140) as msg, count(*) as n, sum(occurrences) as times')
        ->groupBy('source', 'exception_class', 'msg')->orderByDesc('n')->limit(12)->get();
}
// Demo errors for everyone in the window (signed out too): is the demo itself failing?
if ($has('generation_errors')) {
    $out['all_generation_errors_in_window'] = DB::table('generation_errors')->where('created_at', '>=', $since)
        ->selectRaw('source, substr(coalesce(user_message, message), 1, 120) as msg, count(*) as n, sum(occurrences) as times, max(last_seen_at) as last_seen')
        ->groupBy('source', 'msg')->orderByDesc('n')->limit(12)->get();
}
// Daily: sign-ups vs demo and dashboard voices, to spot a day when it stopped working.
$daily = [];
foreach ($users as $u) {
    $d = Carbon::parse($u->created_at)->toDateString();
    $daily[$d]['signups'] = ($daily[$d]['signups'] ?? 0) + 1;
}
foreach (['demo_voice_generations' => 'demo_voices', 'audio_generations' => 'dashboard_voices'] as $table => $key) {
    if ($has($table)) {
        foreach (DB::table($table)->where('created_at', '>=', $since)->selectRaw('date(created_at) as d, count(*) as n')->groupBy('d')->get() as $r) {
            $daily[$r->d][$key] = (int) $r->n;
        }
    }
}
if ($has('audio_generations')) {
    foreach (DB::table('audio_generations')->where('created_at', '>=', $since)->where('status', 'failed')->selectRaw('date(created_at) as d, count(*) as n')->groupBy('d')->get() as $r) {
        $daily[$r->d]['dashboard_failed'] = (int) $r->n;
    }
}
krsort($daily);
$out['daily'] = $daily;
$out['never_made_a_voice_accounts'] = array_slice($rows, 0, 60);

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
