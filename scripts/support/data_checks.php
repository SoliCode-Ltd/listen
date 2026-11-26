<?php

/*
 * One-off, read-only (READ ONLY database session), run through the "Server deploy"
 * workflow's data-checks mode from this branch only: whether "Sign in with Listen"
 * can finish on this server. Counts and yes/no only: no secret, no token, nothing written.
 */
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
    DB::statement('SET SESSION TRANSACTION READ ONLY');
}
echo 'Now (UTC): ', now()->utc()->toDateTimeString(), ' app url: ', config('app.url'), "\n";

echo "== Sign-in keys ==\n";
foreach (['oauth-private.key', 'oauth-public.key'] as $f) {
    $path = storage_path($f);
    echo '  ', $f, ': ', is_file($path) ? 'present, '.filesize($path).' bytes, mode '.substr(sprintf('%o', fileperms($path)), -3).', readable '.(is_readable($path) ? 'yes' : 'NO') : 'MISSING', "\n";
}
echo '  keys from the environment: private ', filled(config('passport.private_key')) ? 'set' : 'not set', ', public ', filled(config('passport.public_key')) ? 'set' : 'not set', "\n";
foreach ([League\OAuth2\Server\AuthorizationServer::class, League\OAuth2\Server\ResourceServer::class] as $class) {
    try {
        app($class);
        echo '  ', class_basename($class), ": builds\n";
    } catch (Throwable $e) {
        echo '  ', class_basename($class), ' FAILS: ', get_class($e), ': ', substr($e->getMessage(), 0, 300), "\n";
    }
}

echo "== Sign-in tables ==\n";
foreach (['oauth_clients', 'oauth_auth_codes', 'oauth_access_tokens', 'oauth_refresh_tokens', 'oauth_device_codes'] as $t) {
    echo '  ', $t, ': ', Schema::hasTable($t) ? DB::table($t)->count().' rows' : 'MISSING', "\n";
}
if (Schema::hasTable('oauth_auth_codes')) {
    foreach (DB::table('oauth_auth_codes')->selectRaw('client_id, revoked, count(*) as n, max(expires_at) as last_expiry')->groupBy('client_id', 'revoked')->get() as $r) {
        echo '  codes: ', json_encode($r), "\n";
    }
}

echo "== Session and cookies (the allow page needs the member signed in) ==\n";
echo '  session driver ', config('session.driver'), ', same_site ', var_export(config('session.same_site'), true), ', secure ', var_export(config('session.secure'), true),
    ', domain ', var_export(config('session.domain'), true), "\n";
echo '  auth guard for the allow page: ', var_export(config('passport.guard'), true), ', default guard ', config('auth.defaults.guard'), "\n";

echo "== Who could connect: the test member, and how many members in all ==\n";
try {
    $plans = app(App\Services\PlanAccessService::class);
    $u = App\Models\User::query()->find(8215);
    if ($u) {
        $plan = $plans->resolveAccessPlanForUserId(8215);
        echo '  member 8215: plan ', $plan?->slug ?? 'none', ', status for AI apps: ', App\Mcp\McpAccess::statusFor(8215, $plan), "\n";
    } else {
        echo "  member 8215: not here\n";
    }
    echo '  plans that allow AI apps: ', App\Models\Plan::query()->get()->filter(fn ($p) => App\Mcp\McpAccess::enabled($p))->map(fn ($p) => $p->slug.($p->is_active ? '' : ' (off)'))->implode(', '), "\n";
} catch (Throwable $e) {
    echo '  could not tell: ', substr($e->getMessage(), 0, 200), "\n";
}

echo "== Errors about sign-in in the app's logs (last lines that mention it) ==\n";
$hits = [];
foreach (glob(storage_path('logs/*.log')) ?: [] as $log) {
    if (filemtime($log) < time() - 35 * 86400) { continue; }
    $h = @fopen($log, 'r');
    if (! $h) { continue; }
    $size = filesize($log);
    if ($size > 40 * 1024 * 1024) { fseek($h, $size - 40 * 1024 * 1024); }
    while (($line = fgets($h)) !== false) {
        if ($line[0] === '[' && preg_match('/oauth|passport|League\\\\OAuth2|CryptKey|mcp/i', $line) && preg_match('/\.(ERROR|CRITICAL|WARNING|EMERGENCY)/', $line)) {
            $hits[] = basename($log).': '.substr(preg_replace('/eyJ[A-Za-z0-9_\-\.]+|lsk_live_[A-Za-z0-9]+|[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+/', '[hidden]', $line), 0, 420);
        }
    }
    fclose($h);
}
echo '  lines: ', count($hits), "\n";
foreach (array_slice($hits, -14) as $hit) { echo '  ', rtrim($hit), "\n"; }
