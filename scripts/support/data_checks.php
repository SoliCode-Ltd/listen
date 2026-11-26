<?php

/*
 * One-off, STAGING ONLY (it refuses anywhere else), run through the "Server deploy"
 * workflow's data-checks mode from this branch only: removes the throwaway member
 * made to try "Sign in with Listen", with its apps, keys, tokens and plan.
 */
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

if (! str_contains((string) config('app.url'), 'cloudwaysapps.com')) {
    echo "Not staging (", config('app.url'), "): nothing done.\n";
    exit(0);
}
$user = App\Models\User::query()->where('email', 'mcp-connection-check@listen.invalid')->first();
if (! $user) {
    echo "No throwaway member here.\n";
    exit(0);
}
$id = (int) $user->id;
try {
    $clients = DB::table('oauth_clients')->where('name', 'Connection check')->pluck('id');
    $tokens = DB::table('oauth_access_tokens')->where('user_id', $id)->pluck('id');
    echo 'refresh tokens removed: ', DB::table('oauth_refresh_tokens')->whereIn('access_token_id', $tokens)->delete(), "\n";
    echo 'access tokens removed: ', DB::table('oauth_access_tokens')->where('user_id', $id)->delete(), "\n";
    echo 'codes removed: ', DB::table('oauth_auth_codes')->where('user_id', $id)->delete(), "\n";
    echo 'test apps removed: ', DB::table('oauth_clients')->whereIn('id', $clients)->delete(), "\n";
    foreach (['api_keys', 'subscriptions', 'usage_counters', 'api_request_logs'] as $table) {
        if (Schema::hasTable($table) && Schema::hasColumn($table, 'user_id')) {
            echo $table, ' removed: ', DB::table($table)->where('user_id', $id)->delete(), "\n";
        }
    }
    echo 'member removed: ', DB::table('users')->where('id', $id)->delete(), "\n";
} catch (Throwable $e) {
    echo 'FAILED: ', get_class($e), ': ', substr($e->getMessage(), 0, 400), "\n";
}
