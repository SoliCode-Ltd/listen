<?php

/*
 * Read-only: why a member's account (or their free trial) is blocked.
 *
 * The member is found by the SHA-256 of their lower-cased email
 * (ACCOUNT_EMAIL_SHA256), so no address has to be stored in the repository.
 * Other members' emails are masked, IPs and device cookies are shortened.
 * On MySQL the session is READ ONLY before any query runs.
 *
 *   ssh user@host 'cd ~/public_html && ACCOUNT_EMAIL_SHA256=<hash> php' < scripts/support/account_block_inspect.php
 */

use App\Models\BillingTrial;
use App\Models\BlockedGeneration;
use App\Models\Subscription;
use App\Models\User;
use App\Services\MultiAccountEnforcementService;
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

$mask = static function (?string $email): string {
    $email = (string) $email;
    if (! str_contains($email, '@')) {
        return $email === '' ? '—' : '***';
    }
    [$local, $domain] = explode('@', $email, 2);

    return mb_substr($local, 0, 2).'***@'.$domain;
};
$short = static fn (?string $value): string => $value ? substr($value, 0, 6).'…' : '—';
$maskIp = static function (?string $ip): string {
    if (! $ip) {
        return '—';
    }
    $parts = explode('.', $ip);

    return count($parts) === 4 ? "{$parts[0]}.{$parts[1]}.x.x" : substr($ip, 0, 9).'…';
};
$when = static fn ($date): string => $date ? (string) $date : '—';
$line = static function (string $label, $value): void {
    echo str_pad($label, 34).(is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value).PHP_EOL;
};

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
    echo "No account with that email on this server.\n";
    exit(0);
}

echo "== Account ==\n";
$line('user id', $user->id);
$line('email', $mask($user->email));
$line('created', $when($user->created_at));
$line('signed up with', $user->auth_provider ?: 'email');
$line('email verified', $when($user->email_verified_at));
$line('country', $user->country_code ?? '—');

echo "\n== Block ==\n";
$line('blocked', (bool) $user->is_blocked);
$line('blocked at', $when($user->blocked_at));
$line('reason (en)', $user->blocked_reason_en ?? $user->blocked_reason ?? '—');
$line('reason (ar)', $user->blocked_reason_ar ?? '—');
$blockedBy = $user->blocked_by ? User::query()->find($user->blocked_by) : null;
$line('blocked by', $user->blocked_by ? 'admin '.$mask($blockedBy?->email).' (#'.$user->blocked_by.')' : ($user->is_blocked ? 'automatic (no admin)' : '—'));
$line('multi-account whitelisted', (bool) $user->multi_account_whitelisted);

$multi = app(MultiAccountEnforcementService::class);
$reasonEn = $user->blocked_reason_en ?? $user->blocked_reason ?? '';
$verdict = match (true) {
    ! $user->is_blocked => 'not blocked',
    (bool) $user->blocked_by => 'blocked by an admin by hand',
    $reasonEn === $multi->blockReasonEn() => 'AUTOMATIC: more accounts on the same browser than allowed ('.$multi->maxAccountsPerIp().')',
    str_contains(strtolower($reasonEn), 'abusive') || str_contains(strtolower($reasonEn), 'prohibited') => 'AUTOMATIC: repeated blocked words in generation text (2+ attempts)',
    default => 'automatic, reason text: '.$reasonEn,
};
$line('verdict', $verdict);

echo "\n== Blocked wording attempts ==\n";
$attempts = BlockedGeneration::query()->where('user_id', $user->id)->latest('id')->get();
$line('count', $attempts->count());
foreach ($attempts->take(5) as $attempt) {
    echo '  '.$when($attempt->created_at).'  ['.$attempt->matched_language.'] matched "'.$attempt->matched_term.'"  on '.$attempt->route_name.PHP_EOL;
}

echo "\n== Same browser / network ==\n";
$deviceIds = array_values(array_unique(array_filter([$user->registration_device_id, $user->last_seen_device_id])));
$ips = array_values(array_unique(array_filter([$user->registration_ip, $user->last_login_ip])));
$line('device cookies', implode(', ', array_map($short, $deviceIds)) ?: '—');
$line('IPs', implode(', ', array_map($maskIp, $ips)) ?: '—');

$linked = User::query()
    ->where('id', '!=', $user->id)
    ->where(function ($q) use ($deviceIds, $ips) {
        if ($deviceIds !== []) {
            $q->whereIn('registration_device_id', $deviceIds)->orWhereIn('last_seen_device_id', $deviceIds);
        }
        if ($ips !== []) {
            $q->orWhereIn('registration_ip', $ips)->orWhereIn('last_login_ip', $ips);
        }
    })
    ->orderBy('id')
    ->limit(30)
    ->get();

$sameDevice = $linked->filter(fn (User $u) => array_intersect($deviceIds, array_filter([$u->registration_device_id, $u->last_seen_device_id])) !== []);
$line('accounts on the same browser', $sameDevice->count().' other(s) + this one, limit '.$multi->maxAccountsPerIp());
$line('accounts on the same IP only', $linked->count() - $sameDevice->count());
foreach ($linked as $other) {
    $how = $sameDevice->contains('id', $other->id) ? 'same browser' : 'same IP';
    echo '  #'.$other->id.'  '.$mask($other->email).'  created '.$when($other->created_at).'  via '.($other->auth_provider ?: 'email')
        .'  '.$how.($other->is_blocked ? '  BLOCKED' : '').($other->is_admin ? '  ADMIN' : '').PHP_EOL;
}

echo "\n== Plan and trial ==\n";
$subscription = Subscription::query()->with('plan')->where('user_id', $user->id)->latest('id')->first();
$line('latest subscription', $subscription ? ($subscription->plan?->slug ?? '?').' / '.$subscription->status.' (ends '.$when($subscription->current_period_end ?? null).')' : '—');
if (Schema::hasTable('billing_trials')) {
    foreach (BillingTrial::query()->with('plan')->where('user_id', $user->id)->latest('id')->get() as $trial) {
        echo '  trial #'.$trial->id.'  '.$trial->status.'  plan '.($trial->plan?->slug ?? '?')
            .'  started '.$when($trial->started_at).'  ends '.$when($trial->trial_ends_at)
            .'  card '.($trial->card_brand ?? '?').' •'.($trial->card_last4 ?? '????')
            .($trial->blocked_reason ? '  blocked_reason='.$trial->blocked_reason : '').PHP_EOL;

        if ($trial->card_fingerprint) {
            $others = BillingTrial::query()
                ->where('card_fingerprint', $trial->card_fingerprint)
                ->where('user_id', '!=', $user->id)
                ->count();
            echo '     same card used by other accounts\' trials: '.$others.PHP_EOL;
        }
    }
}

echo "\n== Log lines about this account ==\n";
$needles = ['"user_id":'.$user->id.',', '"user_id":'.$user->id.'}'];
$found = 0;
foreach (array_slice(glob($root.'/storage/logs/*.log') ?: [], -14) as $file) {
    $handle = @fopen($file, 'r');
    if (! $handle) {
        continue;
    }
    while (($row = fgets($handle)) !== false) {
        if (! preg_match('/block|trial|multi-account/i', $row)) {
            continue;
        }
        foreach ($needles as $needle) {
            if (str_contains($row, $needle)) {
                // Strip emails from the log line before printing.
                $row = preg_replace_callback('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', fn ($m) => $mask($m[0]), $row);
                echo '  '.basename($file).': '.mb_substr(trim($row), 0, 400).PHP_EOL;
                $found++;
                break;
            }
        }
        if ($found >= 20) {
            break 2;
        }
    }
    fclose($handle);
}
if ($found === 0) {
    echo "  none in the last log files\n";
}
