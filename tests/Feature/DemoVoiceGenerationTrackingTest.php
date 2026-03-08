<?php

namespace Tests\Feature;

use App\Models\DemoVoiceGeneration;
use App\Models\Plan;
use App\Models\Voice;
use App\Services\TranslationService;
use App\Services\TtsService;
use App\Support\UsageEstimator;
use App\Support\VoiceCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DemoVoiceGenerationTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_preview_persists_generation_for_admin_tracking(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('audio/demo-track.wav', 'demo-audio-binary');

        Plan::query()->create([
            'slug' => 'free',
            'name' => 'Free',
            'price_cents' => 0,
            'monthly_minutes' => 10,
            'monthly_chars' => 10000,
            'monthly_requests' => 60,
            'max_chars_per_request' => 1200,
            'voice_tier' => 'standard',
            'tts_provider_policy' => 'google_cloud',
            'dialogue_enabled' => false,
            'custom_prompt_enabled' => false,
            'is_active' => true,
        ]);

        $voice = Voice::query()->create([
            'language' => 'en',
            'gender' => 'female',
            'tier' => 'hd',
            'display_name' => 'Serene',
            'internal_voice_id' => 'en-Gemini-HD-Kore',
            'provider' => 'gemini',
            'provider_voice_id' => 'Kore',
            'is_active' => true,
        ]);

        $translation = $this->createMock(TranslationService::class);
        $translation->expects($this->once())
            ->method('maybeTranslate')
            ->willReturn('Translated demo text');

        $tts = $this->createMock(TtsService::class);
        $tts->expects($this->once())
            ->method('synthesizeAdvanced')
            ->willReturn([
                'audio_path' => 'audio/demo-track.wav',
                'duration_seconds' => 4,
                'provider' => 'gemini',
                'model' => 'gemini-2.5-flash-preview-tts',
                'latency_ms' => 842,
                'fallback_used' => false,
                'cost_estimate_usd' => 0.012345,
                'routing_mode' => 'hybrid',
                'plan_slug' => 'free',
            ]);

        $this->app->instance(TranslationService::class, $translation);
        $this->app->instance(TtsService::class, $tts);

        $response = $this->postJsonLocalizedRoute('demo.preview', [
            'text' => 'Hello world',
            'target_language' => 'en',
            'translate' => true,
            'voice_tier' => 'hd',
            'voice_key' => VoiceCatalog::keyFromId((int) $voice->id),
            'performance_style' => 'promo',
            'emotion' => 'excited',
            'style_prompt' => 'Energetic launch ad',
            'speed' => 1.15,
            'pitch' => 1.5,
            'fp_canvas' => 'canvas-demo-fingerprint',
        ]);

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'voice_tier' => 'hd',
            ]);

        $generation = DemoVoiceGeneration::query()->first();

        $this->assertNotNull($generation);
        $this->assertSame('Hello world', $generation->input_text);
        $this->assertSame('Translated demo text', $generation->speech_text);
        $this->assertSame('en', $generation->target_language);
        $this->assertTrue($generation->translate);
        $this->assertSame('gemini', $generation->voice_provider);
        $this->assertSame('hd', $generation->voice_tier);
        $this->assertStringStartsWith('audio/demo/', $generation->audio_path);
        Storage::disk('public')->assertExists($generation->audio_path);
        $this->assertSame(4, $generation->duration_seconds);
        $this->assertSame(UsageEstimator::estimate('Translated demo text')['chars'], $generation->billable_chars);
        $this->assertSame('promo', $generation->performance_style);
        $this->assertSame('excited', $generation->emotion);
        $this->assertNull($generation->style_prompt);
        $this->assertSame(1.15, $generation->speed);
        $this->assertSame(1.5, $generation->pitch);
        $this->assertSame('gemini-2.5-flash-preview-tts', $generation->tts_model);
        $this->assertSame(842, $generation->tts_latency_ms);
        $this->assertStringStartsWith('cv:', $generation->primary_identity_hash);
        $this->assertIsArray($generation->metadata);
        $this->assertSame('hybrid', $generation->metadata['routing_mode']);
        $this->assertContains($generation->primary_identity_hash, $generation->metadata['identities']);
    }
}
