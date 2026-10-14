<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\SiteSetting;
use App\Services\Tts\GeminiKeyManager;
use App\Services\Tts\GeminiTtsProvider;
use App\Services\Tts\TtsRoutingService;
use App\Services\TtsService;
use App\Support\GeminiTtsModel;
use App\Support\TtsStudioConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use ReflectionClass;
use Tests\TestCase;

class GeminiModelSwitchSafetyTest extends TestCase
{
    use RefreshDatabase;

    private const TRANSCRIPT_AR = 'النهاردة هبدأ من جديد';

    private const TRANSCRIPT_EN = 'I start again today.';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config([
            'services.google_tts.api_key' => 'test-google-key',
            'services.google_tts.endpoint' => 'https://texttospeech.googleapis.com/v1/text:synthesize',
        ]);

        $key = [
            'key' => 'test-gemini-key',
            'name' => 'GEMINI_TTS_API_KEY',
            'index' => 1,
        ];
        $manager = \Mockery::mock(GeminiKeyManager::class);
        $manager->shouldReceive('getAllKeys')->andReturn([$key]);
        $manager->shouldReceive('getAvailableKey')->andReturn($key);
        $manager->shouldReceive('recordUsage')->andReturnNull();
        $manager->shouldReceive('markKeyExhausted')->andReturnNull();
        $this->app->instance(GeminiKeyManager::class, $manager);
    }

    public function test_default_model_reuses_cache_and_a_new_model_generates_again(): void
    {
        $this->useHybridMode();
        $captured = $this->fakeBothProviders();
        $plan = $this->paidPlan();
        $options = $this->arabicOptions();

        $first = app(TtsService::class)->synthesizeAdvanced(self::TRANSCRIPT_AR, 'ar', $options, $plan);
        $second = app(TtsService::class)->synthesizeAdvanced(self::TRANSCRIPT_AR, 'ar', $options, $plan);

        $this->assertSame('gemini', $first['provider']);
        $this->assertSame(GeminiTtsModel::CURRENT, $first['model']);
        $this->assertSame('cache', $second['provider']);
        $this->assertSame(GeminiTtsModel::CURRENT, $second['model']);
        $this->assertCount(1, $captured->requests);
        $this->assertStringContainsString(self::TRANSCRIPT_AR, $captured->requests[0]['body']['contents'][0]['parts'][0]['text']);
        $this->assertStringContainsString('Cairene Egyptian', $captured->requests[0]['body']['contents'][0]['parts'][0]['text']);

        SiteSetting::set(GeminiTtsModel::SETTING_KEY, GeminiTtsModel::FLASH, 'tts');
        $third = app(TtsService::class)->synthesizeAdvanced(self::TRANSCRIPT_AR, 'ar', $options, $plan);

        $this->assertSame('gemini', $third['provider']);
        $this->assertSame(GeminiTtsModel::FLASH, $third['model']);
        $this->assertCount(2, $captured->requests);
        $this->assertStringContainsString('/models/'.GeminiTtsModel::FLASH.':generateContent', $captured->requests[1]['url']);
        $part = $captured->requests[1]['body']['contents'][0]['parts'][0];
        $this->assertSame(self::TRANSCRIPT_AR, $part['text']);
        $this->assertStringContainsString('Cairene Egyptian', $part['speech_metadata']['style']);
        $this->assertStringNotContainsString('Voice dialect', $part['text']);
    }

    public function test_lite_model_is_a_separate_cache_entry(): void
    {
        $this->useHybridMode();
        SiteSetting::set(GeminiTtsModel::SETTING_KEY, GeminiTtsModel::FLASH, 'tts');
        $captured = $this->fakeBothProviders();
        $plan = $this->paidPlan();
        $options = $this->arabicOptions();

        app(TtsService::class)->synthesizeAdvanced(self::TRANSCRIPT_AR, 'ar', $options, $plan);
        SiteSetting::set(GeminiTtsModel::SETTING_KEY, GeminiTtsModel::FLASH_LITE, 'tts');
        $lite = app(TtsService::class)->synthesizeAdvanced(self::TRANSCRIPT_AR, 'ar', $options, $plan);

        $this->assertSame(GeminiTtsModel::FLASH_LITE, $lite['model']);
        $this->assertSame('gemini', $lite['provider']);
        $this->assertCount(2, $captured->requests);
        $this->assertStringContainsString(GeminiTtsModel::FLASH_LITE, $captured->requests[1]['url']);
        $this->assertSame(self::TRANSCRIPT_AR, $captured->requests[1]['body']['contents'][0]['parts'][0]['text']);
    }

    public function test_arabic_custom_prompt_reaches_gemini_and_is_not_cached_as_the_built_style(): void
    {
        $this->useHybridMode();
        SiteSetting::set(GeminiTtsModel::SETTING_KEY, GeminiTtsModel::FLASH, 'tts');
        $captured = $this->fakeBothProviders();
        $plan = $this->paidPlan();
        $prompt = 'Speak like a Cairo street vendor talking to a friend.';

        app(TtsService::class)->synthesizeAdvanced(self::TRANSCRIPT_AR, 'ar', $this->arabicOptions(), $plan);
        $custom = app(TtsService::class)->synthesizeAdvanced(
            self::TRANSCRIPT_AR,
            'ar',
            $this->arabicOptions($prompt),
            $plan,
        );

        $this->assertSame('gemini', $custom['provider']);
        $this->assertCount(2, $captured->requests);
        $part = $captured->requests[1]['body']['contents'][0]['parts'][0];
        $this->assertSame(self::TRANSCRIPT_AR, $part['text']);
        $this->assertSame($prompt, $part['speech_metadata']['style']);
    }

    public function test_dialogue_cast_and_custom_prompt_change_the_request(): void
    {
        $this->useHybridMode();
        SiteSetting::set(GeminiTtsModel::SETTING_KEY, GeminiTtsModel::FLASH, 'tts');
        $captured = $this->fakeBothProviders();
        $plan = $this->paidPlan();
        $script = "Sara: إزيك\nWaleed: كويس";
        $base = $this->arabicOptions();
        $base['speakers'] = [
            ['name' => 'Sara', 'voice' => 'Kore'],
            ['name' => 'Waleed', 'voice' => 'Charon'],
        ];
        $otherCast = $base;
        $otherCast['speakers'][1]['voice'] = 'Puck';
        $prompted = $base;
        $prompted['style_prompt'] = 'Keep both voices warm and close.';

        app(TtsService::class)->synthesizeAdvanced($script, 'ar', $base, $plan);
        app(TtsService::class)->synthesizeAdvanced($script, 'ar', $otherCast, $plan);
        app(TtsService::class)->synthesizeAdvanced($script, 'ar', $prompted, $plan);
        $cached = app(TtsService::class)->synthesizeAdvanced($script, 'ar', $base, $plan);

        $this->assertSame('cache', $cached['provider']);
        $this->assertCount(3, $captured->requests);
        $this->assertSame('إزيك', $captured->requests[0]['body']['contents'][0]['parts'][0]['text']);
        $this->assertSame('كويس', $captured->requests[0]['body']['contents'][0]['parts'][1]['text']);
        $this->assertStringContainsString('Cairene Egyptian', $captured->requests[0]['body']['contents'][0]['parts'][0]['speech_metadata']['style']);
        $this->assertSame('Puck', $captured->requests[1]['body']['generationConfig']['speechConfig']['multiSpeakerVoiceConfig']['speakerVoiceConfigs'][1]['voiceConfig']['prebuiltVoiceConfig']['voiceName']);
        $this->assertSame('Keep both voices warm and close.', $captured->requests[2]['body']['contents'][0]['parts'][0]['speech_metadata']['style']);
        $this->assertStringNotContainsString('Sara:', $captured->requests[2]['body']['contents'][0]['parts'][0]['text']);
    }

    public function test_english_accent_stays_in_style_metadata_on_the_new_models(): void
    {
        $this->useHybridMode();
        $captured = $this->fakeBothProviders();
        $plan = $this->paidPlan();
        $options = [
            'voice_name' => 'Kore',
            'provider' => 'gemini',
            'tier' => 'hd',
            'dialect' => 'en-GB',
            'performance_style' => 'podcast',
            'emotion' => 'excited',
            'speed' => 1.4,
            'pitch' => 6,
        ];

        $current = app(TtsService::class)->synthesizeAdvanced(self::TRANSCRIPT_EN, 'en', $options, $plan);
        SiteSetting::set(GeminiTtsModel::SETTING_KEY, GeminiTtsModel::FLASH, 'tts');
        $flash = app(TtsService::class)->synthesizeAdvanced(self::TRANSCRIPT_EN, 'en', $options, $plan);
        SiteSetting::set(GeminiTtsModel::SETTING_KEY, GeminiTtsModel::FLASH_LITE, 'tts');
        $lite = app(TtsService::class)->synthesizeAdvanced(self::TRANSCRIPT_EN, 'en', $options, $plan);

        $this->assertSame(GeminiTtsModel::CURRENT, $current['model']);
        $this->assertSame('en-GB', $captured->requests[0]['body']['generationConfig']['speechConfig']['languageCode']);
        $this->assertStringContainsString(self::TRANSCRIPT_EN, $captured->requests[0]['body']['contents'][0]['parts'][0]['text']);
        $this->assertStringNotContainsString('Voice accent:', $captured->requests[0]['body']['contents'][0]['parts'][0]['text']);

        foreach ([1 => $flash, 2 => $lite] as $index => $result) {
            $part = $captured->requests[$index]['body']['contents'][0]['parts'][0];
            $this->assertSame('gemini', $result['provider']);
            $this->assertSame(self::TRANSCRIPT_EN, $part['text']);
            $this->assertStringContainsString('British English', $part['speech_metadata']['style']);
            $this->assertStringContainsString('faster pace', $part['speech_metadata']['style']);
            $this->assertStringContainsString('higher-pitched', $part['speech_metadata']['style']);
            $this->assertStringContainsString('Emotional tone:', $part['speech_metadata']['style']);
            $this->assertArrayNotHasKey('languageCode', $captured->requests[$index]['body']['generationConfig']['speechConfig']);
        }
    }

    public function test_standard_tier_and_google_mode_do_not_call_gemini(): void
    {
        $captured = $this->fakeBothProviders();
        $plan = $this->paidPlan();
        SiteSetting::set(GeminiTtsModel::SETTING_KEY, GeminiTtsModel::FLASH, 'tts');
        SiteSetting::set('tts_mode', TtsRoutingService::MODE_HYBRID, 'tts');

        $standard = app(TtsService::class)->synthesizeAdvanced(
            self::TRANSCRIPT_AR,
            'ar',
            $this->arabicOptions(null, 'standard'),
            $plan,
        );

        SiteSetting::set('tts_mode', TtsRoutingService::MODE_GOOGLE, 'tts');
        $googleMode = app(TtsService::class)->synthesizeAdvanced(
            'جملة أخرى تماما',
            'ar',
            $this->arabicOptions(),
            $plan,
        );

        $this->assertSame('google_cloud', $standard['provider']);
        $this->assertSame('google_cloud', $googleMode['provider']);
        $this->assertCount(2, $captured->requests);
        $this->assertStringContainsString('texttospeech.googleapis.com', $captured->requests[0]['url']);
        $this->assertStringContainsString('texttospeech.googleapis.com', $captured->requests[1]['url']);
    }

    public function test_gemini_fallback_audio_is_not_cached_as_the_selected_model(): void
    {
        $this->useHybridMode();
        SiteSetting::set(GeminiTtsModel::SETTING_KEY, GeminiTtsModel::FLASH, 'tts');
        $geminiCalls = 0;
        Http::fake(function ($request) use (&$geminiCalls) {
            if (str_contains($request->url(), 'generativelanguage')) {
                $geminiCalls++;
                if ($geminiCalls === 1) {
                    return Http::response(['error' => ['message' => 'rate limit']], 429);
                }

                return Http::response($this->geminiBody('audio/wav', $this->wavBytes()));
            }

            return Http::response(['audioContent' => base64_encode('google-audio')]);
        });

        $plan = $this->paidPlan();
        $options = $this->arabicOptions();
        $failedOver = app(TtsService::class)->synthesizeAdvanced(self::TRANSCRIPT_AR, 'ar', $options, $plan);
        $retried = app(TtsService::class)->synthesizeAdvanced(self::TRANSCRIPT_AR, 'ar', $options, $plan);

        $this->assertTrue($failedOver['fallback_used']);
        $this->assertSame('google_cloud', $failedOver['provider']);
        $this->assertFalse($retried['fallback_used']);
        $this->assertSame('gemini', $retried['provider']);
        $this->assertSame(GeminiTtsModel::FLASH, $retried['model']);
        $this->assertSame(2, $geminiCalls);
    }

    public function test_every_dialect_emotion_and_style_keeps_speech_and_instructions_apart(): void
    {
        $provider = app(GeminiTtsProvider::class);
        $reflection = new ReflectionClass($provider);
        $dialects = $reflection->getReflectionConstant('ARABIC_DIALECT_PROMPTS')->getValue();
        $styles = array_keys($reflection->getReflectionConstant('ARABIC_STYLE_HINTS')->getValue());
        $emotions = TtsStudioConfig::emotionValues();
        $buildStyle = $reflection->getMethod('buildStylePrompt');
        $buildPayload = $reflection->getMethod('buildSingleSpeakerPayload');
        $resolveLanguage = $reflection->getMethod('resolveLanguageCode');
        $buildStyle->setAccessible(true);
        $buildPayload->setAccessible(true);
        $resolveLanguage->setAccessible(true);

        foreach (TtsStudioConfig::dialectValues('ar') as $code) {
            $this->assertArrayHasKey($code, $dialects, $code.' is offered in the studio but has no Gemini dialect prompt.');
        }
        foreach (TtsStudioConfig::performanceStyleValues() as $style) {
            $this->assertContains($style, $styles, $style.' is offered in the studio but has no Gemini style.');
        }

        $failures = [];
        $models = [GeminiTtsModel::CURRENT, GeminiTtsModel::FLASH, GeminiTtsModel::FLASH_LITE];
        $cases = [];
        foreach ($dialects as $dialect => $dialectName) {
            $cases[] = ['ar', $dialect, $dialectName];
        }
        foreach (['en-US' => 'American English', 'en-GB' => 'British English', 'en-AU' => 'Australian English'] + array_map(fn ($row) => $row['prompt'], \App\Support\EnglishAccents::ALL) as $dialect => $dialectName) {
            $cases[] = ['en', $dialect, $dialectName];
        }

        foreach ($models as $model) {
            $structured = GeminiTtsModel::usesStructuredSpeech($model);
            foreach ($cases as [$language, $dialect, $dialectName]) {
                // Every emotion and every style with every dialect, paired in turn:
                // the prompt pieces are independent, so the full product adds nothing.
                $pairs = max(count($emotions), count($styles));
                for ($i = 0; $i < $pairs; $i++) {
                    $emotion = $emotions[$i % count($emotions)];
                    $style = $styles[$i % count($styles)];
                    $prompt = $buildStyle->invoke(
                        $provider,
                        $style,
                        $emotion,
                        $language,
                        1.0,
                        $dialect,
                        0.0,
                        $structured,
                    );
                    $languageCode = $resolveLanguage->invoke($provider, $language, $dialect);
                    $transcript = $language === 'ar' ? self::TRANSCRIPT_AR : self::TRANSCRIPT_EN;
                    $payload = $buildPayload->invoke($provider, $model, $transcript, $prompt, 'Kore', $languageCode);
                    $part = $payload['contents'][0]['parts'][0];
                    $spoken = (string) $part['text'];
                    $label = "{$model} {$language} {$dialect} {$emotion} {$style}";

                    if ($spoken === '' || ! str_contains($spoken, $transcript)) {
                        $failures[] = "{$label} dropped the transcript";
                    }
                    if (str_contains($spoken, 'speech_metadata')) {
                        $failures[] = "{$label} leaked metadata into speech";
                    }

                    if ($structured) {
                        if ($spoken !== $transcript) {
                            $failures[] = "{$label} would speak the instructions";
                        }
                        $styleText = (string) ($part['speech_metadata']['style'] ?? '');
                        if ($styleText === '') {
                            $failures[] = "{$label} has no style metadata";
                        }
                        if ($language === 'ar' && ! str_contains($styleText, $dialectName)) {
                            $failures[] = "{$label} lost the dialect name";
                        }
                        if ($dialect === 'ar-EG' && ! str_contains($styleText, 'Cairene Egyptian')) {
                            $failures[] = "{$label} lost the Egyptian lock";
                        } elseif ($language === 'ar' && ! str_starts_with(strtoupper($dialect), 'AR-XA') && $dialect !== 'ar-EG' && ! str_contains($styleText, 'Do not switch to Modern Standard Arabic')) {
                            $failures[] = "{$label} lost the colloquial lock";
                        }
                        // Fusha and its registers (news, classical, White Arabic).
                        if (str_starts_with(strtoupper($dialect), 'AR-XA') && str_contains($styleText, 'Do not switch to Modern Standard Arabic')) {
                            $failures[] = "{$label} pushed Fusha away from Fusha";
                        }
                        if ($language === 'en' && ! str_contains($styleText, $dialectName)) {
                            $failures[] = "{$label} lost the English accent";
                        }
                        if ($emotion !== 'neutral' && ! str_contains($styleText, 'Emotional tone:')) {
                            $failures[] = "{$label} lost the emotion";
                        }
                        if (isset($payload['generationConfig']['speechConfig']['languageCode'])) {
                            $failures[] = "{$label} sent languageCode to a structured model";
                        }
                    } else {
                        if (! str_contains($spoken, 'Voice dialect:') && $language === 'ar') {
                            $failures[] = "{$label} dropped the dialect from the legacy prompt";
                        }
                        if ($language === 'en' && str_contains($spoken, 'Voice accent:')) {
                            $failures[] = "{$label} changed the legacy English prompt";
                        }
                        $sentCode = $payload['generationConfig']['speechConfig']['languageCode'] ?? '';
                        if ($language === 'ar' && $sentCode !== 'ar-XA') {
                            $failures[] = "{$label} sent {$sentCode} instead of ar-XA";
                        }
                        $expectedCode = in_array($dialect, ['en-US', 'en-GB', 'en-AU', 'en-IN'], true) ? $dialect
                            : (in_array(substr($dialect, 0, 5), ['en-US', 'en-GB'], true) ? substr($dialect, 0, 5) : 'en-US');
                        if ($language === 'en' && $sentCode !== $expectedCode) {
                            $failures[] = "{$label} sent {$sentCode} instead of {$expectedCode}";
                        }
                    }

                    if (count($failures) > 12) {
                        break 3;
                    }
                }
            }
        }

        $this->assertSame([], $failures, implode("\n", $failures));
    }

    public function test_dialogue_above_two_speakers_falls_back_instead_of_failing_the_request(): void
    {
        $this->useHybridMode();
        SiteSetting::set(GeminiTtsModel::SETTING_KEY, GeminiTtsModel::FLASH, 'tts');
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'generativelanguage')) {
                return Http::response([
                    'error' => ['message' => 'speaker_voice_configs must equal 2'],
                ], 400);
            }

            return Http::response(['audioContent' => base64_encode('google-audio')]);
        });

        $options = $this->arabicOptions();
        $options['speakers'] = [
            ['name' => 'Sara', 'voice' => 'Kore'],
            ['name' => 'Waleed', 'voice' => 'Charon'],
            ['name' => 'Nour', 'voice' => 'Puck'],
        ];

        $result = app(TtsService::class)->synthesizeAdvanced(
            "Sara: إزيك\nWaleed: كويس\nNour: تمام",
            'ar',
            $options,
            $this->paidPlan(),
        );

        $this->assertTrue($result['fallback_used']);
        $this->assertSame('google_cloud', $result['provider']);
        $this->assertNotSame('', $result['audio_path']);
    }

    public function test_extreme_speed_and_pitch_stay_out_of_the_spoken_text(): void
    {
        $provider = app(GeminiTtsProvider::class);
        $reflection = new ReflectionClass($provider);
        $buildStyle = $reflection->getMethod('buildStylePrompt');
        $buildPayload = $reflection->getMethod('buildSingleSpeakerPayload');
        $buildStyle->setAccessible(true);
        $buildPayload->setAccessible(true);

        $extremes = [
            [0.5, 0.0, 'very slowly'],
            [0.7, 0.0, 'slow, measured'],
            [1.4, 0.0, 'faster pace'],
            [1.8, 0.0, 'very quickly'],
            [1.0, -12.0, 'very deep'],
            [1.0, -5.0, 'deeper, lower-pitched'],
            [1.0, 6.0, 'higher-pitched'],
            [1.0, 12.0, 'very high-pitched'],
        ];

        foreach ([GeminiTtsModel::FLASH, GeminiTtsModel::FLASH_LITE] as $model) {
            foreach ($extremes as [$speed, $pitch, $phrase]) {
                $prompt = $buildStyle->invoke($provider, 'sports_commentator', 'angry', 'ar', $speed, 'ar-MA', $pitch, true);
                $payload = $buildPayload->invoke($provider, $model, self::TRANSCRIPT_AR, $prompt, 'Kore', 'ar-XA');
                $part = $payload['contents'][0]['parts'][0];

                $this->assertSame(self::TRANSCRIPT_AR, $part['text'], $model);
                $this->assertStringContainsString($phrase, $part['speech_metadata']['style']);
                $this->assertStringContainsString('Moroccan Arabic', $part['speech_metadata']['style']);
                $this->assertStringContainsString('Do not switch to Modern Standard Arabic', $part['speech_metadata']['style']);
            }
        }
    }

    private function fakeBothProviders(): \stdClass
    {
        $captured = new \stdClass;
        $captured->requests = [];
        Http::fake(function ($request) use ($captured) {
            $captured->requests[] = [
                'url' => $request->url(),
                'body' => $request->data(),
            ];

            if (str_contains($request->url(), 'texttospeech')) {
                return Http::response(['audioContent' => base64_encode('google-audio')]);
            }

            $mime = str_contains($request->url(), GeminiTtsModel::CURRENT) ? 'audio/l16' : 'audio/wav';
            $bytes = $mime === 'audio/wav' ? $this->wavBytes() : $this->pcmBytes();

            return Http::response($this->geminiBody($mime, $bytes));
        });

        return $captured;
    }

    /**
     * @return array<string, mixed>
     */
    private function geminiBody(string $mime, string $bytes): array
    {
        return [
            'candidates' => [[
                'content' => [
                    'parts' => [[
                        'inlineData' => [
                            'mimeType' => $mime,
                            'data' => base64_encode($bytes),
                        ],
                    ]],
                ],
            ]],
        ];
    }

    private function useHybridMode(): void
    {
        SiteSetting::set('tts_mode', TtsRoutingService::MODE_HYBRID, 'tts');
        SiteSetting::set('tts_fallback_enabled', '1', 'tts');
    }

    private function paidPlan(): Plan
    {
        return Plan::factory()->create([
            'slug' => 'studio-safety',
            'price_cents' => 1999,
            'tts_provider_policy' => 'auto',
            'voice_tier' => 'hd',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function arabicOptions(?string $stylePrompt = null, string $tier = 'hd'): array
    {
        return [
            'voice_name' => 'Kore',
            'provider' => 'gemini',
            'tier' => $tier,
            'gender' => 'female',
            'dialect' => 'ar-EG',
            'performance_style' => 'cinematic',
            'emotion' => 'warm',
            'speed' => 0.9,
            'pitch' => -4,
            'style_prompt' => $stylePrompt,
        ];
    }

    private function pcmBytes(): string
    {
        return pack('v*', 0, 1200, 2400, 1200, 0, -1200, -2400, -1200);
    }

    private function wavBytes(): string
    {
        $pcm = $this->pcmBytes();
        $dataLength = strlen($pcm);

        return 'RIFF'
            .pack('V', 36 + $dataLength)
            .'WAVE'
            .'fmt '
            .pack('V', 16)
            .pack('v', 1)
            .pack('v', 1)
            .pack('V', 24000)
            .pack('V', 48000)
            .pack('v', 2)
            .pack('v', 16)
            .'data'
            .pack('V', $dataLength)
            .$pcm;
    }
}
