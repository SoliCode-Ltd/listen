<?php

/*
 * Staging only: one member's agent calls charged end to end on the real server, the way a
 * call is charged (AgentCalls::start holds the credits, AgentCalls::end charges the seconds
 * and gives the rest back), with this server's own plans, rates and speech key.
 *
 * Everything happens inside one database transaction that is rolled back at the end: the
 * member, their plan, agent and calls never stay. Nothing goes to the payment provider.
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
use App\Support\Agents\AgentAccess;
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
        $expected = AgentCalls::creditsFor(65, $agent);
        $check((int) $ended->billed_seconds === 65 && (int) $ended->credits === $expected,
            'after 65 s it is charged '.$n($ended->credits).' credits (65 s at the rate: '.$n($expected).')');
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
        $expected = AgentCalls::creditsFor(40, $agent);
        $check((int) $ended->credits === $expected && $used() === $before + $expected, 'it is charged '.$n($ended->credits).' credits for 40 s (credits used now '.$n($used()).')');
    }

    // 3. What the member's Agents page shows: the paid seconds and credits of this period.
    $check($calls->periodSeconds($user->id) === 65 + 40, 'the Agents page counts '.$calls->periodSeconds($user->id).' paid seconds this period');
    $check(AgentConversation::query()->where('user_id', $user->id)->where('free', false)->sum('credits') == $used(),
        'the calls\' credits add up to the credits used');
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
