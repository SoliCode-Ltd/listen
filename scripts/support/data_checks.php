<?php

/*
 * One-off, read-only (READ ONLY database session), run through the "Server deploy"
 * workflow's data-checks mode from this branch only: the public growth numbers for
 * a social video. Counts only (no money, no member, no text); nothing written.
 */
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;

if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
    DB::statement('SET SESSION TRANSACTION READ ONLY');
}
App\Support\MemberReach::forget();
$members = DB::table('users')->where(fn ($q) => $q->where('is_admin', false)->orWhereNull('is_admin'));
echo 'Now (UTC): ', now()->utc()->toDateTimeString(), "\n";
echo 'members: ', (clone $members)->count(), ', countries: ', App\Support\MemberReach::countryCount(), ', shown: ', App\Support\MemberReach::membersRoundedDown(), "\n";

echo "== members joined, by month (and running total) ==\n";
$run = 0;
foreach ((clone $members)->selectRaw("date_format(created_at, '%Y-%m') as m, count(*) as n")->groupBy('m')->orderBy('m')->get() as $r) {
    $run += $r->n; echo '  ', $r->m, ' ', $r->n, ' ', $run, "\n";
}
echo "== members joined, last 8 weeks ==\n";
foreach ((clone $members)->where('created_at', '>=', now()->subWeeks(8))->selectRaw("yearweek(created_at, 3) as w, min(date(created_at)) as d, count(*) as n")->groupBy('w')->orderBy('w')->get() as $r) {
    echo '  ', $r->d, ' ', $r->n, "\n";
}
echo "== members by region of the world (top countries) ==\n";
$c = App\Support\MemberReach::counts()['countries'];
arsort($c);
foreach (array_slice($c, 0, 12, true) as $code => $n) { echo '  ', $code, ' ', $n, "\n"; }
$total = array_sum($c);
$arab = ['EG','SA','AE','KW','QA','BH','OM','JO','LB','SY','IQ','PS','YE','SD','LY','TN','DZ','MA','MR','SO','DJ','KM'];
echo '  with a country: ', $total, ', in Arab countries: ', array_sum(array_intersect_key($c, array_flip($arab))), "\n";

echo "== recordings made (done), and audio hours ==\n";
$done = DB::table('audio_generations')->where('status', 'done');
echo '  all time: ', (clone $done)->count(), ' recordings, ', round((clone $done)->sum('duration_seconds') / 3600), " hours of audio\n";
foreach ((clone $done)->selectRaw("date_format(created_at, '%Y-%m') as m, count(*) as n, round(sum(duration_seconds)/3600) as h")->groupBy('m')->orderBy('m')->get() as $r) {
    echo '  ', $r->m, ' ', $r->n, ' recordings, ', $r->h, " h\n";
}
echo "== Arabic and English ==\n";
foreach ((clone $done)->selectRaw('target_language as l, count(*) as n')->groupBy('l')->orderByDesc('n')->limit(4)->get() as $r) { echo '  ', $r->l, ' ', $r->n, "\n"; }
echo "== most used dialects ==\n";
foreach ((clone $done)->whereNotNull('dialect')->selectRaw('dialect as d, count(*) as n')->groupBy('d')->orderByDesc('n')->limit(8)->get() as $r) { echo '  ', $r->d, ' ', $r->n, "\n"; }
if (Illuminate\Support\Facades\Schema::hasTable('transcriptions')) {
    echo '== transcriptions done: ', DB::table('transcriptions')->where('status', 'ready')->count(), ", hours: ", round(DB::table('transcriptions')->where('status', 'ready')->sum('duration_seconds') / 3600), " ==\n";
}
echo '== studio voices: ', DB::table('voices')->where('is_active', true)->count(), " ==\n";
