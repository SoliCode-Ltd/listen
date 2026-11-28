<?php

/*
 * One-off, read-only: which migrations are pending on this server (files on the server
 * that its database has not recorded), before running "migrate". Nothing written.
 */
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;

if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
    DB::statement('SET SESSION TRANSACTION READ ONLY');
}
$ran = DB::table('migrations')->pluck('migration')->all();
$files = array_map(fn ($f) => basename($f, '.php'), glob(database_path('migrations/*.php')) ?: []);
$pending = array_values(array_diff($files, $ran));
sort($pending);
echo 'Now (UTC): ', now()->utc()->toDateTimeString(), "\n";
echo 'migration files on the server: ', count($files), ' | recorded in the database: ', count($ran), "\n";
echo "== pending (would run on migrate) ==\n";
foreach ($pending as $m) { echo '  ', $m, "\n"; }
echo $pending === [] ? "  none\n" : '';
foreach (['2026_10_29_120000_give_abeer_a_lighter_voice', '2026_11_09_120000_open_voice_design_on_creator_pro_studio_max', '2026_11_26_030000_widen_google_avatar_on_users_table'] as $m) {
    echo $m, ': ', in_array($m, $ran, true) ? 'already run' : 'NOT run', ' | file on server: ', in_array($m, $files, true) ? 'yes' : 'no', "\n";
}
