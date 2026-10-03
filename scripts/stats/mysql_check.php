<?php

/*
 * Read-only MySQL check for the member-country backfill and the business
 * report queries. Prints counts only (no IPs, no emails). The session is
 * READ ONLY, so nothing can be written.
 *
 *   ssh host 'cd app && php' < scripts/stats/mysql_check.php
 */

use App\Services\Analytics\BusinessStatsService;
use Illuminate\Contracts\Console\Kernel;
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

$out = [
    'driver' => DB::connection()->getDriverName(),
    'server_version' => DB::connection()->getDriverName() === 'sqlite' ? null : (DB::selectOne('select version() as v')->v ?? null),
    'sql_mode' => DB::connection()->getDriverName() === 'sqlite' ? null : (DB::selectOne('select @@SESSION.sql_mode as m')->m ?? null),
    'has_country_code' => Schema::hasColumn('users', 'country_code'),
];

// 1. The backfill's IP query, exactly as the fixed command builds it.
try {
    $memberIps = DB::table('users')
        ->when($out['has_country_code'], fn ($q) => $q->whereNull('country_code'))
        ->selectRaw("coalesce(nullif(registration_ip, ''), last_login_ip) as ip");
    $ips = DB::query()
        ->fromSub($memberIps, 'member_ips')
        ->whereNotNull('ip')
        ->where('ip', '!=', '')
        ->selectRaw('ip, count(*) as members')
        ->groupBy('ip')
        ->orderByDesc('members')
        ->get();
    $out['backfill_query'] = [
        'ok' => true,
        'distinct_ips' => $ips->count(),
        'members_covered' => (int) $ips->sum('members'),
        'largest_group' => (int) ($ips->first()->members ?? 0),
    ];
} catch (Throwable $e) {
    $out['backfill_query'] = ['ok' => false, 'error' => mb_substr($e->getMessage(), 0, 400)];
}

// 2. The full report snapshot, as deployed on this server.
try {
    $snapshot = app(BusinessStatsService::class)->build(12);
    $audience = $snapshot['audience'] ?? null;
    $out['report'] = [
        'section_errors' => array_map(fn ($m) => mb_substr((string) $m, 0, 300), $snapshot['errors'] ?? []),
        'has_audience_section' => is_array($audience),
        'audience_members' => $audience['members'] ?? null,
        'audience_with_country' => $audience['with_country'] ?? null,
        'audience_country_rows' => is_array($audience) ? count($audience['countries'] ?? []) : null,
        'email_types' => $audience['email_types'] ?? null,
        'dialects' => $audience['dialects'] ?? null,
    ];
} catch (Throwable $e) {
    $out['report'] = ['ok' => false, 'error' => mb_substr($e->getMessage(), 0, 400)];
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
