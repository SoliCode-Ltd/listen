<?php

/*
 * Staging only: "who is calling" end to end on the real server, the way a member meets it. A
 * member signed in on Listen's homepage gets their name and email, signed, on Listen's own call
 * button; the signature checks out for that button and no other; a call started with it keeps
 * who called (once the caller column is migrated: before that, the call goes on without it); and
 * the WordPress plugin carries the same button's secret.
 *
 * Everything happens inside one database transaction that is rolled back at the end: the member
 * and the call never stay, and any credits the call held or took come back with it. The only
 * thing outside the database is one short-lived key the speech service makes for the call start
 * (never used for a call), and the homepage's session for the member (removed at the end).
 *
 *   ssh user@host 'cd ~/public_html && php' < scripts/support/caller_identity_check.php
 */

use App\Models\AgentConversation;
use App\Models\AgentWidget;
use App\Models\UsageCounter;
use App\Models\User;
use App\Services\Agents\AgentCalls;
use App\Services\Agents\WordPressPlugin;
use App\Support\Agents\CallerIdentity;
use App\Support\Agents\HouseAgent;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
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
// Started as a web request for the English homepage, so the pages' routes carry their language
// the way they do for a visitor (the routes take it from the request they start with).
$request = Request::create('/en', 'GET', [], [], [], ['HTTPS' => 'on', 'REMOTE_ADDR' => '203.0.113.9']);
$app->instance('request', $request);
$kernel = $app->make(HttpKernel::class);
$kernel->bootstrap();

// An error anywhere stops the check as FAILED (left to Laravel, it would be shown and the run would still pass).
set_exception_handler(function (Throwable $e): void {
    fwrite(STDERR, 'The check stopped on an error: '.$e::class.': '.$e->getMessage().' ('.basename($e->getFile()).':'.$e->getLine().")\nFAILED\n");
    exit(1);
});

$host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);
$request->headers->set('host', $host);
$request->server->set('HTTP_HOST', $host);

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
$session = null;

DB::beginTransaction();
try {
    $house = HouseAgent::for('en');
    if (! $house) {
        throw new RuntimeException('Listen\'s own agent is not on for English on this server.');
    }
    $widget = AgentWidget::query()->where('public_key', $house['key'])->firstOrFail();
    $agent = $widget->agent;
    $migrated = AgentConversation::keepsCaller();
    echo '== Listen\'s own agent: '.$agent->name.' (button '.substr($widget->public_key, 0, 6).'…); caller column '.($migrated ? 'migrated' : 'NOT migrated yet').PHP_EOL;

    // Made the way a sign-up makes a member (factories need the development tools, which servers lack).
    $email = 'caller-check-'.uniqid().'@example.test';
    $user = User::query()->create(['name' => 'Caller Check', 'email' => $email, 'password' => bcrypt(bin2hex(random_bytes(16)))]);
    $user->forceFill(['email_verified_at' => now()])->save();

    // 1. The homepage the member sees: Listen's call button carries their signed name and email.
    auth()->guard('web')->setUser($user);
    $response = $kernel->handle($request);
    $session = $request->hasSession() ? $request->session()->getId() : null;
    $html = (string) $response->getContent();
    $check($response->getStatusCode() === 200, 'the homepage answers '.$response->getStatusCode().' for a signed-in member');
    $caller = [];
    if (preg_match('/<script src="[^"]*listen-agent\.js" data-agent="'.preg_quote($widget->public_key, '/').'"([^>]*)>/', $html, $tag)) {
        preg_match_all('/data-caller-(email|name|expires|signature)="([^"]*)"/', $tag[1], $found, PREG_SET_ORDER);
        foreach ($found as [, $field, $value]) {
            $caller[$field] = html_entity_decode($value, ENT_QUOTES);
        }
    }
    $check(($caller['email'] ?? null) === $email && ($caller['name'] ?? null) === 'Caller Check' && isset($caller['signature']),
        'the call button carries the member\'s name and email, signed');
    $left = (int) ($caller['expires'] ?? 0) - time();
    $check($left > 11 * 3600 && $left <= CallerIdentity::TTL, 'the signature is good for '.round($left / 3600, 1).' hours');
    $cache = strtolower((string) $response->headers->get('Cache-Control'));
    $check(str_contains($cache, 'private') || str_contains($cache, 'no-store'), 'the page is never cached for anyone else (Cache-Control: '.$cache.')');

    // 2. The signature checks out for this button only, and only as it was made.
    $verified = CallerIdentity::verify($widget, $caller);
    $check($verified === ['email' => $email, 'name' => 'Caller Check'], 'Listen accepts it for this button');
    $check(CallerIdentity::verify($widget, ['name' => 'Someone Else'] + $caller) === null, 'a changed name is refused');
    $other = AgentWidget::query()->where('id', '!=', $widget->id)->first();
    if ($other) {
        $check(CallerIdentity::verify($other, $caller) === null, 'another button refuses it');
    }

    // 3. A call started with it keeps who called. Listen's own agent: paid by its owner when that
    // account can pay (Agents → Settings), free when it cannot; it always answers.
    $calls = app(AgentCalls::class);
    $owner = (int) $agent->user_id;
    $usedBefore = (int) UsageCounter::query()->where('user_id', $owner)->sum('total_chars');
    $start = $calls->start($agent, 'widget', $widget, ['origin' => rtrim((string) config('app.url'), '/'), 'visitor' => '203.0.113.9|caller-check',
        'network' => '203.0.113.9', 'caller' => $verified]);
    $check($start['ok'] === true, 'the call starts, '.($start['ok'] ? ($start['free'] ? 'free (its owner does not pay now)' : 'paid by its owner') : 'refused ('.($start['error'] ?? '?').')'));
    if ($start['ok']) {
        $kept = $start['conversation']->fresh()->caller;
        $check($verified !== null && ($migrated ? $kept === $verified : $kept === null),
            $migrated ? 'the call keeps who called: '.json_encode($kept) : 'before the migration the call goes on without keeping who called');
        Carbon::setTestNow(now()->addSeconds(30));
        $ended = $calls->end($start['conversation']->fresh(), [], null, 'done');
        Carbon::setTestNow();
        $used = (int) UsageCounter::query()->where('user_id', $owner)->sum('total_chars') - $usedBefore;
        $expected = $ended->free ? 0 : AgentCalls::creditsFor((int) $ended->billed_seconds, $agent);
        $check((int) $ended->credits === $expected && $used === $expected,
            'after '.$ended->billed_seconds.' s it takes '.number_format($expected).' credits from its owner (the rest of the hold given back)');
    }

    // 4. The WordPress plugin for this button signs with the same secret.
    $files = app(WordPressPlugin::class)->files($widget);
    $check(str_contains($files['listen-voice-agent.php'] ?? '', "const CALLER_SECRET = '".CallerIdentity::secret($widget->public_key)."';")
        && str_contains($files['src/Settings.php'] ?? '', "get_option(self::CALLERS, '0')"),
        'the WordPress plugin ('.WordPressPlugin::VERSION.') carries this button\'s secret, and "Show who is calling" starts off');
} catch (Throwable $e) {
    $failures++;
    echo '  FAIL '.get_class($e).': '.$e->getMessage().' ('.basename($e->getFile()).':'.$e->getLine().')'.PHP_EOL;
} finally {
    Carbon::setTestNow();
    DB::rollBack();
    if ($session) {
        try {
            app('session')->driver()->getHandler()->destroy($session);
        } catch (Throwable) {
        }
    }
    echo PHP_EOL.'  Rolled back: nothing stays on this server.'.PHP_EOL;
}

echo PHP_EOL.'== Summary'.PHP_EOL;
echo '  '.($failures === 0 ? 'Every check passed.' : $failures.' check(s) failed.').PHP_EOL;
exit($failures === 0 ? 0 : 1);
