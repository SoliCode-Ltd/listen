<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\SiteSetting;
use App\Services\Tts\GeminiKeyManager;
use App\Services\Tts\TtsCostGuardrail;
use App\Services\Tts\TtsCostLimitException;
use App\Services\Tts\TtsRoutingService;
use App\Services\TtsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The site-wide daily speech ceilings (listen.cost_guardrails) stop a bug or
 * an abuse path from running up an unlimited bill in one day. Every request
 * here is faked: no real speech is generated.
 */
class TtsCostGuardrailTest extends TestCase
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
            'services.gemini_tts.dialect_rewrite' => false,
            'listen.cost_guardrails.daily_gemini_char_limit' => 0,
            'listen.cost_guardrails.daily_total_char_limit' => 0,
            'listen.cost_guardrails.daily_gemini_request_limit' => 0,
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

    public function test_gemini_is_used_while_under_every_limit(): void
    {
        config(['listen.cost_guardrails.daily_gemini_char_limit' => 1000]);
        $calls = $this->fakeSpeech();

        $result = $this->speak('Hello there');

        $this->assertSame('gemini', $result['provider']);
        $this->assertSame(1, $calls->gemini);
        $this->assertSame(0, $calls->google);
        $this->assertSame(11, app(TtsCostGuardrail::class)->usageToday()['gemini_chars']);
        $this->assertSame(1, app(TtsCostGuardrail::class)->usageToday()['gemini_requests']);
        $this->assertSame(11, app(TtsCostGuardrail::class)->usageToday()['total_chars']);
    }

    public function test_over_the_gemini_char_limit_falls_back_to_google_without_calling_gemini(): void
    {
        config(['listen.cost_guardrails.daily_gemini_char_limit' => 5]);
        $calls = $this->fakeSpeech();

        $result = $this->speak('Hello there');

        $this->assertSame('google_cloud', $result['provider']);
        $this->assertTrue($result['fallback_used']);
        $this->assertSame(0, $calls->gemini, 'Gemini must not be called once over its limit');
        $this->assertSame(1, $calls->google);
        $this->assertSame(0, app(TtsCostGuardrail::class)->usageToday()['gemini_chars']);
        $this->assertSame(11, app(TtsCostGuardrail::class)->usageToday()['total_chars']);
    }

    public function test_gemini_char_limit_counts_up_across_requests(): void
    {
        config(['listen.cost_guardrails.daily_gemini_char_limit' => 25]);
        $calls = $this->fakeSpeech();

        $this->assertSame('gemini', $this->speak('Hello there')['provider']);
        $this->assertSame('gemini', $this->speak('Hello again')['provider']);
        // 22 used; 11 more would pass 25.
        $this->assertSame('google_cloud', $this->speak('Hello third')['provider']);

        $this->assertSame(2, $calls->gemini);
        $this->assertSame(1, $calls->google);
    }

    public function test_gemini_request_limit_stops_gemini_after_that_many_requests(): void
    {
        config(['listen.cost_guardrails.daily_gemini_request_limit' => 1]);
        $calls = $this->fakeSpeech();

        $this->assertSame('gemini', $this->speak('First one')['provider']);
        $this->assertSame('google_cloud', $this->speak('Second one')['provider']);

        $this->assertSame(1, $calls->gemini);
    }

    public function test_total_limit_refuses_all_speech_and_calls_no_provider(): void
    {
        config(['listen.cost_guardrails.daily_total_char_limit' => 5]);
        $calls = $this->fakeSpeech();

        try {
            $this->speak('Hello there');
            $this->fail('Speech over the total daily limit must be refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('daily_total_char_limit', $e->getMessage());
        }

        $this->assertSame(0, $calls->gemini);
        $this->assertSame(0, $calls->google);
    }

    public function test_total_limit_also_guards_google_directly(): void
    {
        config(['listen.cost_guardrails.daily_total_char_limit' => 5]);
        $calls = $this->fakeSpeech();

        $this->expectException(TtsCostLimitException::class);

        try {
            app(\App\Services\Tts\GoogleCloudTtsProvider::class)->synthesize('Hello there', 'en', ['voice_name' => 'en-US-Wavenet-C']);
        } finally {
            $this->assertSame(0, $calls->google);
        }
    }

    public function test_google_usage_counts_toward_the_total_but_not_gemini(): void
    {
        $this->fakeSpeech();

        app(\App\Services\Tts\GoogleCloudTtsProvider::class)->synthesize('Hello there', 'en', ['voice_name' => 'en-US-Wavenet-C']);

        $usage = app(TtsCostGuardrail::class)->usageToday();
        $this->assertSame(11, $usage['total_chars']);
        $this->assertSame(0, $usage['gemini_chars']);
        $this->assertSame(0, $usage['gemini_requests']);
    }

    public function test_a_zero_limit_is_switched_off(): void
    {
        $calls = $this->fakeSpeech();

        for ($i = 0; $i < 3; $i++) {
            $this->assertSame('gemini', $this->speak("Message number {$i}")['provider']);
        }

        $this->assertSame(3, $calls->gemini);
    }

    public function test_failed_speech_is_not_counted(): void
    {
        config(['listen.cost_guardrails.daily_gemini_char_limit' => 1000]);
        Http::fake(fn () => Http::response(['error' => ['message' => 'bad request']], 400));

        try {
            $this->speak('Hello there');
        } catch (\RuntimeException) {
        }

        $usage = app(TtsCostGuardrail::class)->usageToday();
        $this->assertSame(0, $usage['gemini_chars']);
        $this->assertSame(0, $usage['total_chars']);
    }

    public function test_limits_start_again_the_next_day(): void
    {
        config(['listen.cost_guardrails.daily_gemini_char_limit' => 15]);
        $calls = $this->fakeSpeech();

        $this->assertSame('gemini', $this->speak('Hello there')['provider']);
        $this->assertSame('google_cloud', $this->speak('Hello again')['provider']);

        $this->travel(1)->days();

        $this->assertSame('gemini', $this->speak('Hello third')['provider']);
        $this->assertSame(2, $calls->gemini);
    }

    private function speak(string $text): array
    {
        return app(TtsService::class)->synthesizeAdvanced($text, 'en', [
            'voice_name' => 'Kore',
            'provider' => 'gemini',
            'tier' => 'hd',
            'gender' => 'female',
            'performance_style' => 'neutral',
            'emotion' => 'neutral',
        ], $this->geminiPlan());
    }

    private function geminiPlan(): Plan
    {
        return Plan::query()->where('slug', 'guardrail-gemini')->first() ?? Plan::factory()->create([
            'slug' => 'guardrail-gemini',
            'price_cents' => 900,
            'tts_provider_policy' => 'gemini',
            'voice_tier' => 'hd',
            'max_chars_per_request' => 5000,
        ]);
    }

    private function fakeSpeech(): \stdClass
    {
        $calls = new \stdClass;
        $calls->gemini = 0;
        $calls->google = 0;

        Http::fake(function ($request) use ($calls) {
            if (str_contains($request->url(), 'texttospeech')) {
                $calls->google++;

                return Http::response(['audioContent' => base64_encode('google-audio')]);
            }

            $calls->gemini++;

            return Http::response([
                'candidates' => [[
                    'content' => ['parts' => [[
                        'inlineData' => ['mimeType' => 'audio/l16', 'data' => base64_encode(pack('v*', 0, 1200, -1200, 0))],
                    ]]],
                ]],
            ]);
        });

        return $calls;
    }
}
