<?php

namespace Tests\Feature;

use App\Models\SiteSetting;
use App\Services\Tts\GeminiKeyManager;
use App\Services\Tts\GeminiTtsProvider;
use App\Services\VoiceCloningService;
use App\Support\ArabicDialects;
use App\Support\GeminiTtsModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A member's own direction (the custom prompt) used to replace the whole built
 * direction, so the dialect and speed they picked were lost. Their words now
 * lead, and the picked dialect (or accent) and speed follow them.
 */
class OwnDirectionTest extends TestCase
{
    use RefreshDatabase;

    private const OWN = 'Read it like a grandmother telling a bedtime story.';

    /** How 0.7x is read (the speed direction of the voice, not the member's words). */
    private const SLOW = 'Speak at a slow, measured pace';

    /** How 1.3x is read. */
    private const FAST = 'Speak at a faster pace with energetic delivery';

    /** @var array<int, array<string, mixed>> */
    private array $bodies = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        // The script is spoken as written: these tests look at the direction only.
        config(['services.gemini_tts.dialect_rewrite' => false]);
        $key = ['key' => 'test-gemini-key', 'name' => 'GEMINI_TTS_API_KEY', 'index' => 1];
        $manager = \Mockery::mock(GeminiKeyManager::class);
        $manager->shouldReceive('getAllKeys')->andReturn([$key]);
        $manager->shouldReceive('getAvailableKey')->andReturn($key);
        $manager->shouldReceive('recordUsage')->andReturnNull();
        $this->app->instance(GeminiKeyManager::class, $manager);

        $pcm = pack('v*', 0, 1200, -1200, 0);
        $wav = 'RIFF'.pack('V', 36 + strlen($pcm)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 24000, 48000, 2, 16).'data'.pack('V', strlen($pcm)).$pcm;
        Http::fake(function ($request) use ($wav) {
            $this->bodies[] = $request->data();

            return str_contains($request->url(), '/interactions')
                ? Http::response(['output_audio' => ['data' => base64_encode($wav)]])
                : Http::response(['candidates' => [['content' => ['parts' => [['inlineData' => ['mimeType' => 'audio/wav', 'data' => base64_encode($wav)]]]]]]]);
        });
    }

    public function test_own_words_lead_then_the_picked_dialect_and_speed_follow(): void
    {
        SiteSetting::set(GeminiTtsModel::SETTING_KEY, GeminiTtsModel::FLASH, 'tts');

        app(GeminiTtsProvider::class)->synthesize('كان يا ما كان في قديم الزمان.', 'ar', [
            'voice_name' => 'Kore',
            'style_prompt' => self::OWN,
            'dialect' => 'ar-SA',
            'speed' => 0.7,
            'pitch' => 5,
            'performance_style' => 'cinematic',
            'emotion' => 'warm',
        ]);

        $style = $this->bodies[0]['contents'][0]['parts'][0]['speech_metadata']['style'];
        $this->assertStringStartsWith(
            self::OWN.' Voice dialect: Saudi Arabic (Gulf dialect). Speak with authentic regional pronunciation and natural dialect characteristics. '
            .'Dialect markers to make clearly audible: '.ArabicDialects::markers('ar-SA'),
            $style,
        );
        $this->assertGreaterThan(strpos($style, 'Dialect markers'), strpos($style, self::SLOW), 'the dialect, then the speed');
        $this->assertStringContainsString('Stay in this regional colloquial variety. Do not switch to Modern Standard Arabic or news-anchor Fusha.', $style);
        // Style, emotion and pitch stay with the member's own words.
        $this->assertStringNotContainsString('Emotional tone', $style);
        $this->assertStringNotContainsString('cinematic', $style);
        $this->assertStringNotContainsString('higher-pitched', $style);
        $this->assertSame(1, substr_count($style, self::OWN));
    }

    public function test_an_english_accent_and_the_speed_follow_the_own_words(): void
    {
        SiteSetting::set(GeminiTtsModel::SETTING_KEY, GeminiTtsModel::FLASH, 'tts');

        app(GeminiTtsProvider::class)->synthesize('Once upon a time.', 'en', [
            'voice_name' => 'Kore', 'style_prompt' => self::OWN, 'dialect' => 'en-GB', 'speed' => 1.3,
        ]);

        $style = $this->bodies[0]['contents'][0]['parts'][0]['speech_metadata']['style'];
        $this->assertStringStartsWith(self::OWN.' '.self::FAST, $style);
        $this->assertStringEndsWith('Voice accent: British English. Keep this accent consistent and do not switch to a different English accent.', $style);
    }

    public function test_own_words_alone_when_nothing_else_was_picked(): void
    {
        SiteSetting::set(GeminiTtsModel::SETTING_KEY, GeminiTtsModel::FLASH, 'tts');

        app(GeminiTtsProvider::class)->synthesize('مرحبا بكم.', 'ar', ['voice_name' => 'Kore', 'style_prompt' => self::OWN]);

        $this->assertSame(self::OWN, $this->bodies[0]['contents'][0]['parts'][0]['speech_metadata']['style']);
    }

    public function test_each_dialogue_speaker_gets_the_own_words_then_their_dialect_and_the_speed(): void
    {
        SiteSetting::set(GeminiTtsModel::SETTING_KEY, GeminiTtsModel::FLASH, 'tts');

        app(GeminiTtsProvider::class)->synthesize("سلطان: هلا والله!\nنورة: أهلاً بيك.", 'ar', [
            'style_prompt' => self::OWN,
            'dialect' => 'ar-XA',
            'speed' => 0.7,
            'speakers' => [
                ['name' => 'سلطان', 'voice_name' => 'Puck', 'dialect' => 'ar-SA'],
                ['name' => 'نورة', 'voice_name' => 'Kore', 'dialect' => 'ar-EG'],
            ],
        ]);

        $parts = $this->bodies[0]['contents'][0]['parts'];
        [$saudi, $egyptian] = [$parts[0]['speech_metadata']['style'], $parts[1]['speech_metadata']['style']];
        foreach ([$saudi, $egyptian] as $style) {
            $this->assertStringStartsWith(self::OWN.' Voice dialect: ', $style);
            $this->assertStringContainsString(self::SLOW, $style);
        }
        $this->assertStringContainsString('Saudi Arabic (Gulf dialect)', $saudi);
        $this->assertStringContainsString('Cairene Egyptian', $egyptian);
        $this->assertLessThan(strpos($saudi, self::SLOW), strpos($saudi, 'Saudi Arabic'), 'the dialect, then the speed');
    }

    public function test_the_older_prompt_model_reads_the_own_words_first_too(): void
    {
        SiteSetting::set(GeminiTtsModel::SETTING_KEY, GeminiTtsModel::CURRENT, 'tts');

        app(GeminiTtsProvider::class)->synthesize('مرحبا بكم في البرنامج.', 'ar', [
            'voice_name' => 'Kore', 'style_prompt' => self::OWN, 'dialect' => 'ar-EG', 'speed' => 0.7,
        ]);

        $prompt = $this->bodies[0]['contents'][0]['parts'][0]['text'];
        $own = strpos($prompt, self::OWN);
        $dialect = strpos($prompt, 'Voice dialect: Egyptian Arabic.');
        $speed = strpos($prompt, self::SLOW);
        $this->assertNotFalse($own);
        $this->assertNotFalse($dialect);
        $this->assertNotFalse($speed);
        $this->assertLessThan($dialect, $own, 'their words lead');
        $this->assertLessThan($speed, $dialect);
        $this->assertStringContainsString('مرحبا بكم في البرنامج.', $prompt);
    }

    public function test_a_cloned_voice_reads_the_own_words_first_then_the_accent_and_speed(): void
    {
        config(['services.gemini_tts.api_key' => 'test-key', 'services.gemini_tts.voice_retry_pause' => false]);
        $service = app(VoiceCloningService::class);

        $style = $service->deliveryStyle('ar-SA', 'neutral', 'neutral', self::OWN, 0.7, 0.0);
        $lead = self::OWN.' '.ArabicDialects::cloneInstruction('ar-SA').' ';
        $this->assertStringStartsWith($lead, $style);
        $this->assertNotSame('', trim(substr($style, strlen($lead))), 'the speed follows');

        $service->synthesizeWithClonedVoice('هلا والله.', 'ar-XA', 'voice_x', $style);
        $this->assertSame($style, $this->bodies[0]['input'][0]['content'][0]['annotations'][0]['style']);
    }
}
