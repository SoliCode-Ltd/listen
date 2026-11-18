<?php

/*
 * Read-only: the members on the free plan who used more than the free demo
 * allows (DemoAllowance, 300 characters), and where each one's characters came
 * from, so it shows whether the limit was kept or got around:
 *
 *   1. the rules in force: the demo allowance, whether the free plan gives
 *      monthly characters, the free plan's own characters;
 *   2. free members whose own demo characters are over the allowance: what the
 *      demo said the limit was when each voice was made (each voice keeps it),
 *      referral reward characters given and spent, the demo's Voice Design try,
 *      the plan each voice was made on, and the other accounts that are the
 *      same person (DemoSamePerson) with their characters;
 *   3. accounts that are one person whose demo characters together are over
 *      the allowance while each account alone is not;
 *   4. free members whose studio credits this period (Monthly usage) are over
 *      the allowance: the paid plans, packs, gifts and trials they had, and when
 *      the voices were made against when the paid plan ended.
 *
 * Prints member ids, dates and numbers only: no email, no name, no text. On
 * MySQL the session is READ ONLY before any query.
 *
 *   ssh user@host 'cd ~/public_html && php' < scripts/support/free_overuse_inspect.php
 */

use App\Models\DemoVoiceGeneration;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\DemoSamePerson;
use App\Services\PlanAccessService;
use App\Support\CreditPeriod;
use App\Support\DemoAllowance;
use App\Support\FreeAllowance;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

ini_set('display_errors', 'stderr');
ini_set('memory_limit', '512M');

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

$started = microtime(true);
$n = static fn ($value): string => number_format((int) $value);
$line = static function (string $label, $value): void {
    echo '  '.str_pad($label, 50).(is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value).PHP_EOL;
};
$date = static fn ($value): string => $value ? Carbon::parse($value)->format('Y-m-d H:i') : '-';
$has = static fn (string $table): bool => Schema::hasTable($table);

$allowance = DemoAllowance::chars();
$freePlan = app(PlanAccessService::class)->freePlan();
$access = app(PlanAccessService::class);
$samePerson = app(DemoSamePerson::class);

echo "== 1. The rules in force now\n";
$setting = SiteSetting::query()->where('key', DemoAllowance::SETTING_KEY)->first();
$line('Demo allowance (characters, in total)', $n($allowance));
$line('  saved in Site Settings', $setting ? $n((int) $setting->value).' (last saved '.$date($setting->updated_at).' UTC)' : 'not saved: the default ('.$n((int) config('listen.demo.guest_char_allowance')).')');
$line('Free plan gives monthly characters', FreeAllowance::freePlanGivesMonthlyChars());
$fa = SiteSetting::query()->where('key', FreeAllowance::NEW_MEMBERS_KEY)->first();
$line('  that switch last saved', $fa ? $fa->value.' on '.$date($fa->updated_at).' UTC' : 'never (default on)');
$line('Free plan', $freePlan ? $freePlan->slug.' #'.$freePlan->id.', '.$n((int) $freePlan->monthly_chars).' characters a month' : 'none');
$line('A free allowance waits for a confirmed email', (bool) config('listen.demo.require_verified_email', true));

// The accounts on the free plan now, admins left out: every account without an active
// plan row on a paid plan or a pack (PlanAccessService gives those the free plan, row or not).
$paidNow = DB::table('subscriptions')
    ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
    ->where('subscriptions.status', 'active')
    ->where(fn ($q) => $q->where('plans.price_cents', '>', 0)->orWhere('plans.kind', Plan::KIND_PACK))
    ->distinct()->pluck('subscriptions.user_id')->map(fn ($id) => (int) $id)->all();
$freeIds = DB::table('users')->where('is_admin', false)->whereNotIn('id', $paidNow ?: [0])
    ->pluck('id')->map(fn ($id) => (int) $id)->all();
$freeSet = array_flip($freeIds);
$line('Accounts on the free plan now (not admins)', $n(count($freeIds)).' (paid plan or pack now: '.$n(count($paidNow)).')');

// Who ever paid: a card subscription, an approved manual payment, a paid pack, a paid trial.
$paidEver = [];
foreach ([
    ['billing_subscriptions', fn ($q) => $q->whereNotIn('stripe_status', ['incomplete', 'incomplete_expired'])],
    ['manual_payment_requests', fn ($q) => $q->where('status', 'approved')],
    ['char_pack_purchases', fn ($q) => $q->whereNotNull('paid_at')],
    ['billing_trials', fn ($q) => $q->where('status', 'paid')],
] as [$table, $where]) {
    if ($has($table)) {
        foreach ($where(DB::table($table))->distinct()->pluck('user_id') as $id) {
            $paidEver[(int) $id][] = $table;
        }
    }
}

echo "\n== 2. Free members whose own demo characters are over ".$n($allowance)."\n";
$demo = DB::table('demo_voice_generations')
    ->select('user_id', DB::raw('SUM(billable_chars) as chars'), DB::raw('COUNT(*) as voices'), DB::raw('MIN(created_at) as first_at'), DB::raw('MAX(created_at) as last_at'),
        DB::raw('MAX(quota_limit_chars) as max_limit'), DB::raw('MIN(quota_limit_chars) as min_limit'))
    ->whereNotNull('user_id')
    ->groupBy('user_id')
    ->havingRaw('SUM(billable_chars) > ?', [$allowance])
    ->orderByDesc('chars')
    ->get();
$overFree = $demo->filter(fn ($r) => isset($freeSet[(int) $r->user_id]))->values();
$overOther = $demo->count() - $overFree->count();
$line('Accounts with demo characters over the allowance', $n($demo->count()).' ('.$n($overFree->count()).' on the free plan now, '.$n($overOther).' on a paid plan, admins or no plan row)');

$rewardsBy = $has('demo_reward_transactions')
    ? DB::table('demo_reward_transactions')->select('user_id', 'kind', DB::raw('SUM(delta_chars) as chars'))->groupBy('user_id', 'kind')->get()->groupBy('user_id')
    : collect();
$reasonCount = [];
$rows = [];
foreach ($overFree as $r) {
    $id = (int) $r->user_id;
    $gens = DemoVoiceGeneration::query()->where('user_id', $id)->orderBy('id')
        ->get(['id', 'billable_chars', 'quota_limit_chars', 'quota_remaining_chars', 'metadata', 'created_at']);
    $rewardGranted = (int) ($rewardsBy->get($id)?->whereIn('kind', ['grant', 'revoke', 'adjustment'])->sum('chars') ?? 0);
    $rewardSpent = (int) abs((int) ($rewardsBy->get($id)?->where('kind', 'consume')->sum('chars') ?? 0));
    $rewardInVoices = (int) $gens->sum(fn ($g) => (int) (($g->metadata ?? [])['reward_chars_consumed'] ?? 0));
    $designVoices = $gens->filter(fn ($g) => ! empty(($g->metadata ?? [])['voice_design']));
    $plans = $gens->map(fn ($g) => (string) (($g->metadata ?? [])['plan_slug'] ?? '?'))->countBy()->map(fn ($c, $p) => "$p:$c")->implode(' ');
    $limits = $gens->pluck('quota_limit_chars')->filter(fn ($v) => $v !== null)->unique()->sort()->implode('/');
    // The characters the demo could give at the time: the biggest limit it named, plus the reward characters it spent.
    $couldGive = max((int) $r->max_limit, $allowance) + max($rewardSpent, $rewardInVoices);
    $unexplained = max(0, (int) $r->chars - $couldGive);
    $member = User::query()->find($id);
    $ids = $member ? $samePerson->ids($member) : [$id];
    $others = array_values(array_diff($ids, [$id]));
    $groupChars = (int) DB::table('demo_voice_generations')->whereIn('user_id', $ids)->sum('billable_chars');

    $reasons = [];
    if ((int) $r->max_limit > $allowance) {
        $reasons[] = 'limit was '.$n($r->max_limit).' when made';
    }
    if (max($rewardSpent, $rewardInVoices) > 0) {
        $reasons[] = 'referral rewards '.$n(max($rewardSpent, $rewardInVoices));
    }
    if ($unexplained > 0) {
        $reasons[] = 'NOT EXPLAINED: '.$n($unexplained).' over';
    }
    $reasonKey = $unexplained > 0 ? 'not explained' : ((int) $r->max_limit > $allowance && max($rewardSpent, $rewardInVoices) > 0 ? 'higher limit then + rewards' : ((int) $r->max_limit > $allowance ? 'higher limit then' : 'referral rewards'));
    $reasonCount[$reasonKey] = ($reasonCount[$reasonKey] ?? 0) + 1;

    $rows[] = sprintf('  %-7d %7s %5d  %-16s %-16s  limits %-14s reward +%s/-%s  design %d  plans %-18s paid %-3s  same person %s (%s chars)  %s',
        $id, $n($r->chars), (int) $r->voices, $date($r->first_at), $date($r->last_at), $limits !== '' ? $limits : '-',
        $n($rewardGranted), $n(max($rewardSpent, $rewardInVoices)), $designVoices->count(), $plans,
        isset($paidEver[$id]) ? 'yes' : 'no', $others === [] ? 'none' : count($others).' other(s) '.implode(',', array_slice($others, 0, 4)), $n($groupChars),
        implode('; ', $reasons));
}
echo "  member  chars voices first (UTC)      last (UTC)        demo limits named  reward given/spent  design  plans made on     paid  same person          why\n";
foreach (array_slice($rows, 0, 60) as $row) {
    echo $row.PHP_EOL;
}
if (count($rows) > 60) {
    echo '  ... and '.(count($rows) - 60)." more\n";
}
echo "  Why, counted:\n";
foreach ($reasonCount as $reason => $count) {
    $line('    '.$reason, $n($count));
}

// When the over-allowance voices were made, by month, and the limit each voice was told.
echo "\n  Demo voices of those members, by month: voices / characters / limits named\n";
if ($overFree->isNotEmpty()) {
    DB::table('demo_voice_generations')
        ->whereIn('user_id', $overFree->pluck('user_id')->all())
        ->select(DB::raw("DATE_FORMAT(created_at, '%Y-%m') as ym"), DB::raw('COUNT(*) as voices'), DB::raw('SUM(billable_chars) as chars'),
            DB::raw('MIN(quota_limit_chars) as lo'), DB::raw('MAX(quota_limit_chars) as hi'))
        ->groupBy('ym')->orderBy('ym')->get()
        ->each(fn ($m) => $line('    '.$m->ym, $n($m->voices).' / '.$n($m->chars).' / '.($m->lo === null ? '-' : $n($m->lo).'..'.$n($m->hi))));
}

echo "\n== 3. One person, several accounts: demo characters together over ".$n($allowance).", each account alone not\n";
$near = DB::table('demo_voice_generations')->whereNotNull('user_id')
    ->select('user_id', DB::raw('SUM(billable_chars) as chars'))->groupBy('user_id')
    ->havingRaw('SUM(billable_chars) >= ?', [(int) floor($allowance / 2)])
    ->havingRaw('SUM(billable_chars) <= ?', [$allowance])
    ->where('created_at', '>=', now()->subDays(120))
    ->orderByDesc('chars')->limit(600)->get();
$seen = [];
$groups = [];
foreach ($near as $r) {
    $id = (int) $r->user_id;
    if (isset($seen[$id]) || ! isset($freeSet[$id])) {
        continue;
    }
    $user = User::query()->find($id);
    if (! $user) {
        continue;
    }
    $usage = $samePerson->demoUsage($user);
    foreach ($usage['ids'] as $other) {
        $seen[(int) $other] = true;
    }
    if (count($usage['ids']) > 1 && $usage['chars'] > $allowance) {
        $groups[] = sprintf('  accounts %-40s together %7s chars', implode(',', $usage['ids']), $n($usage['chars']));
    }
}
$line('Members near the allowance checked (last 120 days)', $n($near->count()));
$line('Groups over the allowance together', $n(count($groups)));
foreach (array_slice($groups, 0, 30) as $g) {
    echo $g.PHP_EOL;
}

echo "\n== 4. Free members whose studio credits this period (Monthly usage) are over ".$n($allowance)."\n";
$candidates = DB::table('usage_counters')->whereIn('user_id', $freeIds ?: [0])->where('total_chars', '>', $allowance)
    ->orderByDesc('period_ym')->get()->groupBy('user_id');
$studioRows = [];
$studioWhy = [];
foreach ($candidates as $id => $counters) {
    $id = (int) $id;
    $period = CreditPeriod::current($id);
    $current = $counters->firstWhere('period_ym', $period['key']);
    if (! $current) {
        continue;
    }
    $active = $access->resolveActivePlanForUserId($id);
    if ($active && (int) $active->price_cents > 0 && ! $active->isPack()) {
        continue; // on a paid plan after all
    }
    $gens = DB::table('audio_generations')->where('user_id', $id)
        ->where('created_at', '>=', $period['starts_at']->copy()->subDays(2))
        ->select('id', 'status', 'created_at', 'billed_to_pack', 'api_key_id', 'duration_seconds')->orderBy('id')->get();
    $card = DB::table('billing_subscriptions')->where('user_id', $id)->orderByDesc('id')->first(['stripe_status', 'created_at', 'ends_at']);
    $trial = $has('billing_trials') ? DB::table('billing_trials')->where('user_id', $id)->orderByDesc('id')->first(['status', 'created_at', 'updated_at']) : null;
    $manual = DB::table('manual_payment_requests')->where('user_id', $id)->where('status', 'approved')->orderByDesc('id')->first(['created_at', 'updated_at']);
    $packs = $has('char_pack_purchases') ? (int) DB::table('char_pack_purchases')->where('user_id', $id)->whereNotNull('paid_at')->whereNull('refunded_at')->sum('chars') : 0;
    $gifts = $has('char_gifts') ? (int) DB::table('char_gifts')->where('user_id', $id)->whereNull('revoked_at')->where('period_ym', $period['key'])->sum('chars') : 0;
    $planRows = DB::table('subscriptions')->join('plans', 'plans.id', '=', 'subscriptions.plan_id')->where('user_id', $id)
        ->orderByDesc('subscriptions.id')->limit(3)->get(['plans.slug', 'subscriptions.status', 'subscriptions.kept_left_at', 'subscriptions.billing_downgraded_at', 'subscriptions.updated_at']);
    $endedAt = $card?->ends_at ?? $planRows->first()?->kept_left_at ?? $planRows->first()?->billing_downgraded_at;
    $afterEnd = $endedAt ? $gens->where('created_at', '>', $endedAt)->where('status', 'done')->count() : null;

    $why = [];
    if ($card) {
        $why[] = 'card plan '.$card->stripe_status.($card->ends_at ? ' ended '.$date($card->ends_at) : '');
    }
    if ($trial) {
        $why[] = 'trial '.$trial->status;
    }
    if ($manual) {
        $why[] = 'manual payment approved '.$date($manual->updated_at);
    }
    if ($packs > 0) {
        $why[] = 'packs '.$n($packs);
    }
    if ($gifts > 0) {
        $why[] = 'gift '.$n($gifts);
    }
    if ($why === []) {
        $why[] = 'NO PAID PLAN, PACK OR GIFT FOUND';
    }
    $key = $why[0] === 'NO PAID PLAN, PACK OR GIFT FOUND' ? 'not explained' : ($card ? 'card plan this period' : ($trial ? 'trial' : ($manual ? 'manual payment' : ($packs ? 'pack' : 'gift'))));
    $studioWhy[$key] = ($studioWhy[$key] ?? 0) + 1;
    $studioRows[] = sprintf('  %-7d period %-8s credits %8s gifted %6s  voices %3d (done %3d, after the paid plan ended %s, api %d, pack %d)  first %s last %s  plan rows %s  %s',
        $id, $period['key'], $n($current->total_chars), $n($current->bonus_chars), $gens->count(), $gens->where('status', 'done')->count(),
        $afterEnd === null ? '-' : $n($afterEnd), $gens->whereNotNull('api_key_id')->count(), $gens->where('billed_to_pack', 1)->count(),
        $date($gens->first()?->created_at), $date($gens->last()?->created_at),
        $planRows->map(fn ($p) => $p->slug.':'.$p->status)->implode(','), implode('; ', $why));
}
$line('Free members over the allowance this period', $n(count($studioRows)));
foreach (array_slice($studioRows, 0, 60) as $row) {
    echo $row.PHP_EOL;
}
echo "  Why, counted:\n";
foreach ($studioWhy as $reason => $count) {
    $line('    '.$reason, $n($count));
}

echo "\nDone in ".round(microtime(true) - $started, 1)." s.\n";
