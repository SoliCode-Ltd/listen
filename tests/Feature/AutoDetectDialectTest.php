<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\SiteSetting;
use App\Services\Tts\GeminiKeyManager;
use App\Services\Tts\TtsRoutingService;
use App\Services\TtsService;
use App\Services\VoiceCloningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * "Auto-detect" (no dialect chosen) speaks the member's text as written: no
 * rewrite into formal Arabic and no Fusha direction, though the voice still
 * reads it as Arabic. Choosing Fusha keeps the rewrite. Speech is faked.
 */
class AutoDetectDialectTest extends TestCase
{
    use RefreshDatabase;

    private const COLLOQUIAL = 'أنا عايز أروح دلوقتي';

    /** @var array<int, array<string, mixed>> */
    private array $speech = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config(['services.gemini_tts.dialect_rewrite' => true]);
        SiteSetting::set('tts_mode', TtsRoutingService::MODE_HYBRID, 'tts');

        $key = ['key' => 'test-gemini-key', 'name' => 'GEMINI_TTS_API_KEY', 'index' => 1];
        $manager = \Mockery::mock(GeminiKeyManager::class);
        $manager->shouldReceive('getAllKeys')->andReturn([$key]);
        $manager->shouldReceive('getAvailableKey')->andReturn($key);
        $manager->shouldReceive('recordUsage')->andReturnNull();
        $manager->shouldReceive('markKeyExhausted')->andReturnNull();
        $this->app->instance(GeminiKeyManager::class, $manager);

        Http::fake(function ($request) {
            $this->speech[] = $request->data();

            return Http::response(['candidates' => [['content' => ['parts' => [[
                'inlineData' => ['mimeType' => 'audio/l16', 'data' => base64_encode(pack('v*', 0, 1200, -1200, 0))],
            ]]]]]]);
        });
    }

    public function test_auto_detect_speaks_the_text_as_written_without_fusha(): void
    {
        $cloning = $this->createMock(VoiceCloningService::class);
        $cloning->expects($this->never())->method('speakableText');
        $this->app->instance(VoiceCloningService::class, $cloning);

        app(TtsService::class)->synthesizeAdvanced(self::COLLOQUIAL, 'ar', $this->hdOptions(null), $this->plan());

        $this->assertCount(1, $this->speech, 'only the speech request, no rewrite');
        $part = $this->speech[0]['contents'][0]['parts'][0];
        $this->assertSame(self::COLLOQUIAL, $part['text'], 'the member\'s own words');
        $this->assertStringNotContainsString('Modern Standard Arabic', (string) ($part['speech_metadata']['style'] ?? ''));
        $this->assertStringNotContainsString('Fusha', (string) ($part['speech_metadata']['style'] ?? ''));
    }

    public function test_choosing_fusha_still_rewrites_colloquial_text_into_fusha(): void
    {
        $cloning = $this->createMock(VoiceCloningService::class);
        $cloning->expects($this->once())->method('speakableText')->with(self::COLLOQUIAL, 'ar-XA')->willReturn('أريد أن أذهب الآن');
        $this->app->instance(VoiceCloningService::class, $cloning);

        app(TtsService::class)->synthesizeAdvanced(self::COLLOQUIAL, 'ar', $this->hdOptions('ar-XA'), $this->plan());

        $part = $this->speech[0]['contents'][0]['parts'][0];
        $this->assertSame('أريد أن أذهب الآن', $part['text']);
        $this->assertStringContainsString('Modern Standard Arabic (Fusha)', $part['speech_metadata']['style']);
    }

    /**
     * What GenerateAudioJob sends for an HD voice (Auto-detect is a null dialect).
     *
     * @return array<string, mixed>
     */
    private function hdOptions(?string $dialect): array
    {
        return [
            'voice_name' => 'Kore',
            'provider' => 'gemini',
            'tier' => 'hd',
            'gender' => 'female',
            'performance_style' => 'neutral',
            'style' => 'neutral',
            'style_prompt' => null,
            'dialect' => $dialect,
            'emotion' => 'neutral',
            'speed' => 1.0,
            'pitch' => 0.0,
        ];
    }

    private function plan(): Plan
    {
        return Plan::factory()->create(['slug' => 'auto-detect', 'price_cents' => 1999, 'voice_tier' => 'hd']);
    }
}
