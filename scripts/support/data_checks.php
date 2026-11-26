<?php

/*
 * One-off, read-only (READ ONLY database session), run through the "Server deploy"
 * workflow's data-checks mode from this branch only: which migrations have not run
 * on this server, and what the Google picture column holds. Nothing is written.
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
$ran = DB::table('migrations')->pluck('migration')->all();
$files = array_map(fn ($f) => basename($f, '.php'), glob(database_path('migrations/*.php')) ?: []);
sort($files);
$pending = array_values(array_diff($files, $ran));
echo '== Pending migrations: ', count($pending), " ==\n";
foreach ($pending as $p) { echo '  ', $p, "\n"; }
echo '== users.google_avatar is: ', Schema::getColumnType('users', 'google_avatar'), " ==\n";
echo '  members with a picture: ', DB::table('users')->whereNotNull('google_avatar')->count(),
    ', longest address: ', (int) DB::table('users')->max(DB::raw('length(google_avatar)')), " characters\n";
