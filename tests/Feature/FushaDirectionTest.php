<?php

namespace Tests\Feature;

use App\Services\Tts\GeminiKeyManager;
use App\Services\Tts\GeminiTtsProvider;
use App\Services\VoiceCloningService;
use App\Support\ArabicDialects;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Plain Fusha (Modern Standard Arabic, and its news register) used to be asked
 * for "with authentic regional pronunciation and natural dialect
 * characteristics", which pulls against formal Arabic. It is now asked for
 * with clear, formal pronunciation only; every regional dialect keeps its wording.
 */
class FushaDirectionTest extends TestCase
{
    use RefreshDatabase;

    private const FORMAL = 'Speak with clear, formal Modern Standard Arabic pronunciation.';

    private const REGIONAL = 'Speak with authentic regional pronunciation and natural dialect characteristics.';

    /** @var array<int, array<string, mixed>> */
    private array $bodies = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
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

            return Http::response(['candidates' => [['content' => ['parts' => [['inlineData' => ['mimeType' => 'audio/wav', 'data' => base64_encode($wav)]]]]]]]);
        });
    }

    public function test_fusha_is_asked_for_as_formal_arabic_only(): void
    {

        $style = $this->styleFor('ar-XA');

        $this->assertStringStartsWith('Voice dialect: Modern Standard Arabic (Fusha). '.self::FORMAL
            .' Pronunciation to make clearly audible: '.ArabicDialects::markers('ar-XA'), $style);
        foreach (['regional', 'dialect characteristics', 'Dialect markers', 'colloquial variety'] as $pull) {
            $this->assertStringNotContainsString($pull, $style, $pull);
        }

        // The news register is plain Fusha too.
        $news = $this->styleFor('ar-XA-NW');
        $this->assertStringContainsString('Voice dialect: Modern Standard Arabic in the style of a TV news bulletin. '.self::FORMAL, $news);
        $this->assertStringNotContainsString('regional', $news);
    }

    public function test_a_regional_dialect_keeps_its_regional_wording(): void
    {

        foreach (['ar-SA' => 'Saudi Arabic (Gulf dialect)', 'ar-EG' => 'Egyptian Arabic', 'ar-MA' => 'Moroccan Arabic (Maghrebi)'] as $code => $name) {
            $style = $this->styleFor($code);
            $this->assertStringStartsWith("Voice dialect: {$name}. ".self::REGIONAL.' Dialect markers to make clearly audible: ', $style, $code);
            $this->assertStringNotContainsString(self::FORMAL, $style, $code);
        }
    }

    public function test_in_a_dialogue_the_fusha_speaker_is_formal_and_the_other_regional(): void
    {

        app(GeminiTtsProvider::class)->synthesize("المذيع: مساء الخير.\nسلطان: هلا والله.", 'ar', [
            'speakers' => [
                ['name' => 'المذيع', 'voice_name' => 'Charon', 'dialect' => 'ar-XA'],
                ['name' => 'سلطان', 'voice_name' => 'Puck', 'dialect' => 'ar-SA'],
            ],
        ]);

        $parts = $this->bodies[0]['contents'][0]['parts'];
        $this->assertStringContainsString(self::FORMAL, $parts[0]['speech_metadata']['style']);
        $this->assertStringNotContainsString('regional', $parts[0]['speech_metadata']['style']);
        $this->assertStringContainsString(self::REGIONAL, $parts[1]['speech_metadata']['style']);
    }

    public function test_cloned_voices_already_ask_fusha_for_formal_pronunciation(): void
    {
        $style = app(VoiceCloningService::class)->deliveryStyle('ar-XA');

        $this->assertStringContainsString('Modern Standard Arabic (Fusha) with clear formal pronunciation', $style);
        $this->assertStringNotContainsString('regional', $style);
    }

    private function styleFor(string $dialect): string
    {
        $this->bodies = [];
        app(GeminiTtsProvider::class)->synthesize('مرحبا بكم في البرنامج.', 'ar', [
            'voice_name' => 'Kore', 'dialect' => $dialect, 'performance_style' => 'neutral', 'emotion' => 'neutral',
        ]);

        return (string) $this->bodies[0]['contents'][0]['parts'][0]['speech_metadata']['style'];
    }
}
