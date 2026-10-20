<?php

/*
 * For the "Long-form samples record" workflow: makes each long text on the
 * voice samples page (database/seeders/data/long_form_samples.php) the way a
 * Studio Max member makes a voice, through the studio's own Generate request
 * (its checks, the pieces of a long text read at once over the site's keys,
 * their joins, loudness and the MP3), and times it from the request to the
 * finished file. Prints one JSON line per text with the recording and what
 * was measured. It uses the speech quota live and staging share (about 30
 * minutes of audio for all four), so it runs by hand only, when the owner has
 * said yes.
 *
 * The member it makes them as has no Stripe customer and goes back to the
 * free plan at the end; its voices stay in its history like any member's.
 *
 *   ssh user@host 'cd ~/public_html && php' < record.php   (the workflow puts the texts in)
 */

use App\Models\AudioGeneration;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Voice;
use App\Services\PlanAccessService;
use App\Services\Tts\GeminiKeyManager;
use App\Support\GeminiTtsModel;
use App\Support\VoiceCatalog;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

ini_set('display_errors', 'stderr');

$root = getcwd();
if (! is_file($root.'/artisan')) {
    fwrite(STDERR, "Run this from the Laravel app root.\n");
    exit(1);
}

$samples = json_decode(base64_decode('__SAMPLES__'), true, 512, JSON_THROW_ON_ERROR);

require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->instance('request', Request::create('/en'));
$kernel->bootstrap();

// Its own requests, one after another: past the form token and the studio's rate limit.
$app->instance(ValidateCsrfToken::class, new class($app, $app['encrypter']) extends ValidateCsrfToken
{
    public function handle($request, Closure $next)
    {
        return $next($request);
    }
});
$app->instance(ThrottleRequests::class, new class($app->make(Illuminate\Cache\RateLimiter::class)) extends ThrottleRequests
{
    public function handle($request, Closure $next, ...$limits)
    {
        return $next($request);
    }
});

$access = app(PlanAccessService::class);
$member = User::query()->firstOrCreate(
    ['email' => 'long-form-samples@example.com'],
    ['name' => 'Voice samples page', 'password' => bcrypt(Str::random(40))],
);
$member->forceFill(['subscription_status_checked_at' => now()])->save();
$plan = Plan::query()->subscriptionPlans()->where('is_active', true)->where('slug', 'studio_max')->first()
    ?? Plan::query()->subscriptionPlans()->where('is_active', true)->where('price_cents', '>', 0)->orderByDesc('price_cents')->first();
if (! $plan) {
    fwrite(STDERR, "No paid plan on sale.\n");
    exit(1);
}
Subscription::withoutEvents(fn () => $access->assignPlan($member, $plan));
fwrite(STDERR, "Member {$member->id} on {$plan->slug} (up to ".number_format((int) $plan->max_chars_per_request)." characters at once), ".count($samples)." text(s).\n");

// Each text starts only once every key has room, so all its pieces can go out at once
// (a key busy with members' voices would send them in two rounds). At most 3 minutes.
$keys = app(GeminiKeyManager::class);
$allRoom = function () use ($keys): int {
    $total = count($keys->getAllKeys());
    for ($waited = 0; $waited < 180; $waited += 10) {
        $room = count($keys->keysWithRoom(GeminiTtsModel::resolve()));
        if ($room >= $total) {
            return $room;
        }
        fwrite(STDERR, "{$room} of {$total} keys have room; waiting.\n");
        sleep(10);
    }

    return count($keys->keysWithRoom(GeminiTtsModel::resolve()));
};

$guard = $app['auth']->guard('web');
try {
    foreach ($samples as $sample) {
        $line = ['slug' => $sample['slug'], 'characters' => mb_strlen($sample['text']), 'text_sha1' => sha1($sample['text'])];
        try {
            $voice = Voice::query()->where('is_active', true)->where('internal_voice_id', $sample['voice'])->first();
            if (! $voice) {
                throw new RuntimeException("The voice {$sample['voice']} is not on sale on this site.");
            }
            $payload = [
                'content' => $sample['text'],
                'target_language' => $sample['language'],
                'voice_tier' => 'hd',
                'dialect' => $sample['dialect'],
                'performance_style' => $sample['style'],
                'voice_key' => VoiceCatalog::keyFromId((int) $voice->id),
            ];

            $line['keys_with_room'] = $allRoom();
            $guard->setUser($member);
            $last = (int) AudioGeneration::query()->where('user_id', $member->id)->max('id');
            $request = Request::create('/en/dashboard/queue', 'POST', $payload, [], [], ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
            $line['recorded_at'] = now()->utc()->toIso8601String();
            $started = microtime(true);
            $response = $kernel->handle($request);
            // A long text is made after the reply: this runs it, so the time runs to the finished file.
            $kernel->terminate($request, $response);
            $line['made_in_seconds'] = round(microtime(true) - $started, 1);
            $line['http'] = $response->getStatusCode();
            if ($response->getStatusCode() >= 400) {
                $body = json_decode((string) $response->getContent(), true);
                $line['refused'] = mb_substr(json_encode($body['errors'] ?? $body['message'] ?? $response->getContent(), JSON_UNESCAPED_UNICODE), 0, 400);
            }

            $generation = AudioGeneration::query()->where('user_id', $member->id)->where('id', '>', $last)->latest('id')->first();
            if ($generation) {
                $check = (array) (($generation->settings_snapshot ?? [])['pieces_check'] ?? []);
                $line += [
                    'status' => $generation->status,
                    'duration_seconds' => $generation->duration_seconds,
                    'pieces' => $check['pieces'] ?? null,
                    'pieces_at_once' => $check['pieces_at_once'] ?? null,
                    'pieces_check' => $check ?: null,
                    'error' => $generation->error_message ? mb_substr((string) $generation->error_message, 0, 300) : null,
                ];
                $path = (string) $generation->audio_path;
                if ($generation->status === 'done' && $path !== '' && Storage::disk('public')->exists($path)) {
                    $line['audio_base64'] = base64_encode((string) Storage::disk('public')->get($path));
                }
            }
        } catch (Throwable $e) {
            $line['error'] = get_class($e).': '.mb_substr($e->getMessage(), 0, 300);
        }
        echo json_encode($line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    }
} finally {
    // The member goes back to the free plan.
    $free = $access->freePlan();
    if ($free) {
        Subscription::withoutEvents(fn () => $access->assignPlan($member, $free));
    }
}
