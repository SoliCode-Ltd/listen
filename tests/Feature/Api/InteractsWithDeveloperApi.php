<?php

namespace Tests\Feature\Api;

use App\Models\ApiKey;
use App\Models\ApiKeyUsage;
use App\Models\AudioGeneration;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\UsageCounter;
use App\Models\User;
use App\Models\Voice;
use App\Services\PlanAccessService;
use App\Services\Tts\GeminiKeyManager;
use App\Services\Tts\TtsRoutingService;
use App\Support\CreditPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/**
 * What the developer API tests share: the site set up as it runs live
 * (premium voices spoken by Gemini, credits that follow each plan's own
 * dates), a paying member on a plan with the API, keys made the way the
 * dashboard makes them, and speech engines that answer with a recording of
 * a known length. Nothing leaves the machine and no real audio is made.
 */
trait InteractsWithDeveloperApi
{
    /** What the faked services were asked: gemini and google speech calls, and any other address. */
    protected \stdClass $speechCalls;

    /** Length, in seconds, of every recording the faked engines return. */
    protected int $audioSeconds = 4;

    /** Whether the faked translator answers (it is down otherwise). */
    protected bool $translatorUp = false;

    protected function setUpDeveloperApi(): void
    {
        // Held still in the middle of a credit period, so no test crosses a
        // month, a member's day or a rate-limit minute while it runs.
        $this->travelTo(Carbon::parse('2026-10-14 10:00:00', 'UTC'));

        Storage::fake('public');
        config([
            'services.gemini_tts.dialect_rewrite' => false,
            'services.gemini_tts.retry_pause_ms' => 0,
            'services.gemini_tts.voice_retry_pause' => false,
            'services.google_tts.api_key' => 'test-google-key',
            'services.google_tts.endpoint' => 'https://texttospeech.googleapis.com/v1/text:synthesize',
            'services.google_tts.audio_encoding' => 'MP3',
        ]);
        SiteSetting::set('tts_mode', TtsRoutingService::MODE_HYBRID, 'tts');
        // As on the servers: paid plans' credits follow the plan's own dates.
        SiteSetting::set(CreditPeriod::SWITCH_SETTING, '2026-10-01 00:00:00', 'billing');

        $key = ['key' => 'test-gemini-key', 'name' => 'GEMINI_TTS_API_KEY', 'index' => 1];
        $keys = \Mockery::mock(GeminiKeyManager::class);
        $keys->shouldReceive('getAllKeys')->andReturn([$key]);
        $keys->shouldReceive('getAvailableKey')->andReturn($key);
        $keys->shouldReceive('keysInOrder')->andReturn([$key]);
        $keys->shouldReceive('recordUsage')->andReturnNull();
        $keys->shouldReceive('markKeyExhausted')->andReturnNull();
        $keys->shouldReceive('markOutForToday')->andReturnNull();
        $this->app->instance(GeminiKeyManager::class, $keys);

        $this->fakeSpeech();
    }

    /**
     * Gemini's speech models answer with raw 24 kHz PCM (as they really do)
     * and Google with an MP3; either way the recording lasts $audioSeconds.
     * The translator answers only while $translatorUp; anything else (such as
     * Gemini's text models) is refused and noted in speechCalls->other.
     */
    protected function fakeSpeech(): void
    {
        $calls = (object) ['gemini' => 0, 'google' => 0, 'other' => []];
        $this->speechCalls = $calls;

        Http::fake(function ($request) use ($calls) {
            $url = $request->url();
            $seconds = $this->audioSeconds;
            if (str_contains($url, 'texttospeech.googleapis.com')) {
                $calls->google++;

                return Http::response(['audioContent' => base64_encode(self::mp3Seconds($seconds))]);
            }
            if (str_contains($url, 'generativelanguage.googleapis.com') && str_contains($url, '-tts')) {
                $calls->gemini++;

                return Http::response(['candidates' => [['content' => ['parts' => [['inlineData' => [
                    'mimeType' => 'audio/L16;codec=pcm;rate=24000',
                    'data' => base64_encode(str_repeat("\0\0", 24000 * $seconds)),
                ]]]]]]]);
            }
            $calls->other[] = $url;
            if ($this->translatorUp && str_contains($url, 'translate.googleapis.com')) {
                return Http::response([[['مرحبا بكم في هذا الاختبار', 'Hello', null, null, 10]], null, 'en']);
            }

            return Http::response(['error' => ['message' => 'Not available in tests.']], 503);
        });
    }

    /** A constant-bitrate MP3 (128 kbps) of exactly $seconds. */
    protected static function mp3Seconds(int $seconds): string
    {
        return "\xFF\xFB\x90\xC4".str_repeat("\0", 16000 * $seconds - 4);
    }

    /**
     * A paid subscription plan that includes the developer API.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function apiPlan(array $attributes = []): Plan
    {
        return Plan::factory()->create(array_merge([
            'slug' => 'creator',
            'name' => 'Creator',
            'kind' => Plan::KIND_SUBSCRIPTION,
            'price_cents' => 2900,
            'voice_tier' => 'hd',
            'commercial_use_allowed' => true,
            'api_access_enabled' => true,
            'api_requests_per_minute' => null,
            'api_concurrency' => null,
            'monthly_chars' => 90000,
            'monthly_minutes' => 100,
            'monthly_requests' => 0,
            'daily_requests' => 0,
            'max_chars_per_request' => 10000,
            'processing_priority' => 'standard',
            'allow_overage' => false,
            'tts_provider_policy' => 'gemini',
            'download_formats' => ['mp3'],
            'dialogue_enabled' => false,
            'custom_prompt_enabled' => false,
            'sound_effects_enabled' => false,
            'voice_cloning_enabled' => false,
            'is_active' => true,
            'is_featured' => false,
        ], $attributes));
    }

    /**
     * A member on the plan (a new API plan when none is given), paid by hand
     * so the billing check keeps the plan as it is.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function apiMember(?Plan $plan = null, array $attributes = []): User
    {
        $user = User::factory()->create(array_merge(['subscription_status_checked_at' => now()], $attributes));
        app(PlanAccessService::class)->assignPlan($user, $plan ?? $this->apiPlan());

        return $user;
    }

    /**
     * A key as the dashboard makes it; null scopes hold every permission.
     *
     * @param  array<int, string>|null  $scopes
     * @return array{key: ApiKey, plain: string}
     */
    protected function issueKey(User $user, ?array $scopes = null, ?int $monthlyCreditLimit = null, string $name = 'Server key'): array
    {
        return ApiKey::issue($user, $name, $scopes, $monthlyCreditLimit);
    }

    /**
     * A premium studio voice, spoken by Gemini.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function premiumVoice(array $attributes = []): Voice
    {
        return Voice::query()->create(array_merge([
            'language' => 'en',
            'gender' => 'female',
            'tier' => 'hd',
            'display_name' => 'Maya',
            'internal_voice_id' => 'en-Gemini-HD-Kore',
            'provider' => 'gemini',
            'provider_voice_id' => 'Kore',
            'is_active' => true,
        ], $attributes));
    }

    protected static function voiceId(Voice $voice): string
    {
        return 'voice_'.$voice->id;
    }

    /**
     * A finished generation already in the member's history, with its audio
     * stored (an MP3 of $audioSeconds), made without calling any engine.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function storedGeneration(User $user, array $attributes = []): AudioGeneration
    {
        $path = 'audio/stored-'.bin2hex(random_bytes(6)).'.mp3';
        Storage::disk('public')->put($path, self::mp3Seconds($this->audioSeconds));

        return AudioGeneration::query()->create(array_merge([
            'user_id' => $user->id,
            'input_text' => 'Hello from the archive.',
            'source_language' => 'en',
            'target_language' => 'en',
            'voice_tier' => 'hd',
            'voice_name' => 'en-Gemini-HD-Kore',
            'translate' => false,
            'status' => 'done',
            'audio_path' => $path,
            'duration_seconds' => $this->audioSeconds,
            'billable_chars' => 23,
        ], $attributes));
    }

    /** @param  array<string, string>  $headers */
    protected function apiGet(?string $plainKey, string $path, array $headers = []): TestResponse
    {
        return $this->get('/v1'.$path, $this->keyHeader($plainKey) + $headers);
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    protected function apiPost(?string $plainKey, string $path, array $body = [], array $headers = []): TestResponse
    {
        return $this->postJson('/v1'.$path, $body, $this->keyHeader($plainKey) + $headers);
    }

    /** @param  array<string, string>  $headers */
    protected function apiDelete(?string $plainKey, string $path, array $headers = []): TestResponse
    {
        return $this->delete('/v1'.$path, [], $this->keyHeader($plainKey) + $headers);
    }

    /**
     * POST /v1/text-to-speech/{voice_id}, waiting for the file.
     *
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    protected function speak(string $plainKey, Voice|string $voice, array $body = [], array $headers = []): TestResponse
    {
        $voiceId = $voice instanceof Voice ? self::voiceId($voice) : $voice;

        return $this->apiPost($plainKey, '/text-to-speech/'.$voiceId, $body + ['text' => 'Hello there, this is a test.'], $headers);
    }

    /**
     * POST /v1/text-to-speech/{voice_id}/async.
     *
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    protected function speakAsync(string $plainKey, Voice|string $voice, array $body = [], array $headers = []): TestResponse
    {
        $voiceId = $voice instanceof Voice ? self::voiceId($voice) : $voice;

        return $this->apiPost($plainKey, '/text-to-speech/'.$voiceId.'/async', $body + ['text' => 'Hello there, this is a test.'], $headers);
    }

    /** @return array<string, string> */
    private function keyHeader(?string $plainKey): array
    {
        return $plainKey === null ? [] : ['X-Listen-Api-Key' => $plainKey];
    }

    /**
     * The API's one error shape, with the request id of the answer.
     */
    protected function assertApiError(TestResponse $response, int $status, string $code, ?string $type = null, ?string $param = null): TestResponse
    {
        $response->assertStatus($status)
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonStructure(['error' => ['type', 'code', 'message', 'param', 'request_id']])
            ->assertJsonPath('error.code', $code);
        if ($type !== null) {
            $response->assertJsonPath('error.type', $type);
        }
        if ($param !== null) {
            $response->assertJsonPath('error.param', $param);
        }
        $this->assertStringStartsWith('req_', (string) $response->json('error.request_id'));
        $this->assertSame($response->headers->get('X-Request-Id'), $response->json('error.request_id'));

        return $response;
    }

    /** Credits the member has spent, over every credit period. */
    protected function creditsSpent(User $user): int
    {
        return (int) UsageCounter::query()->where('user_id', $user->id)->sum('total_chars');
    }

    /** Generations counted against the member's monthly requests, over every period. */
    protected function requestsCounted(User $user): int
    {
        return (int) UsageCounter::query()->where('user_id', $user->id)->sum('total_requests');
    }

    /** Seconds of audio counted on the member's usage, over every period. */
    protected function secondsCounted(User $user): int
    {
        return (int) UsageCounter::query()->where('user_id', $user->id)->sum('total_seconds');
    }

    /** Credits a key has spent against its own budget, over every period. */
    protected function keyCreditsSpent(ApiKey $key): int
    {
        return (int) ApiKeyUsage::query()->where('api_key_id', $key->id)->sum('credits');
    }

    /** Requests a key has counted against its own figures, over every period. */
    protected function keyRequestsCounted(ApiKey $key): int
    {
        return (int) ApiKeyUsage::query()->where('api_key_id', $key->id)->sum('requests');
    }

    /** Nothing was made, reserved or charged for the member. */
    protected function assertNothingCharged(User $user): void
    {
        $this->assertSame(0, $this->creditsSpent($user), 'no credits are charged');
        $this->assertSame(0, $this->requestsCounted($user), 'no request is counted');
        $this->assertSame(0, $this->secondsCounted($user), 'no seconds are counted');
        $this->assertSame(0, (int) ApiKeyUsage::query()
            ->whereIn('api_key_id', ApiKey::query()->where('user_id', $user->id)->pluck('id'))
            ->sum('credits'), 'no key budget is used');
        $this->assertSame(0, AudioGeneration::query()->where('user_id', $user->id)->count(), 'no generation is recorded');
        $this->assertSame(0, $this->speechCalls->gemini + $this->speechCalls->google, 'no speech is requested');
    }
}
