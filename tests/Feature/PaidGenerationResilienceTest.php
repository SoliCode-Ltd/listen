<?php

namespace Tests\Feature;

use App\Models\AudioGeneration;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\TtsLog;
use App\Models\User;
use App\Services\Tts\GeminiKeyManager;
use App\Services\Tts\GoogleCloudTtsProvider;
use App\Services\Tts\PremiumVoiceUnavailableException;
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

    public function test_gemini_server_error_is_retried(): void
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

    public function test_a_reply_cut_off_at_the_output_limit_is_asked_for_again_not_delivered(): void
    {
        $cutOff = Http::response(['candidates' => [[
            'finishReason' => 'MAX_TOKENS',
            'content' => ['parts' => [['inlineData' => ['mimeType' => 'audio/l16', 'data' => base64_encode(pack('v*', 0, 600, -600, 0))]]]],
        ]]]);
        $calls = $this->fakeGemini([$cutOff, $this->geminiAudio()]);

        $result = app(TtsService::class)->synthesizeAdvanced('Hello there', 'en', $this->hdOptions(), $this->starterPlan());

        $this->assertSame('gemini', $result['provider']);
        $this->assertSame(2, $calls->gemini, 'the cut-off take was not kept');
    }

    public function test_slow_reply_without_audio_fails_without_retrying(): void
    {
        config(['services.gemini_tts.retry_budget_seconds' => 0]);
        $calls = $this->fakeGemini([
            Http::response(['candidates' => [['finishReason' => 'OTHER', 'content' => ['parts' => [['text' => '']]]]]]),
        ]);

        $this->assertPremiumFails(fn () => app(TtsService::class)->synthesizeAdvanced('Hello there', 'en', $this->hdOptions(), $this->starterPlan()));

        $this->assertSame(1, $calls->gemini);
        $this->assertSame(0, $calls->google, 'never read by a standard voice instead');
    }

    public function test_bad_request_is_not_retried(): void
    {
        $calls = $this->fakeGemini([Http::response(['error' => ['message' => 'bad']], 400)]);

        $this->assertPremiumFails(fn () => app(TtsService::class)->synthesizeAdvanced('Hello there', 'en', $this->hdOptions(), $this->starterPlan()));

        $this->assertSame(1, $calls->gemini);
        $this->assertSame(0, $calls->google);
    }

    public function test_gemini_down_fails_an_hd_request_after_its_retries_without_google(): void
    {
        $calls = $this->fakeGemini([Http::response(['error' => ['message' => 'down']], 503)]);

        $error = $this->assertPremiumFails(fn () => app(TtsService::class)->synthesizeAdvanced('مرحبا بكم في المنصة', 'ar', $this->hdOptions(), $this->starterPlan()));

        $this->assertStringContainsString('HTTP 503', $error->getMessage());
        $this->assertSame(3, $calls->gemini, 'the first try and two retries');
        $this->assertSame(0, $calls->google);
    }

    public function test_long_arabic_standard_voice_is_split_under_googles_byte_limit(): void
    {
        $calls = $this->fakeGemini([Http::response(['error' => ['message' => 'down']], 503)]);
        $text = trim(str_repeat('هذا نص عربي طويل لاختبار حد البايتات في جوجل. ', 100));
        $this->assertGreaterThan(5000, strlen($text));
        $this->assertLessThanOrEqual(5000, mb_strlen($text));

        $result = app(TtsService::class)->synthesizeAdvanced($text, 'ar', $this->standardOptions(), $this->starterPlan());

        $this->assertSame('google_cloud', $result['provider']);
        $this->assertFalse($result['fallback_used']);
        $this->assertSame(0, $calls->gemini);
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

    public function test_the_gemini_error_is_kept_when_an_hd_request_fails(): void
    {
        $googleCalls = 0;
        Http::fake(function ($request) use (&$googleCalls) {
            if (str_contains($request->url(), 'texttospeech')) {
                $googleCalls++;

                return Http::response(['error' => ['message' => 'quota exceeded']], 429);
            }

            return Http::response(['error' => ['message' => 'Internal error']], 500);
        });

        $error = $this->assertPremiumFails(fn () => app(TtsService::class)->synthesizeAdvanced('Hello there', 'en', $this->hdOptions(), $this->starterPlan()));

        $this->assertStringContainsString('HTTP 500', $error->getMessage());
        $this->assertStringContainsString('Internal error', $error->getPrevious()?->getMessage() ?? '');
        $this->assertSame(0, $googleCalls);
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

    public function test_unpunctuated_text_reaches_google_without_overlong_sentences(): void
    {
        $calls = $this->fakeGemini([
            Http::response(['candidates' => [['finishReason' => 'OTHER', 'content' => ['parts' => [['text' => '']]]]]]),
        ]);
        $text = 'سالفة '.trim(str_repeat('كان يا ما كان في قديم الزمان رجل طيب، ', 60));

        $result = app(TtsService::class)->synthesizeAdvanced($text, 'ar', $this->standardOptions(), $this->starterPlan());

        $this->assertSame('google_cloud', $result['provider']);
        $sent = implode(' ', $calls->googleTexts);
        foreach (preg_split('/(?<=[.!?؟…])\s+/u', $sent) as $sentence) {
            $this->assertLessThanOrEqual(GoogleCloudTtsProvider::MAX_SENTENCE_CHARS, mb_strlen($sentence));
        }
        $wordsOnly = fn (string $s) => preg_split('/\s+/u', trim(preg_replace('/[.،,]/u', '', $s)));
        $this->assertSame($wordsOnly($text), $wordsOnly($sent));
    }

    public function test_long_sentences_break_at_a_comma_and_short_ones_are_untouched(): void
    {
        $this->assertSame('Short one. Another.', GoogleCloudTtsProvider::breakLongSentences('Short one. Another.'));

        $broken = GoogleCloudTtsProvider::breakLongSentences('aaa bbb, ccc ddd eee fff', 16);

        $this->assertSame('aaa bbb. ccc ddd eee fff.', $broken);
        $this->assertSame("line one.\nline two.", GoogleCloudTtsProvider::breakLongSentences("line one\nline two"));
        $this->assertSame("asked? yes.\ndone.", GoogleCloudTtsProvider::breakLongSentences("asked? yes،\ndone."));
    }

    public function test_story_split_only_by_line_breaks_reaches_google_as_short_sentences(): void
    {
        $calls = $this->fakeGemini([Http::response(['error' => ['message' => 'down']], 503)]);
        $line = trim(str_repeat('كلام عراقي من القلب ', 4));
        $text = 'سالفة '.implode("\n", array_fill(0, 28, $line));

        $result = app(TtsService::class)->synthesizeAdvanced($text, 'ar', $this->standardOptions(), $this->starterPlan());

        $this->assertSame('google_cloud', $result['provider']);
        $this->assertGreaterThan(1, count($calls->googleTexts));
        foreach ($calls->googleTexts as $chunk) {
            $this->assertLessThanOrEqual(GoogleCloudTtsProvider::MAX_INPUT_BYTES, strlen($chunk));
            foreach (preg_split('/(?<=[.!?؟…])\s+/u', $chunk) as $sentence) {
                $this->assertLessThanOrEqual(GoogleCloudTtsProvider::MAX_SENTENCE_CHARS, mb_strlen($sentence));
            }
        }
    }

    public function test_google_api_key_is_sent_in_a_header_not_the_url(): void
    {
        $this->fakeGemini([Http::response(['error' => ['message' => 'down']], 503)]);

        app(TtsService::class)->synthesizeAdvanced('Hello there', 'en', $this->standardOptions(), $this->starterPlan());

        Http::assertSent(fn ($request) => str_contains($request->url(), 'texttospeech')
            && ! str_contains($request->url(), 'test-google-key')
            && $request->header('X-Goog-Api-Key') === ['test-google-key']);
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
    public function test_long_text_is_read_in_pieces_and_joined_into_one_wav(): void
    {
        config(['services.gemini_tts.model' => 'gemini-3.8-flash-tts', 'services.gemini_tts.max_chars_per_request' => 300]);
        $calls = $this->fakeGemini([$this->geminiAudio()]);
        $sentence = 'كان يا ما كان في قديم الزمان رجل طيب يسكن في بغداد';
        $text = implode("\n", array_fill(0, 20, $sentence));

        $result = app(TtsService::class)->synthesizeAdvanced($text, 'ar', $this->hdOptions(), $this->starterPlan());

        $this->assertSame('gemini', $result['provider']);
        $this->assertFalse($result['fallback_used']);
        $this->assertGreaterThan(1, $calls->gemini);
        $this->assertSame(0, $calls->google);
        foreach ($calls->geminiTexts as $piece) {
            $this->assertLessThanOrEqual(300, mb_strlen($piece));
        }
        $this->assertSame(preg_split('/\s+/u', $text), preg_split('/\s+/u', implode(' ', $calls->geminiTexts)));

        Storage::disk('public')->assertExists($result['audio_path']);
    }

    public function test_pieces_are_joined_into_one_wav_with_a_pause(): void
    {
        $provider = app(\App\Services\Tts\GeminiTtsProvider::class);
        $wrap = new \ReflectionMethod($provider, 'wrapPcmL16AsWav');
        $join = new \ReflectionMethod($provider, 'joinAudioParts');
        $first = pack('v*', 1, 2, 3);
        $second = pack('v*', 4, 5);

        $joined = $join->invoke($provider, [
            ['bytes' => $first, 'mime_type' => 'audio/L16;codec=pcm;rate=24000'],
            ['bytes' => $wrap->invoke($provider, $second, 24000, 1, 16), 'mime_type' => 'audio/wav'],
        ]);

        $pause = str_repeat("\0", 7200 * 2);
        $this->assertSame('audio/wav', $joined['mime_type']);
        $this->assertSame($wrap->invoke($provider, $first . $pause . $second, 24000, 1, 16), $joined['bytes']);
    }

    public function test_failed_piece_is_retried_on_its_own(): void
    {
        config(['services.gemini_tts.model' => 'gemini-3.8-flash-tts', 'services.gemini_tts.max_chars_per_request' => 300]);
        $calls = $this->fakeGemini([
            $this->geminiAudio(),
            Http::response(['candidates' => [['finishReason' => 'OTHER', 'content' => ['parts' => [['text' => '']]]]]]),
            $this->geminiAudio(),
        ]);
        $text = implode("\n", array_fill(0, 10, 'كان يا ما كان في قديم الزمان رجل طيب يسكن في بغداد'));

        $result = app(TtsService::class)->synthesizeAdvanced($text, 'ar', $this->hdOptions(), $this->starterPlan());

        $this->assertSame('gemini', $result['provider']);
        $this->assertSame(0, $calls->google);
        $this->assertSame(3, $calls->gemini);
        $this->assertSame($calls->geminiTexts[1], $calls->geminiTexts[2]);
    }

    public function test_a_slow_first_batch_does_not_use_up_a_later_pieces_retries(): void
    {
        config([
            'services.gemini_tts.model' => 'gemini-3.8-flash-tts',
            'services.gemini_tts.max_chars_per_request' => 200,
            'services.gemini_tts.max_parallel_requests' => 1,
            'services.gemini_tts.retry_budget_seconds' => 1,
        ]);
        $sent = ['alpha' => 0, 'bravo' => 0];
        Http::fake(function ($request) use (&$sent) {
            $text = (string) ($request->data()['contents'][0]['parts'][0]['text'] ?? '');
            $piece = str_contains($text, 'alpha') ? 'alpha' : 'bravo';
            $sent[$piece]++;
            if ($piece === 'alpha') {
                // The first batch is slow, past the whole retry budget.
                usleep(1_100_000);
            } elseif ($sent['bravo'] === 1) {
                return Http::response(['error' => ['message' => 'Internal error']], 500);
            }

            return $this->geminiAudio();
        });
        $text = trim(str_repeat('This is the alpha part of the story. ', 4))."\n".trim(str_repeat('This is the bravo part of the story. ', 4));

        $result = app(TtsService::class)->synthesizeAdvanced($text, 'en', $this->hdOptions(), $this->starterPlan());

        $this->assertSame('gemini', $result['provider']);
        $this->assertSame(['alpha' => 1, 'bravo' => 2], $sent, 'the second batch still got its retry');
    }

    private function fakeGemini(array $geminiResponses): \stdClass
    {
        $calls = new \stdClass;
        $calls->gemini = 0;
        $calls->google = 0;
        $calls->googleTexts = [];
        $calls->geminiTexts = [];

        Http::fake(function ($request) use ($calls, $geminiResponses) {
            if (str_contains($request->url(), 'texttospeech')) {
                $calls->google++;
                $calls->googleTexts[] = $request->data()['input']['text'];

                return Http::response(['audioContent' => base64_encode('google-audio')]);
            }

            $response = $geminiResponses[min($calls->gemini, count($geminiResponses) - 1)];
            $calls->gemini++;
            $calls->geminiTexts[] = $request->data()['contents'][0]['parts'][0]['text'] ?? '';

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

    /**
     * A Standard voice: read by Google Cloud directly.
     *
     * @return array<string, mixed>
     */
    private function standardOptions(): array
    {
        return [
            'tier' => 'standard',
            'gender' => 'female',
            'performance_style' => 'neutral',
            'emotion' => 'neutral',
        ];
    }

    /**
     * An HD request Gemini cannot make fails: it is never read by a standard voice instead.
     */
    private function assertPremiumFails(callable $synthesize): PremiumVoiceUnavailableException
    {
        try {
            $synthesize();
        } catch (PremiumVoiceUnavailableException $e) {
            return $e;
        }

        $this->fail('The HD request should have failed rather than use a standard voice.');
    }
}
