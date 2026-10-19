<?php

/*
 * For the "Server deploy" workflow's email-check mode, on either site: whether
 * the site's email goes out, and how the email confirmation is going. Reads,
 * in a READ ONLY database session: the mail setup in use (never a password),
 * the sign-ups since confirmation links began (by how they signed up, and how
 * many confirmed), the app's log lines about links that failed to send, and,
 * for one member (MEMBER: an email or a member id), how they signed up and
 * where they stand. Prints member ids, never another member's address, and
 * writes nothing.
 *
 *   ssh user@host 'cd ~/public_html && MEMBER=... php' < scripts/support/email_check.php
 */
use App\Models\User;
use App\Support\EmailVerification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
    DB::statement('SET SESSION TRANSACTION READ ONLY');
}

$mask = fn (string $s): string => preg_replace('/[A-Za-z0-9._%+-]+@([A-Za-z0-9.-]+\.[A-Za-z]{2,})/', '…@$1', $s) ?? $s;
$how = function (User $u): string {
    if ($u->google_id) {
        return 'Google';
    }
    if ($u->phone_verified_at || $u->auth_provider === 'phone' || $u->firebase_uid) {
        return 'phone';
    }

    return 'email and password';
};
echo 'Now (UTC): ', now()->utc()->toDateTimeString(), "\n";

echo "== Mail setup in use ==\n";
$mailer = (string) config('mail.default');
$transport = (string) config("mail.mailers.$mailer.transport", $mailer);
echo '  mailer: ', $mailer, ' (', $transport, ')', in_array($transport, ['log', 'array'], true) ? '  <-- emails are NOT delivered, only written to the log' : '', "\n";
if ($transport === 'smtp') {
    echo '  host set: ', config("mail.mailers.$mailer.host") ? 'yes' : 'NO', ', port: ', (string) config("mail.mailers.$mailer.port"),
        ', encryption: ', (string) (config("mail.mailers.$mailer.encryption") ?? config("mail.mailers.$mailer.scheme") ?? 'none'),
        ', username set: ', config("mail.mailers.$mailer.username") ? 'yes' : 'no', ', password set: ', config("mail.mailers.$mailer.password") ? 'yes' : 'no', "\n";
}
$from = (string) config('mail.from.address');
echo '  sent from: an address at ', $from !== '' && str_contains($from, '@') ? substr($from, strpos($from, '@') + 1) : '(not set)', ', name "', (string) config('mail.from.name'), "\"\n";
echo '  free use waits for a confirmed email: ', config('listen.demo.require_verified_email', true) ? 'yes' : 'NO (switched off)',
    ' (LISTEN_REQUIRE_VERIFIED_EMAIL ', preg_match('/^LISTEN_REQUIRE_VERIFIED_EMAIL=/m', (string) @file_get_contents(base_path('.env'))) ? 'is set in .env' : 'is not in .env: the default, on', ")\n";

$since = Illuminate\Support\Carbon::createFromTimestampUTC((int) @filemtime(app_path('Support/EmailVerification.php')));
echo "== Sign-ups since confirmation links began (", $since->toDateTimeString(), " UTC, when this server got them) ==\n";
$users = User::query()->where('created_at', '>=', $since)->orderBy('id')->get();
$groups = $users->groupBy($how);
foreach (['email and password', 'Google', 'phone'] as $kind) {
    $g = $groups->get($kind, collect());
    if ($kind !== 'email and password') {
        echo '  ', str_pad($kind, 20), $g->count(), " (confirmed when made)\n";
        continue;
    }
    $confirmed = $g->filter(fn (User $u) => $u->email_verified_at !== null);
    $waits = $confirmed->map(fn (User $u) => max(0, (int) round(abs($u->created_at->diffInMinutes($u->email_verified_at)))))->sort()->values();
    $median = $waits->isEmpty() ? null : $waits[intdiv($waits->count(), 2)];
    echo '  ', str_pad($kind, 20), $g->count(), ', confirmed: ', $confirmed->count(), $median === null ? '' : ' (half of them within '.$median.' min)',
        ', still waiting: ', $g->count() - $confirmed->count(), "\n";
    foreach ($g->reject(fn (User $u) => $u->email_verified_at !== null) as $u) {
        echo '    member ', $u->id, ': signed up ', $u->created_at->utc()->format('m-d H:i'), ', link sent and still good: ',
            Cache::has('verify-email:sent:'.$u->id) ? 'yes' : 'no', "\n";
    }
}

echo "== The app's log lines about confirmation links and mail since then ==\n";
$failed = [];
$mailErrors = 0;
foreach (glob(storage_path('logs/*.log')) ?: [] as $file) {
    if ((int) @filemtime($file) < $since->getTimestamp()) {
        continue;
    }
    $handle = @fopen($file, 'r');
    while ($handle && ($line = fgets($handle)) !== false) {
        if (! preg_match('/^\[(\d{4}-\d\d-\d\d[ T]\d\d:\d\d:\d\d)/', $line, $m) || strtotime($m[1].' UTC') < $since->getTimestamp()) {
            continue;
        }
        if (str_contains($line, 'The email confirmation link was not sent.')) {
            preg_match('/"error":"((?:[^"\\\\]|\\\\.)*)"/', $line, $e);
            $key = substr($mask(stripcslashes($e[1] ?? '?')), 0, 220);
            $failed[$key] = ($failed[$key] ?? 0) + 1;
        } elseif (preg_match('/TransportException|Connection could not be established|Expected response code|stream_socket_client|Failed to authenticate|Unable to connect/i', $line)) {
            $mailErrors++;
        }
    }
    if ($handle) {
        fclose($handle);
    }
}
echo '  links that failed to send: ', array_sum($failed), "\n";
foreach ($failed as $error => $n) {
    echo '    ', $n, ' x ', $error, "\n";
}
echo '  other mail errors: ', $mailErrors, "\n";

$member = trim((string) getenv('MEMBER'));
if ($member !== '') {
    echo "== The member asked about ==\n";
    $u = ctype_digit($member) ? User::query()->find((int) $member) : User::query()->where('email', $member)->first();
    if (! $u) {
        echo "  no member with that email or id\n";
    } else {
        echo '  member ', $u->id, ': signed up ', $u->created_at->utc()->toDateTimeString(), ' UTC with ', $how($u), "\n";
        echo '  email confirmed: ', $u->email_verified_at ? $u->email_verified_at->utc()->toDateTimeString().' UTC' : 'not yet', "\n";
        echo '  pays or has paid: ', App\Support\PaidMember::is($u) ? 'yes' : 'no', "\n";
        echo '  waiting for a confirmed email before free use: ', EmailVerification::requiredForFreeUse($u) ? 'YES' : 'no', "\n";
        echo '  a link was sent in the last ', EmailVerification::LINK_DAYS, ' days: ', Cache::has('verify-email:sent:'.$u->id) ? 'yes' : 'no', "\n";
        echo '  demo voices made: ', DB::table('demo_voice_generations')->where('user_id', $u->id)->count(),
            ', studio voices made: ', DB::table('audio_generations')->where('user_id', $u->id)->count(), "\n";
        echo '  blocked: ', $u->is_blocked ? 'yes' : 'no', "\n";
    }
}
