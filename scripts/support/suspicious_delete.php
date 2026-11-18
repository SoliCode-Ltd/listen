<?php

/*
 * Deletes the accounts the owner asked to remove (MEMBER_IDS: ids, comma
 * separated), as Admin → Suspicious Users → Delete selected does: admins and
 * members who pay or have paid are kept (PaidMember), and so is anyone the
 * deletion refuses (a card plan still charged, an open trial). Each account goes
 * through UserDeletionService::delete(withDemoHistory: true), which first keeps
 * what it used of the free demo (DemoSamePerson::leaveBehind), so the same
 * person's other or later accounts do not get it back. An account with a cloned
 * voice is kept too (its removal calls the voice provider): it is listed instead.
 *
 * Without APPLY=1 nothing is changed: it lists each account, what it has, and
 * whether it would be deleted. Expects EXPECTED_COUNT ids, so a wrong list stops
 * it. No email is sent (mail goes to the array driver for the run). Prints
 * member ids, masked emails (first letters and the domain), dates and counts.
 *
 *   ssh user@host 'cd ~/public_html && MEMBER_IDS=1,2 EXPECTED_COUNT=2 APPLY=0 php' < scripts/support/suspicious_delete.php
 */

use App\Models\User;
use App\Services\DemoSamePerson;
use App\Services\DemoSuspects;
use App\Services\UserDeletionService;
use App\Support\PaidMember;
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

$apply = getenv('APPLY') === '1';
$ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) getenv('MEMBER_IDS'))))));
$expected = (int) getenv('EXPECTED_COUNT');
if ($ids === [] || count($ids) !== $expected) {
    fwrite(STDERR, 'MEMBER_IDS has '.count($ids)." ids, EXPECTED_COUNT says {$expected}: nothing done.\n");
    exit(1);
}

// No member is emailed by this run, whatever the deletion does.
config(['mail.default' => 'array']);
if (! $apply && in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
    DB::statement('SET SESSION TRANSACTION READ ONLY');
}

$site = (string) config('app.url');
echo ($apply ? 'DELETING' : 'Dry run (nothing changed)').' on '.parse_url($site, PHP_URL_HOST).': '.count($ids)." accounts asked for\n";
$count = fn (string $table, int $id, string $column = 'user_id'): int => Schema::hasTable($table) ? (int) DB::table($table)->where($column, $id)->count() : 0;
$date = fn ($v): string => $v ? \Illuminate\Support\Carbon::parse($v)->format('Y-m-d') : '-';
$mask = static function (?string $email): string {
    $email = (string) $email;
    $at = strpos($email, '@');

    return $at === false ? '***' : substr($email, 0, min(3, $at)).'***'.substr($email, $at);
};

$deletion = app(UserDeletionService::class);
$samePerson = app(DemoSamePerson::class);
$done = 0;
$kept = [];
$missing = [];
printf("  %-6s %-28s %-10s %-10s %6s %6s %6s %6s %6s  %-s\n", 'member', 'email', 'made', 'last voice', 'demo', 'studio', 'agents', 'keys', 'clones', 'what happens');
foreach ($ids as $id) {
    $user = User::query()->find($id);
    if (! $user) {
        $missing[] = $id;
        printf("  %-6d %s\n", $id, 'not found (already gone)');

        continue;
    }
    $demo = (int) DB::table('demo_voice_generations')->where('user_id', $id)->sum('billable_chars');
    $studio = $count('audio_generations', $id);
    $agents = $count('agents', $id);
    $keys = $count('api_keys', $id);
    $clones = Schema::hasTable('cloned_voices') ? (int) DB::table('cloned_voices')->where('user_id', $id)->count() : 0;
    $lastVoice = max((string) DB::table('demo_voice_generations')->where('user_id', $id)->max('created_at'), (string) DB::table('audio_generations')->where('user_id', $id)->max('created_at'));

    $why = null;
    if ($user->is_admin) {
        $why = 'kept: an admin';
    } elseif (PaidMember::is($user)) {
        $why = 'kept: pays or has paid';
    } elseif (($refusal = $deletion->refusal($user)) !== null) {
        $why = 'kept: '.$refusal;
    } elseif ($clones > 0) {
        $why = 'kept: has a cloned voice';
    }

    $outcome = $why ?? ($apply ? 'deleted' : 'would be deleted');
    if ($why === null && $apply) {
        $refused = $deletion->delete($user, null, withDemoHistory: true);
        if ($refused !== null) {
            $outcome = 'kept: '.$refused;
            $why = $outcome;
        } else {
            $done++;
        }
    }
    if ($why !== null) {
        $kept[] = $id;
    }
    printf("  %-6d %-28s %-10s %-10s %6d %6d %6d %6d %6d  %s\n", $id, $mask($user->email), $date($user->created_at), $date($lastVoice ?: null),
        $demo, $studio, $agents, $keys, $clones, $outcome);
}
if ($apply) {
    app(DemoSuspects::class)->forget();
}

echo "\n";
echo '  '.($apply ? 'Deleted' : 'Would be deleted').': '.($apply ? $done : count($ids) - count($kept) - count($missing))."\n";
echo '  Kept: '.count($kept).($kept === [] ? '' : ' ('.implode(',', $kept).')')."\n";
echo '  Not found: '.count($missing).($missing === [] ? '' : ' ('.implode(',', $missing).')')."\n";
