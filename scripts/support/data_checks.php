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

echo "== countries outside the Arab world, most members first ==\n";
foreach (array_slice(array_diff_key($c, array_flip($arab)), 0, 15, true) as $code => $n) { echo '  ', $code, ' ', $n, "\n"; }
echo '  outside the Arab world: ', $total - array_sum(array_intersect_key($c, array_flip($arab))), ' members in ', count(array_diff_key($c, array_flip($arab))), " countries\n";
echo '  Arab countries with members: ', count(array_intersect_key($c, array_flip($arab))), "\n";
