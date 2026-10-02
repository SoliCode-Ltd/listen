<?php

namespace Tests\Feature;

use App\Models\AudioGeneration;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\TtsLog;
use App\Models\User;
use App\Services\Tts\GeminiKeyManager;
use App\Services\Tts\GoogleCloudTtsProvider;
use App\Services\Tts\TtsRoutingService;
use App\Services\TtsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PaidGenerationResilienceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config([
            'services.google_tts.api_key' => 'test-google-key',
            'services.google_tts.endpoint' => 'https://texttospeech.googleapis.com/v1/text:synthesize',
            'services.google_tts.audio_encoding' => 'MP3',
            'services.gemini_tts.retry_pause_ms' => 0,
            'services.gemini_tts.transient_retries' => 2,
        ]);
        SiteSetting::set('tts_mode', TtsRoutingService::MODE_HYBRID, 'tts');
        SiteSetting::set('tts_fallback_enabled', '1', 'tts');

        $key = ['key' => 'test-gemini-key', 'name' => 'GEMINI_TTS_API_KEY', 'index' => 1];
        $manager = \Mockery::mock(GeminiKeyManager::class);
        $manager->shouldReceive('getAllKeys')->andReturn([$key]);
        $manager->shouldReceive('getAvailableKey')->andReturn($key);
        $manager->shouldReceive('recordUsage')->andReturnNull();
        $manager->shouldReceive('markKeyExhausted')->andReturnNull();
        $this->app->instance(GeminiKeyManager::class, $manager);
    }

    public function test_gemini_server_error_is_retried_before_falling_back(): void
    {
        $calls = $this->fakeGemini([
            Http::response(['error' => ['message' => 'Internal error']], 500),
            $this->geminiAudio(),
        ]);

        $result = app(TtsService::class)->synthesizeAdvanced('مرحبا بكم في المنصة', 'ar', $this->hdOptions(), $this->starterPlan());

        $this->assertSame('gemini', $result['provider']);
        $this->assertFalse($result['fallback_used']);
        $this->assertSame(2, $calls->gemini);
        $this->assertSame(0, $calls->google);
    }

    public function test_gemini_reply_without_audio_is_retried(): void
    {
        $calls = $this->fakeGemini([
            Http::response(['candidates' => [['finishReason' => 'OTHER', 'content' => ['parts' => [['text' => '']]]]]]),
            $this->geminiAudio(),
        ]);

        $result = app(TtsService::class)->synthesizeAdvanced('Hello there', 'en', $this->hdOptions(), $this->starterPlan());

        $this->assertSame('gemini', $result['provider']);
        $this->assertSame(2, $calls->gemini);
    }

    public function test_bad_request_is_not_retried(): void
    {
        $calls = $this->fakeGemini([Http::response(['error' => ['message' => 'bad']], 400)]);

        $result = app(TtsService::class)->synthesizeAdvanced('Hello there', 'en', $this->hdOptions(), $this->starterPlan());

        $this->assertTrue($result['fallback_used']);
        $this->assertSame(1, $calls->gemini);
    }

    public function test_long_arabic_fallback_is_split_under_googles_byte_limit(): void
    {
        $calls = $this->fakeGemini([Http::response(['error' => ['message' => 'down']], 503)]);
        $text = trim(str_repeat('هذا نص عربي طويل لاختبار حد البايتات في جوجل. ', 100));
        $this->assertGreaterThan(5000, strlen($text));
        $this->assertLessThanOrEqual(5000, mb_strlen($text));

        $result = app(TtsService::class)->synthesizeAdvanced($text, 'ar', $this->hdOptions(), $this->starterPlan());

        $this->assertSame('google_cloud', $result['provider']);
        $this->assertTrue($result['fallback_used']);
        $this->assertSame(3, $calls->gemini);
        $this->assertGreaterThan(1, count($calls->googleTexts));
        foreach ($calls->googleTexts as $chunk) {
            $this->assertLessThanOrEqual(5000, strlen($chunk));
        }
        $this->assertSame(
            preg_replace('/\s+/u', ' ', $text),
            implode(' ', $calls->googleTexts),
        );
        $this->assertSame(
            str_repeat('google-audio', count($calls->googleTexts)),
            Storage::disk('public')->get($result['audio_path']),
        );
    }

    public function test_both_errors_are_kept_when_fallback_also_fails(): void
    {
        Http::fake(function ($request) {
            return str_contains($request->url(), 'texttospeech')
                ? Http::response(['error' => ['message' => 'quota exceeded']], 429)
                : Http::response(['error' => ['message' => 'Internal error']], 500);
        });

        try {
            app(TtsService::class)->synthesizeAdvanced('Hello there', 'en', $this->hdOptions(), $this->starterPlan());
            $this->fail('Expected the synthesis to fail.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('HTTP 500', $e->getMessage());
            $this->assertStringContainsString('Google fallback failed', $e->getMessage());
            $this->assertStringContainsString('quota exceeded', $e->getMessage());
        }
    }

    public function test_split_keeps_every_word_and_respects_the_limit(): void
    {
        $text = 'One two. '.str_repeat('ب', 3000).' three four? five';

        $chunks = GoogleCloudTtsProvider::splitForByteLimit($text, 100);

        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(100, strlen($chunk));
            $this->assertTrue(mb_check_encoding($chunk, 'UTF-8'));
        }
        $this->assertSame(
            str_replace(' ', '', $text),
            str_replace(' ', '', implode('', $chunks)),
        );
        $this->assertSame(['short text'], GoogleCloudTtsProvider::splitForByteLimit('short text'));
    }

    public function test_key_limits_come_from_config_so_config_cache_keeps_them(): void
    {
        $this->app->forgetInstance(GeminiKeyManager::class);
        config([
            'services.gemini_tts.rpm_limit' => 900,
            'services.gemini_tts.tpm_limit' => 500000,
            'services.gemini_tts.rpd_limit' => 20000,
        ]);

        $stats = (new GeminiKeyManager)->getKeyStats('GEMINI_TTS_API_KEY');

        $this->assertSame(900, $stats['rpm_limit']);
        $this->assertSame(500000, $stats['tpm_limit']);
        $this->assertSame(20000, $stats['rpd_limit']);
    }

    public function test_diagnose_command_shows_plan_usage_and_real_failure_reason(): void
    {
        $this->app->forgetInstance(GeminiKeyManager::class);
        $user = User::factory()->create(['email' => 'paid@example.com']);
        $plan = $this->starterPlan();
        app(\App\Services\PlanAccessService::class)->assignPlan($user, $plan);
        $generation = AudioGeneration::create([
            'user_id' => $user->id,
            'input_text' => 'نص',
            'target_language' => 'ar',
            'voice_tier' => 'hd',
            'voice_name' => 'Kore',
            'status' => 'failed',
            'error_message' => 'Voice generation failed. Please try again.',
        ]);
        TtsLog::create([
            'user_id' => $user->id,
            'audio_generation_id' => $generation->id,
            'provider' => 'unknown',
            'routing_mode' => 'hybrid',
            'status' => 'failed',
            'failure_reason' => 'Gemini TTS request failed (HTTP 500)',
        ]);

        $this->artisan('listen:diagnose-user', ['user' => 'paid@example.com'])
            ->expectsOutputToContain('Plan used for generation: '.$plan->slug)
            ->expectsOutputToContain('Gemini TTS request failed (HTTP 500)')
            ->assertSuccessful();

        $this->artisan('listen:diagnose-user', ['user' => 'nobody@example.com'])->assertFailed();
    }

    /**
     * @param  array<int, mixed>  $geminiResponses  Played in order; the last one repeats.
     */
    private function fakeGemini(array $geminiResponses): \stdClass
    {
        $calls = new \stdClass;
        $calls->gemini = 0;
        $calls->google = 0;
        $calls->googleTexts = [];

        Http::fake(function ($request) use ($calls, $geminiResponses) {
            if (str_contains($request->url(), 'texttospeech')) {
                $calls->google++;
                $calls->googleTexts[] = $request->data()['input']['text'];

                return Http::response(['audioContent' => base64_encode('google-audio')]);
            }

            $response = $geminiResponses[min($calls->gemini, count($geminiResponses) - 1)];
            $calls->gemini++;

            return $response;
        });

        return $calls;
    }

    private function geminiAudio()
    {
        return Http::response([
            'candidates' => [[
                'content' => ['parts' => [[
                    'inlineData' => ['mimeType' => 'audio/l16', 'data' => base64_encode(pack('v*', 0, 1200, -1200, 0))],
                ]]],
            ]],
        ]);
    }

    private function starterPlan(): Plan
    {
        return Plan::factory()->create([
            'slug' => 'starter-resilience',
            'price_cents' => 900,
            'tts_provider_policy' => 'gemini',
            'voice_tier' => 'hd',
            'max_chars_per_request' => 5000,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function hdOptions(): array
    {
        return [
            'voice_name' => 'Kore',
            'provider' => 'gemini',
            'tier' => 'hd',
            'gender' => 'female',
            'performance_style' => 'neutral',
            'emotion' => 'neutral',
        ];
    }
}
