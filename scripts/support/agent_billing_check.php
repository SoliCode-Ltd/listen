<?php

/*
 * Staging only: one member's agent calls charged end to end on the real server, the way a
 * call is charged (AgentCalls::start holds the credits, AgentCalls::end charges the seconds
 * and gives the rest back), with this server's own plans, rates and speech key.
 *
 * Everything happens inside one database transaction that is rolled back at the end: the
 * member, their plan, agent and calls never stay (nor Listen's own agent, lent to the member for
 * one call). Nothing goes to the payment provider.
 * The only thing outside the database is one short-lived key the speech service makes for
 * each call start (never used for a call).
 *
 *   ssh user@host 'cd ~/public_html && php' < scripts/support/agent_billing_check.php
 */

use App\Models\Agent;
use App\Models\AgentConversation;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use App\Services\Agents\AgentCalls;
use App\Services\PlanAccessService;
use App\Support\Agents\AgentAccess;
use App\Support\Agents\HouseAgent;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
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

// Staging only: live runs real customers.
if (filter_var(config('listen.live_actions'), FILTER_VALIDATE_BOOLEAN)) {
    fwrite(STDERR, "This check runs on staging only.\n");
    exit(1);
}

$failures = 0;
$check = static function (bool $ok, string $what) use (&$failures): void {
    if (! $ok) {
        $failures++;
    }
    echo '  '.($ok ? 'PASS ' : 'FAIL ').$what.PHP_EOL;
};
$n = static fn ($value): string => number_format((int) $value);

DB::beginTransaction();
try {
    $plan = Plan::query()->subscriptionPlans()->where('is_active', true)->where('price_cents', '>', 0)
        ->orderBy('price_cents')->get()->first(fn (Plan $p) => AgentAccess::limits($p)['included']);
    if (! $plan) {
        throw new RuntimeException('No active paid plan with agents on this server.');
    }
    // Made the way a sign-up makes a member (factories need the development tools, which servers lack).
    $user = User::query()->create(['name' => 'Billing check', 'email' => 'agent-billing-check-'.uniqid().'@example.test', 'password' => bcrypt(bin2hex(random_bytes(16)))]);
    $user->forceFill(['email_verified_at' => now()])->save();
    Subscription::create(['user_id' => $user->id, 'plan_id' => $plan->id, 'status' => 'active']);
    $agent = Agent::create([
        'user_id' => $user->id, 'name' => 'Billing check', 'business_name' => 'Billing check', 'business_type' => 'services',
        'jobs' => ['answer'], 'language' => 'ar', 'dialect' => 'ar-EG', 'speaker_id' => 'ar-Gemini-HD-Achernar', 'live_voice' => 'Achernar',
        'status' => Agent::LIVE, 'max_call_seconds' => 600, 'first_message' => 'أهلًا',
    ]);
    $widget = $agent->mainWidget();
    $calls = app(AgentCalls::class);
    $used = static fn (): int => (int) UsageCounter::query()->where('user_id', $user->id)->sum('total_chars');

    echo '== Plan '.$plan->slug.': '.$n($plan->monthly_chars).' credits a month, '.$n(AgentAccess::creditsPerMinute()).' credits a call minute'.PHP_EOL;

    // 1. A caller on the member's website: the whole call is held, then 65 seconds are charged.
    $start = $calls->start($agent, 'widget', $widget, ['origin' => 'https://example.test', 'visitor' => '203.0.113.9|billing-check', 'network' => '203.0.113.9']);
    $check($start['ok'] === true, 'a caller\'s call starts'.($start['ok'] ? '' : ' ('.($start['error'] ?? '?').')'));
    if ($start['ok']) {
        $c = $start['conversation'];
        $held = (int) ($c->reservation['credits'] ?? 0);
        $check($start['free'] === false, 'it is paid, not free');
        $check($held === AgentCalls::creditsFor((int) $start['max_seconds'], $agent) && $used() === $held,
            'its longest call is held: '.$n($held).' credits for '.$start['max_seconds'].' s (used now '.$n($used()).')');
        Carbon::setTestNow(now()->addSeconds(65));
        $ended = $calls->end($c->fresh(), [], null, 'done');
        Carbon::setTestNow();
        // The start time is kept to the second, so a call can measure one second more.
        $billed = (int) $ended->billed_seconds;
        $expected = AgentCalls::creditsFor($billed, $agent);
        $check(in_array($billed, [65, 66], true) && (int) $ended->credits === $expected,
            'after 65 s it is charged '.$n($ended->credits).' credits ('.$billed.' s at the rate: '.$n($expected).')');
        $check($used() === $expected, 'the rest of the hold is given back: credits used now '.$n($used()));
    }

    // 2. The owner's own test calls: free for their first free test seconds, then paid.
    $before = $used();
    $free = AgentAccess::freeTestSeconds();
    $test = $calls->start($agent, 'test', $widget, ['visitor' => '203.0.113.9|billing-check-owner']);
    $check($test['ok'] === true && $test['free'] === true, 'the owner\'s first test call is free ('.$n($free).' free test seconds)');
    if ($test['ok']) {
        Carbon::setTestNow(now()->addSeconds($free + 30));
        $ended = $calls->end($test['conversation']->fresh(), [], null, 'done');
        Carbon::setTestNow();
        $check((int) $ended->credits === 0 && $used() === $before, 'it takes no credits ('.$ended->billed_seconds.' s, capped at the free seconds)');
    }
    $paidTest = $calls->start($agent, 'test', $widget, ['visitor' => '203.0.113.9|billing-check-owner']);
    $check($paidTest['ok'] === true && $paidTest['free'] === false, 'once the free test seconds are used, a test call is paid');
    if ($paidTest['ok']) {
        Carbon::setTestNow(now()->addSeconds(40));
        $ended = $calls->end($paidTest['conversation']->fresh(), [], null, 'done');
        Carbon::setTestNow();
        $billed = (int) $ended->billed_seconds;
        $expected = AgentCalls::creditsFor($billed, $agent);
        $check(in_array($billed, [40, 41], true) && (int) $ended->credits === $expected && $used() === $before + $expected,
            'after 40 s it is charged '.$n($ended->credits).' credits ('.$billed.' s at the rate: '.$n($expected).'; credits used now '.$n($used()).')');
    }

    // 3. What the member's Agents page shows: the paid seconds and credits of this period.
    $paid = (int) AgentConversation::query()->where('user_id', $user->id)->where('free', false)->sum('billed_seconds');
    $check($calls->periodSeconds($user->id) === $paid, 'the Agents page counts '.$calls->periodSeconds($user->id).' paid seconds this period (the paid calls: '.$paid.' s)');
    $check(AgentConversation::query()->where('user_id', $user->id)->where('free', false)->sum('credits') == $used(),
        'the calls\' credits add up to the credits used');

    // 4. Listen's own agent, lent to a member for one call: a visitor's call is paid by its owner
    // when that account can pay for a whole call (Agents → Settings, "use their owner's credits"),
    // and free when it cannot, so it never turns a visitor away. Rolled back with everything else.
    $house = ($id = HouseAgent::for('en')['agent'] ?? null) ? Agent::query()->where('public_id', $id)->first() : null;
    if (! $house || ! method_exists(AgentAccess::class, 'houseCharged')) {
        echo '  (Listen\'s own agent: not on this server, or this server\'s code does not charge it yet)'.PHP_EOL;
    } else {
        $houseCall = function (User $owner, string $who) use ($house, $calls, $check, $n): void {
            $house->forceFill(['user_id' => $owner->id])->save();
            $agent = $house->fresh();
            $usedBy = fn (): int => (int) UsageCounter::query()->where('user_id', $owner->id)->sum('total_chars');
            $quotaPlan = app(PlanAccessService::class)->resolveQuotaPlanForUserId($owner->id);
            $pays = AgentAccess::limitsFor($owner->id)['included']
                && $calls->affordableSeconds($owner->id, $quotaPlan, $agent, HouseAgent::MAX_SECONDS) >= HouseAgent::MAX_SECONDS;
            $before = $usedBy();
            $hs = $calls->start($agent, 'widget', $agent->mainWidget(), ['origin' => rtrim((string) config('app.url'), '/'),
                'visitor' => '203.0.113.9|billing-check-house-'.$owner->id, 'network' => '203.0.113.9']);
            $held = $usedBy() - $before;
            $check($hs['ok'] === true && $hs['free'] === ! $pays && $held === ($pays ? AgentCalls::creditsFor((int) $hs['max_seconds'], $agent) : 0),
                'Listen\'s own agent, owned by '.$who.': its call starts '
                .($pays ? 'paid, holding '.$n($held).' credits for '.$hs['max_seconds'].' s' : 'free (a whole call costs more than the credits left)')
                .($hs['ok'] ? '' : ' ('.($hs['error'] ?? '?').')'));
            if ($hs['ok']) {
                Carbon::setTestNow(now()->addSeconds(50));
                $ended = $calls->end($hs['conversation']->fresh(), [], null, 'done');
                Carbon::setTestNow();
                $expected = $pays ? AgentCalls::creditsFor((int) $ended->billed_seconds, $agent) : 0;
                $check((int) $ended->credits === $expected && $usedBy() === $before + $expected,
                    '  after '.$ended->billed_seconds.' s it takes '.$n($expected).' credits from its owner, the rest given back');
            }
        };
        // The member above, most of whose credits the calls above used.
        $houseCall($user, 'the member above');
        // A new member on the plan with agents that has the most credits.
        $big = Plan::query()->subscriptionPlans()->where('is_active', true)->where('price_cents', '>', 0)->get()
            ->filter(fn (Plan $p) => AgentAccess::limits($p)['included'])->sortByDesc('monthly_chars')->first();
        $rich = User::query()->create(['name' => 'Billing check', 'email' => 'agent-billing-check-'.uniqid().'@example.test', 'password' => bcrypt(bin2hex(random_bytes(16)))]);
        $rich->forceFill(['email_verified_at' => now()])->save();
        Subscription::create(['user_id' => $rich->id, 'plan_id' => $big->id, 'status' => 'active']);
        $houseCall($rich, 'a new member on '.$big->slug.' ('.$n($big->monthly_chars).' credits)');
    }
} catch (Throwable $e) {
    $failures++;
    echo '  FAIL '.get_class($e).': '.$e->getMessage().PHP_EOL;
} finally {
    Carbon::setTestNow();
    DB::rollBack();
    echo PHP_EOL.'  Rolled back: nothing stays on this server.'.PHP_EOL;
}

echo PHP_EOL.'== Summary'.PHP_EOL;
echo '  '.($failures === 0 ? 'Every check passed.' : $failures.' check(s) failed.').PHP_EOL;
exit($failures === 0 ? 0 : 1);
