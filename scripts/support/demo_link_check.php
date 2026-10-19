<?php

/*
 * For the "Server deploy" workflow's demo-links mode, on either site: why a
 * member's free demo was or was not shared with other accounts. For one member
 * (MEMBER: an email or a member id) it reads, in a READ ONLY database session,
 * how they signed up, their demo use, and every other account that shares a
 * browser (device cookie), a demo fingerprint or a network address with them,
 * with which of those it shares and whether the site's linking rules count it
 * (HomeController::demoLinkedUserIds: same browser; same sign-up address
 * within 72 hours; same fingerprint within 72 hours). Addresses, cookies and
 * fingerprints are shown only as short hashes, to tell same from different.
 * Prints member ids, never another member's email, and writes nothing.
 *
 *   ssh user@host 'cd ~/public_html && MEMBER=... php' < scripts/support/demo_link_check.php
 */

use App\Models\User;
use Illuminate\Support\Facades\DB;

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
    DB::statement('SET SESSION TRANSACTION READ ONLY');
}

$windowHours = 72;
$short = fn (?string $v): string => $v === null || $v === '' ? '-' : substr(hash('sha256', $v), 0, 8);
$how = fn (User $u): string => $u->google_id ? 'Google' : (($u->phone_verified_at || $u->auth_provider === 'phone' || $u->firebase_uid) ? 'phone' : 'email and password');
$demoChars = fn (int $id): int => (int) DB::table('demo_voice_generations')->where('user_id', $id)->sum('billable_chars');
echo 'Now (UTC): ', now()->utc()->toDateTimeString(), "\n";

$member = trim((string) getenv('MEMBER'));
$t = $member === '' ? null : (ctype_digit($member) ? User::query()->find((int) $member) : User::query()->where('email', $member)->first());
if (! $t) {
    echo "No member with that email or id.\n";
    exit(0);
}

$gens = DB::table('demo_voice_generations')->where('user_id', $t->id)->orderBy('id')->get(['id', 'primary_identity_hash', 'billable_chars', 'created_at']);
$hashes = $gens->pluck('primary_identity_hash')->filter()->unique()->values()->all();
echo "== Member {$t->id} ==\n";
echo '  signed up ', $t->created_at->utc()->toDateTimeString(), ' UTC with ', $how($t), ', email confirmed: ', $t->email_verified_at ? $t->email_verified_at->utc()->format('Y-m-d H:i') : 'no', "\n";
echo '  browser at sign-up: ', $short($t->registration_device_id), ', browser last seen: ', $short($t->last_seen_device_id),
    ', sign-up address: ', $short($t->registration_ip), ', last sign-in address: ', $short($t->last_login_ip), "\n";
$kind = fn (?string $ip): string => $ip === null || $ip === '' ? '-' : (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 'IPv6' : 'IPv4');
$net = function (?string $ip): ?string {
    if ($ip === null || $ip === '' || ($bin = @inet_pton($ip)) === false) {
        return null;
    }
    // The household or phone network: an IPv6 /64, an IPv4 /24.
    return strlen($bin) === 16 ? substr($bin, 0, 8) : substr($bin, 0, 3);
};
echo '  addresses are ', $kind($t->registration_ip), ' / ', $kind($t->last_login_ip), "\n";
echo '  demo voices: ', $gens->count(), ', demo characters used: ', $gens->sum('billable_chars'), "\n";
foreach ($gens as $g) {
    echo '    #', $g->id, ' ', substr((string) $g->created_at, 0, 16), ' ', $g->billable_chars, ' chars, fingerprint ', substr((string) $g->primary_identity_hash, 0, 3), $short((string) $g->primary_identity_hash), "\n";
}

$devices = array_values(array_filter([(string) $t->registration_device_id, (string) $t->last_seen_device_id]));
$ips = array_values(array_unique(array_filter([(string) $t->registration_ip, (string) $t->last_login_ip])));
$candidates = collect();
if ($devices !== []) {
    $candidates = $candidates->merge(User::query()->where(fn ($q) => $q->whereIn('registration_device_id', $devices)->orWhereIn('last_seen_device_id', $devices))->get());
}
if ($ips !== []) {
    $candidates = $candidates->merge(User::query()->where(fn ($q) => $q->whereIn('registration_ip', $ips)->orWhereIn('last_login_ip', $ips))->limit(200)->get());
}
// Accounts on the same network (IPv6 /64, IPv4 /24) made or signed in within 30 days.
$nets = array_values(array_filter(array_map($net, $ips)));
if ($nets !== []) {
    $near = User::query()->where('id', '<>', $t->id)
        ->where(fn ($q) => $q->whereBetween('created_at', [$t->created_at->copy()->subDays(30), $t->created_at->copy()->addDays(30)])->orWhere('updated_at', '>=', now()->subDays(30)))
        ->limit(20000)->get(['id', 'registration_ip', 'last_login_ip']);
    $sameNet = $near->filter(fn ($u) => array_intersect(array_filter([$net($u->registration_ip), $net($u->last_login_ip)]), $nets) !== [])->pluck('id');
    $candidates = $candidates->merge(User::query()->whereIn('id', $sameNet)->get());
}
if ($hashes !== []) {
    $ids = DB::table('demo_voice_generations')->whereIn('primary_identity_hash', $hashes)->whereNotNull('user_id')->distinct()->pluck('user_id');
    $candidates = $candidates->merge(User::query()->whereIn('id', $ids)->get());
}
$candidates = $candidates->unique('id')->reject(fn (User $u) => $u->id === $t->id)->sortBy('id')->values();

echo "== Other accounts sharing a browser, a demo fingerprint or an address with them (", $candidates->count(), ") ==\n";
$within = fn (User $u): bool => abs($u->created_at->diffInHours($t->created_at)) <= $windowHours;
$counted = [$t->id => true];
foreach ($candidates as $u) {
    $shares = [];
    $uDevices = array_filter([(string) $u->registration_device_id, (string) $u->last_seen_device_id]);
    if (array_intersect($uDevices, $devices) !== []) {
        $shares[] = 'browser';
    }
    if ($u->registration_ip && $u->registration_ip === $t->registration_ip) {
        $shares[] = 'sign-up address';
    } elseif (array_intersect(array_filter([(string) $u->registration_ip, (string) $u->last_login_ip]), $ips) !== []) {
        $shares[] = 'an address (not both sign-ups)';
    } elseif (array_intersect(array_filter([$net($u->registration_ip), $net($u->last_login_ip)]), $nets) !== []) {
        $shares[] = 'the same network ('.$kind($u->registration_ip).'), not the same address';
    }
    $uHashes = DB::table('demo_voice_generations')->where('user_id', $u->id)->pluck('primary_identity_hash')->filter()->unique()->all();
    $sharedFp = array_values(array_intersect($uHashes, $hashes));
    if ($sharedFp !== []) {
        $shares[] = 'demo fingerprint ('.implode(', ', array_map(fn ($h) => substr($h, 0, 3), $sharedFp)).')';
    }
    $rules = [];
    if (in_array('browser', $shares, true)) {
        $rules[] = 'same browser';
    }
    if (in_array('sign-up address', $shares, true) && $within($u)) {
        $rules[] = 'sign-up address within 72 h';
    }
    foreach ($sharedFp as $h) {
        if (str_starts_with($h, 'dc:') || $within($u)) {
            $rules[] = 'fingerprint'.(str_starts_with($h, 'dc:') ? '' : ' within 72 h');
            break;
        }
    }
    if ($rules !== []) {
        $counted[$u->id] = true;
    }
    printf("  member %d: signed up %s UTC (%+.0f h) with %s, demo chars %d, pays or paid: %s\n      shares: %s\n      counted by the site's rules: %s\n",
        $u->id, $u->created_at->utc()->format('Y-m-d H:i'), $u->created_at->diffInHours($t->created_at, false) * -1, $how($u), $demoChars($u->id),
        App\Support\PaidMember::is($u) ? 'yes' : 'no', implode('; ', $shares) ?: '-', $rules === [] ? 'NO' : implode(', ', $rules));
}
$total = array_sum(array_map($demoChars, array_keys($counted)));
echo "== The demo the site sees for member {$t->id} (without the current browser cookie) ==\n";
echo '  linked accounts counted: ', implode(', ', array_keys($counted)), "; demo characters used among them: $total\n";
