<?php

/*
 * Read-only: everything one caller said to an agent, across all their calls on this server.
 *
 * Starts from one call (CALL_ID, the cnv_... id in the admin's address) and gathers the other
 * calls by the same caller: the same signed-in member where the website said who was calling,
 * and the same visitor otherwise. For each call: when, how long, where from, how it ended, its
 * summary, the details the caller left, and the conversation turn by turn.
 *
 * This prints what a caller said, so it runs only when the owner asks to read a caller's calls,
 * and the run's log is deleted once it has been read. Emails and phone numbers are masked. On
 * MySQL the session is READ ONLY before any query. Nothing is written.
 *
 *   ssh user@host 'cd ~/public_html && CALL_ID=cnv_... php' < scripts/support/agent_caller_calls_inspect.php
 */

use App\Models\AgentConversation;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

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

$id = trim((string) getenv('CALL_ID'));
if (! preg_match('/^cnv_[a-z0-9]{10,40}$/', $id)) {
    fwrite(STDERR, "CALL_ID must be a call's cnv_... id.\n");
    exit(1);
}

$maskEmail = static function (?string $email): string {
    $email = (string) $email;
    $at = strpos($email, '@');

    return $at === false ? ($email === '' ? '-' : '***') : substr($email, 0, min(3, $at)).'***'.substr($email, $at);
};
$maskPhone = static fn (?string $phone): string => ($phone = (string) $phone) === '' ? '-' : str_repeat('*', max(0, strlen($phone) - 3)).substr($phone, -3);

$first = AgentConversation::query()->where('public_id', $id)->first();
if (! $first) {
    echo "No call with that id on this server.\n";
    exit(0);
}

$email = strtolower(trim((string) ($first->caller['email'] ?? '')));
$calls = AgentConversation::query()
    ->where(function ($q) use ($first, $email): void {
        $q->where('id', $first->id);
        if ($email !== '') {
            $q->orWhere('caller->email', $email);
        }
        if (filled($first->visitor_hash)) {
            $q->orWhere('visitor_hash', $first->visitor_hash);
        }
    })
    ->orderBy('started_at')
    ->limit(60)
    ->get();

$out = [
    'asked_for' => $id,
    'caller' => [
        'name' => $first->caller['name'] ?? null,
        'email_masked' => $maskEmail($first->caller['email'] ?? null),
        'matched_by' => array_values(array_filter([$email !== '' ? 'signed-in member' : null, filled($first->visitor_hash) ? 'same visitor' : null])),
    ],
    'calls' => [],
];
foreach ($calls as $call) {
    $lead = (array) ($call->lead ?? []);
    foreach ($lead as $key => $value) {
        if (! is_string($value)) {
            continue;
        }
        if (str_contains(strtolower((string) $key), 'email')) {
            $lead[$key] = $maskEmail($value);
        } elseif (str_contains(strtolower((string) $key), 'phone')) {
            $lead[$key] = $maskPhone($value);
        }
    }
    $out['calls'][] = [
        'id' => $call->public_id,
        'agent' => optional($call->agent)->name,
        'channel' => $call->channel,
        'started_utc' => optional($call->started_at)->toDateTimeString(),
        'seconds' => (int) $call->duration_seconds,
        'status' => $call->status,
        'end_reason' => $call->end_reason,
        'country' => $call->country,
        'page' => $call->page_url,
        'caller_name' => $call->caller['name'] ?? null,
        'same_member' => $email !== '' && strtolower((string) ($call->caller['email'] ?? '')) === $email,
        'summary' => $call->summary,
        'outcome' => $call->outcome,
        'lead' => $lead,
        'turns' => array_map(
            static fn ($turn): string => (($turn['role'] ?? '') === 'agent' ? 'AGENT: ' : 'CALLER: ').(string) ($turn['text'] ?? ''),
            array_values(array_filter((array) ($call->transcript ?? []), 'is_array')),
        ),
    ];
}

echo "CALLER_CALLS_JSON_BEGIN\n";
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
echo "CALLER_CALLS_JSON_END\n";
