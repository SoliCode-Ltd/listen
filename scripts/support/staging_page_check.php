<?php

/*
 * For the "Server deploy" workflow's page-check mode, on STAGING only: shows
 * the pages members use inside the app (no browser), in one
 * language per run (LOCALE=en or ar), as a free and as a paying test member,
 * and fails when a page errors, answers an unexpected status, or shows text
 * as its translation key ("ui.dashboard.title").
 *
 * The billing pages are left out on purpose: staging runs the live Stripe
 * keys, and showing them can create a customer there. The test members have
 * no Stripe customer, so nothing here reaches Stripe, and the paying member's
 * plan is set with model events off (no welcome email) and taken back at the
 * end. As the free member it also checks that the demo waits for a confirmed
 * email and that a confirmation link works through the host's proxy (no email
 * is sent). Refuses to run on the live site.
 *
 *   ssh user@host 'cd ~/public_html && LOCALE=en php' < scripts/support/staging_page_check.php
 */

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\PlanAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

ini_set('display_errors', 'stderr');

$root = getcwd();
if (! is_file($root.'/artisan')) {
    fwrite(STDERR, "Run this from the Laravel app root.\n");
    exit(1);
}

$locale = in_array(getenv('LOCALE'), ['en', 'ar'], true) ? (string) getenv('LOCALE') : 'en';

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
// Started as a web request in this language, so the pages are found under /en or /ar.
$app->instance('request', Request::create('/'.$locale));
$kernel->bootstrap();

if (str_contains(strtolower((string) config('app.url')), 'listenai.studio')) {
    fwrite(STDERR, "This is the live site: the page check runs on staging only.\n");
    exit(1);
}

$access = app(PlanAccessService::class);
$member = fn (string $email, string $name): User => User::query()->firstOrCreate(
    ['email' => $email],
    ['name' => $name, 'password' => bcrypt(Str::random(40))],
);
$free = $member('staging-pages-free@example.com', 'Staging pages (free)');
$paid = $member('staging-pages-paid@example.com', 'Staging pages (paid)');
$freePlan = $access->freePlan();
// The dearest plan on sale, so every studio feature is on the page.
$paidPlan = Plan::query()->subscriptionPlans()->where('is_active', true)->where('price_cents', '>', 0)->orderByDesc('price_cents')->first();
if (! $freePlan || ! $paidPlan) {
    fwrite(STDERR, "No free plan or no paid plan on sale.\n");
    exit(1);
}
Subscription::withoutEvents(fn () => $access->assignPlan($free, $freePlan));
Subscription::withoutEvents(fn () => $access->assignPlan($paid, $paidPlan));
// The free member has not confirmed its email, so its pages show what to do about it.
$free->forceFill(['email_verified_at' => null, 'phone_verified_at' => null])->save();
echo "Language {$locale}: free member {$free->id}, paying member {$paid->id} on {$paidPlan->slug}.\n";

// [who, address, the statuses that are right]
$pages = [
    [null, '', [200]],
    [null, 'pricing', [200]],
    [null, 'voice-samples', [200]],
    [null, 'developers', [200]],
    [null, 'browsers-extension', [200]],
    [$free, 'dashboard', [200]],
    [$free, 'demo', [200]],
    [$free, 'dashboard/api-keys', [200, 302]],
    [$free, 'dashboard/voice-cloning', [200, 302]],
    [$paid, 'dashboard', [200]],
    // The keys page sends a member whose plan has no API to the studio.
    [$paid, 'dashboard/api-keys', [200, 302]],
    [$paid, 'dashboard/voice-cloning', [200, 302]],
    [$paid, 'browsers-extension', [200]],
    [$paid, 'browsers-extension/download', [200]],
    // The demo is for free accounts: a member with a plan is sent to the studio.
    [$paid, 'demo', [302]],
];

$failed = 0;
foreach ($pages as [$who, $path, $expected]) {
    $guard = $app['auth']->guard('web');
    if ($who) {
        $guard->setUser($who);
    } else {
        $guard->forgetUser();
    }
    $request = Request::create('/'.$locale.($path === '' ? '' : '/'.$path), 'GET', [], [], [], ['HTTP_ACCEPT' => 'text/html']);
    $started = microtime(true);
    try {
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);
    } catch (Throwable $e) {
        $failed++;
        printf("  FAIL  %-34s %s: %s\n", '/'.$locale.'/'.$path, get_class($e), mb_substr($e->getMessage(), 0, 300));

        continue;
    }
    $status = $response->getStatusCode();
    $body = (string) $response->getContent();
    preg_match_all('/>\s*(ui\.[a-z0-9_]+\.[a-z0-9_.]+)/', $body, $keys);
    $keys = array_values(array_unique($keys[1]));
    $problem = ! in_array($status, $expected, true) ? 'answered '.$status
        : ($keys !== [] ? 'text shown as keys: '.implode(' ', array_slice($keys, 0, 8)) : null);
    $error = $response->exception ?? null;
    if ($problem !== null && $error) {
        $problem .= ' ('.get_class($error).': '.mb_substr($error->getMessage(), 0, 300).')';
    }
    $who = $who === $paid ? 'paying' : ($who === $free ? 'free' : 'visitor');
    $where = $status >= 300 && $status < 400 ? ' -> '.parse_url((string) $response->headers->get('Location'), PHP_URL_PATH) : '';
    printf("  %-4s  %-34s %-8s %d %s%s, %d ms%s\n", $problem ? 'FAIL' : 'ok', '/'.$locale.($path === '' ? '' : '/'.$path), $who,
        $status, $status === 200 ? number_format(strlen($body)).' bytes' : '', $where, (int) round((microtime(true) - $started) * 1000),
        $problem ? ': '.$problem : '');
    $failed += $problem ? 1 : 0;
}

// Confirming an email (App\Support\EmailVerification). The demo refuses the
// unconfirmed free member before anything is checked or made (an empty text:
// were it let through, the demo would refuse the text, never make a voice).
// Then the link its email would carry, opened signed out at the site's own
// address through the host's proxy as a mail app would, confirms the address.
// No email is sent; the member is left unconfirmed again for the next run.
$app->instance(Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class, new class($app, $app['encrypter']) extends Illuminate\Foundation\Http\Middleware\ValidateCsrfToken
{
    public function handle($request, Closure $next)
    {
        return $next($request);
    }
});
$guard = $app['auth']->guard('web');
$guard->setUser($free);
$request = Request::create('/'.$locale.'/demo', 'POST', ['text' => ''], [], [], ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
try {
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);
    $status = $response->getStatusCode();
    $refused = $status === 403 && (json_decode((string) $response->getContent(), true)['email_verification_required'] ?? false) === true;
} catch (Throwable $e) {
    $status = 0;
    $refused = false;
}
printf("  %-4s  %-34s %-8s %d: %s\n", $refused ? 'ok' : 'FAIL', 'POST /'.$locale.'/demo', 'free', $status, $refused ? 'refused until the email is confirmed' : 'NOT refused for an unconfirmed email');
$failed += $refused ? 0 : 1;
$guard->forgetUser();

$link = app(App\Services\Auth\EmailVerificationMailer::class)->verificationUrl($free);
try {
    $reply = Illuminate\Support\Facades\Http::withoutRedirecting()->timeout(30)->get($link);
    $status = $reply->status();
    $landing = (string) parse_url((string) $reply->header('Location'), PHP_URL_PATH);
} catch (Throwable $e) {
    $status = 0;
    $landing = get_class($e);
}
$confirmed = $free->fresh()?->email_verified_at !== null;
printf("  %-4s  %-34s %-8s %d -> %s: %s\n", $confirmed ? 'ok' : 'FAIL', 'GET /'.$locale.'/email/verify/…', 'link', $status, $landing !== '' ? $landing : '-', $confirmed ? 'the address is confirmed' : 'the address is NOT confirmed');
$failed += $confirmed ? 0 : 1;
$free->forceFill(['email_verified_at' => null])->save();

// The demo remembers the device it is used from (App\Services\DemoLinkedAccounts),
// so a new account in an incognito window on it shares this member's demo. A
// made-up fingerprint for this test member only; nothing is generated.
if (App\Services\DemoLinkedAccounts::available()) {
    $device = hash('sha256', 'staging-page-check-device');
    $guard->setUser($free);
    $request = Request::create('/'.$locale.'/demo/quota', 'POST', [], [], [], [
        'HTTP_ACCEPT' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        'HTTP_X_CLIENT_DEVICE' => $device, 'HTTP_X_CLIENT_DEVICE_TOUCH' => '0',
    ]);
    try {
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);
        $status = $response->getStatusCode();
    } catch (Throwable $e) {
        $status = 0;
    }
    $remembered = $status === 200 && App\Models\MemberDevice::query()
        ->where('user_id', $free->id)->where('kind', App\Models\MemberDevice::DEVICE)->where('signal', $device)->exists();
    printf("  %-4s  %-34s %-8s %d: %s\n", $remembered ? 'ok' : 'FAIL', 'POST /'.$locale.'/demo/quota', 'free', $status, $remembered ? 'the device is remembered' : 'the device is NOT remembered');
    $failed += $remembered ? 0 : 1;
    $guard->forgetUser();
} else {
    echo "  --    the member_devices table is not there yet: run the migration\n";
}

// The paying test member goes back to the free plan.
Subscription::withoutEvents(fn () => $access->assignPlan($paid, $freePlan));

echo $failed === 0 ? "Every page answered as it should.\n" : "{$failed} page(s) did not.\n";
exit($failed === 0 ? 0 : 1);
