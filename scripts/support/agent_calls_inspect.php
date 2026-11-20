<?php

/*
 * Read-only: are agent calls charged right on this server?
 *
 * - Listen's own agents (HouseAgent): their calls are on Listen, so every one is free and
 *   takes nothing from the account that owns them.
 * - Members' agents: an owner's own test calls are free until their free test seconds are
 *   used; every other call holds credits when it starts and is charged its billed seconds at
 *   the agent's rate when it ends (AgentCalls::settle). For each call: what it was charged
 *   against what its seconds cost, and for each owner: their period's credits used and how
 *   much of it is calls.
 *
 * Prints ids and numbers only, never what a caller said; emails are masked. On MySQL the
 * session is READ ONLY before any query.
 *
 *   ssh user@host 'cd ~/public_html && php' < scripts/support/agent_calls_inspect.php
 */

use App\Models\Agent;
use App\Models\AgentConversation;
use App\Models\UsageCounter;
use App\Models\User;
use App\Services\Agents\AgentCalls;
use App\Services\PlanAccessService;
use App\Support\Agents\AgentAccess;
use App\Support\CreditPeriod;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

ini_set('display_errors', 'stderr');

$root = getcwd();
if (! is_file($root.'/artisan')) {
    fwrite(STDERR, "Run this from the Laravel app root.\n");
    exit(1);
}

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// An error anywhere stops the check as FAILED (left to Laravel, it would be shown and the run would still pass).
set_exception_handler(function (Throwable $e): void {
    fwrite(STDERR, 'The check stopped on an error: '.$e::class.': '.$e->getMessage().' ('.basename($e->getFile()).':'.$e->getLine().")\nFAILED\n");
    exit(1);
});

if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
    DB::statement('SET SESSION TRANSACTION READ ONLY');
}

$line = static function (string $label, $value): void {
    echo '  '.str_pad($label, 46).(is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value).PHP_EOL;
};
$mask = static function (?string $email): string {
    $email = (string) $email;
    $at = strpos($email, '@');

    return $at === false ? '***' : substr($email, 0, min(3, $at)).'***'.substr($email, $at);
};
$n = static fn ($value): string => number_format((int) $value);
$problems = 0;
$flag = static function (string $why) use (&$problems): void {
    $problems++;
    echo '  !! '.$why.PHP_EOL;
};

$since = now()->subDays(30);
$agents = Agent::withTrashed()->get();
$houseIds = $agents->filter(fn (Agent $a) => (bool) $a->setting('house'))->pluck('id')->all();

echo '== Settings'.PHP_EOL;
$line('Credits a call minute (the plans)', $n(AgentAccess::creditsPerMinute()));
$line('Free test seconds an owner gets', $n(AgentAccess::freeTestSeconds()));
$line('Calls stopped for everyone', AgentAccess::callsStopped());
echo PHP_EOL;

echo '== Listen\'s own agents (paid by the account that owns them when it can, else free)'.PHP_EOL;
foreach ($agents->whereIn('id', $houseIds) as $agent) {
    $calls = AgentConversation::query()->where('agent_id', $agent->id)->where('started_at', '>=', $since)->get();
    $line('Agent '.$agent->id.' ('.$agent->language.', owner '.$agent->user_id.')', $calls->count().' calls in 30 days');
    // Whether its owner pays for its calls now: a plan with agents, and credits for a whole call.
    $owner = (int) $agent->user_id;
    $limits = AgentAccess::limitsFor($owner);
    $quotaPlan = app(PlanAccessService::class)->resolveQuotaPlanForUserId($owner);
    $line('   owner\'s plan', ($quotaPlan?->slug ?? 'none').($limits['included'] ? ', with agents' : ', without agents'));
    $line('   owner can pay for', $n(app(AgentCalls::class)->affordableSeconds($owner, $quotaPlan, $agent, 180)).' s of calls now');
    $line('   charged to its owner (setting)', method_exists(AgentAccess::class, 'houseCharged') ? AgentAccess::houseCharged() : 'not on this server yet');
    foreach ($calls->groupBy('channel') as $channel => $group) {
        $line('   '.$channel, $group->count().' calls, '.$n($group->sum('billed_seconds')).' s, free '.$group->where('free', true)->count().', credits '.$n($group->sum('credits')));
    }
    // A paid call takes what its seconds cost, like a member's call.
    foreach ($calls->where('free', false)->whereNotNull('settled_at') as $call) {
        if ((int) $call->credits !== AgentCalls::creditsFor((int) $call->billed_seconds, $agent)) {
            $flag('call '.$call->id.' of Listen\'s own agent '.$agent->id.' took '.$n($call->credits).' credits for '.$call->billed_seconds.' s.');
        }
    }
}
echo PHP_EOL;

echo '== Members\' agents: calls in the last 30 days'.PHP_EOL;
$calls = AgentConversation::query()->whereNotIn('agent_id', $houseIds ?: [0])->where('started_at', '>=', $since)->orderBy('id')->get();
$line('Calls', $calls->count());
foreach ($calls->groupBy(fn ($c) => $c->channel.($c->free ? ' (free)' : ' (paid)')) as $kind => $group) {
    $line('   '.$kind, $group->count().' calls, '.$n($group->sum('billed_seconds')).' s, '.$n($group->sum('credits')).' credits');
}
$line('Still live now', $calls->where('status', AgentConversation::LIVE)->count());
echo PHP_EOL;

echo '== Each member\'s call (newest 80): charged against what its seconds cost'.PHP_EOL;
foreach ($calls->sortByDesc('id')->take(80) as $c) {
    $agent = $agents->firstWhere('id', $c->agent_id);
    $expected = $c->free ? 0 : AgentCalls::creditsFor((int) $c->billed_seconds, $agent);
    $held = (int) ($c->reservation['credits'] ?? 0);
    $state = $c->settled_at ? 'settled' : $c->status;
    echo sprintf('  #%d %s owner %d agent %d %-6s %-8s %4ds billed, %7s credits (seconds cost %s, held %s)%s',
        $c->id, $c->started_at->format('m-d H:i'), $c->user_id, $c->agent_id, $c->channel, $state,
        (int) $c->billed_seconds, $n($c->credits), $n($expected), $n($held), $c->free ? ' free' : '').PHP_EOL;
    if ($c->settled_at && ! $c->free && (int) $c->credits !== $expected) {
        $flag('call '.$c->id.' was charged '.$c->credits.' credits; its '.$c->billed_seconds.' s cost '.$expected.'.');
    }
    if (! $c->free && $c->settled_at && (int) $c->billed_seconds > 0 && (int) $c->credits === 0) {
        $flag('call '.$c->id.' lasted '.$c->billed_seconds.' s but was charged nothing.');
    }
    if (! $c->settled_at && $c->status === AgentConversation::LIVE && $c->last_seen_at && $c->last_seen_at->lt(now()->subMinutes(10))) {
        $flag('call '.$c->id.' is still open but quiet since '.$c->last_seen_at->toDateTimeString().'.');
    }
}
echo PHP_EOL;

echo '== Each owner with calls: this period\'s credits used, and the calls in them'.PHP_EOL;
foreach ($calls->groupBy('user_id') as $userId => $group) {
    $user = User::query()->find($userId);
    $period = CreditPeriod::current((int) $userId);
    $counter = UsageCounter::query()->where('user_id', $userId)->where('period_ym', $period['key'])->first();
    $inPeriod = AgentConversation::query()->where('user_id', $userId)->where('free', false)
        ->where(fn ($q) => $q->where('period_ym', $period['key'])
            ->orWhere(fn ($q) => $q->whereNull('period_ym')->where('started_at', '>=', $period['starts_at'])))
        ->get();
    $callCredits = (int) $inPeriod->sum(fn ($c) => $c->settled_at ? (int) $c->credits : (int) ($c->reservation['credits'] ?? 0));
    $freeTest = (int) AgentConversation::query()->where('user_id', $userId)->where('channel', 'test')->where('free', true)->sum('billed_seconds');
    echo '  owner '.$userId.' '.$mask($user?->email).PHP_EOL;
    $line('   period', $period['key'].' (from '.$period['starts_at']->toDateString().')');
    $line('   credits used this period (all)', $n($counter?->total_chars));
    $line('   of which paid calls', $n($callCredits).' credits, '.$n(app(AgentCalls::class)->periodSeconds((int) $userId)).' s');
    $line('   free test seconds used / left', $n($freeTest).' / '.$n(app(AgentCalls::class)->freeTestSecondsLeft((int) $userId)));
    if ($counter && (int) $counter->total_chars < $callCredits) {
        $flag('owner '.$userId.': the period\'s credits used ('.$counter->total_chars.') are fewer than their calls alone ('.$callCredits.').');
    }
    if (! $counter && $callCredits > 0) {
        $flag('owner '.$userId.': paid calls this period but no usage counter.');
    }
}
echo PHP_EOL;

echo '== Summary'.PHP_EOL;
echo '  '.($problems === 0 ? 'No problem found.' : $problems.' problem(s) flagged above (lines starting with !!).').PHP_EOL;
