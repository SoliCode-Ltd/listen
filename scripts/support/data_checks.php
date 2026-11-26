<?php

/*
 * One-off, read-only (READ ONLY database session), run through the "Server deploy"
 * workflow's data-checks mode from this branch only: how AI apps have fared on the
 * MCP server. Counts only: no member, no address, no token, nothing written.
 */
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
    DB::statement('SET SESSION TRANSACTION READ ONLY');
}
echo 'Now (UTC): ', now()->utc()->toDateTimeString(), "\n";
echo '== Tables: api_idempotency_keys ', Schema::hasTable('api_idempotency_keys') ? 'yes' : 'MISSING',
    ', oauth_clients ', Schema::hasTable('oauth_clients') ? 'yes' : 'MISSING', " ==\n";

if (Schema::hasTable('oauth_clients')) {
    echo "== Apps that registered themselves (name, how many, first and last) ==\n";
    foreach (DB::table('oauth_clients')->selectRaw('name, count(*) as n, min(created_at) as first, max(created_at) as last')
        ->groupBy('name')->orderByDesc('n')->limit(30)->get() as $r) {
        echo '  ', json_encode($r, JSON_UNESCAPED_SLASHES), "\n";
    }
    if (Schema::hasTable('oauth_access_tokens')) {
        echo "== Sign-ins that finished (tokens), by app name ==\n";
        foreach (DB::table('oauth_access_tokens as t')->join('oauth_clients as c', 'c.id', '=', 't.client_id')
            ->selectRaw('c.name, count(*) as tokens, count(distinct t.user_id) as members, max(t.created_at) as last')
            ->groupBy('c.name')->orderByDesc('tokens')->limit(30)->get() as $r) {
            echo '  ', json_encode($r, JSON_UNESCAPED_SLASHES), "\n";
        }
    }
    $columns = Schema::getColumnListing('oauth_clients');
    echo '  oauth_clients columns: ', implode(', ', $columns), "\n";
    foreach (DB::table('oauth_clients')->orderByDesc('created_at')->limit(8)->get() as $c) {
        $c = (array) $c;
        unset($c['secret']);
        echo '  recent: ', json_encode(array_intersect_key($c, array_flip(['name', 'redirect_uris', 'redirect', 'grant_types', 'created_at', 'revoked'])), JSON_UNESCAPED_SLASHES), "\n";
    }
}

if (Schema::hasTable('api_request_logs')) {
    $since = now()->subDays(30);
    $mcp = fn () => DB::table('api_request_logs')->where('created_at', '>=', $since)->where('path', 'like', '%mcp%');
    echo '== MCP requests, last 30 days: ', $mcp()->count(), " ==\n";
    echo "  by status and error code:\n";
    foreach ($mcp()->selectRaw('status, error_code, count(*) as n')->groupBy('status', 'error_code')->orderByDesc('n')->limit(30)->get() as $r) {
        echo '    ', json_encode($r), "\n";
    }
    echo "  by app (start of its user agent) and status:\n";
    foreach ($mcp()->selectRaw('substr(user_agent, 1, 40) as agent, status, count(*) as n, max(created_at) as last')->groupBy('agent', 'status')->orderByDesc('n')->limit(40)->get() as $r) {
        echo '    ', json_encode($r, JSON_UNESCAPED_SLASHES), "\n";
    }
    echo "  by endpoint (tool) and status:\n";
    foreach ($mcp()->selectRaw('endpoint, status, error_code, count(*) as n')->groupBy('endpoint', 'status', 'error_code')->orderByDesc('n')->limit(40)->get() as $r) {
        echo '    ', json_encode($r), "\n";
    }
    // From the published addresses AI apps call from (counts only).
    $ranges = [['160.79.104.0', 21], ['153.61.208.0', 20]];
    $in = 0; $out = 0;
    foreach ($mcp()->whereNotNull('ip')->selectRaw('ip, count(*) as n')->groupBy('ip')->get() as $r) {
        $hit = false;
        $ip = ip2long((string) $r->ip);
        foreach ($ranges as [$net, $bits]) {
            if ($ip !== false && ($ip >> (32 - $bits)) === (ip2long($net) >> (32 - $bits))) { $hit = true; }
        }
        $hit ? $in += $r->n : $out += $r->n;
    }
    echo "  from the published cloud addresses: {$in}; from elsewhere: {$out}\n";
}
