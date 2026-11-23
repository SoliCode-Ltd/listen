<?php

/*
 * Read-only: how many members come from each country (users.country_code, kept at sign-up), all
 * members and paying ones, for a "where our members are" picture. Counts only: no name, no email.
 * Admin accounts are left out. On MySQL the session is READ ONLY before any query.
 *
 *   ssh user@host 'cd ~/public_html && php' < scripts/support/member_countries.php
 */

use App\Models\Payment;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
    DB::statement('SET SESSION TRANSACTION READ ONLY');
}

$members = DB::table('users')->where(fn ($q) => $q->where('is_admin', false)->orWhereNull('is_admin'));
$total = (clone $members)->count();
$known = (clone $members)->whereNotNull('country_code')->where('country_code', '!=', '')->count();
$all = (clone $members)->whereNotNull('country_code')->where('country_code', '!=', '')
    ->select('country_code', DB::raw('count(*) as n'))->groupBy('country_code')->orderByDesc('n')->pluck('n', 'country_code');
$payers = DB::table('payments')->whereNotNull('paid_at')->distinct()->pluck('user_id');
$paying = (clone $members)->whereIn('id', $payers)->whereNotNull('country_code')->where('country_code', '!=', '')
    ->select('country_code', DB::raw('count(*) as n'))->groupBy('country_code')->orderByDesc('n')->pluck('n', 'country_code');

echo "Members: {$total}, country known for {$known}\n";
echo 'Countries with members: '.$all->count()."\n";
echo 'Countries with paying members: '.$paying->count()."\n";
echo "\n== country,members,paying (csv) ==\n";
foreach ($all as $code => $n) {
    echo strtoupper((string) $code).','.$n.','.((int) ($paying[$code] ?? 0))."\n";
}
echo "\nNothing was changed.\n";
