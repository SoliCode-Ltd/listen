<?php

namespace Tests\Feature;

use App\Contracts\TtsProviderInterface;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\Voice;
use App\Services\TranslationService;
use App\Services\Tts\TtsRoutingService;
use App\Services\TtsService;
use App\Support\VoiceCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoPremiumRoutingTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_preview_uses_gemini_for_premium_quality(): void
    {
        [$freePlan, $standardVoice, $premiumVoice] = $this->seedDemoRoutingFixtures();
        $user = \App\Models\User::factory()->create();
        $this->actingAs($user);

        $translation = $this->createMock(TranslationService::class);
        $translation->expects($this->once())
            ->method('maybeTranslate')
            ->willReturn('Hello world');

        $tts = $this->createMock(TtsService::class);
        $tts->expects($this->once())
            ->method('synthesizeAdvanced')
            ->with(
                'Hello world',
                'en',
                $this->callback(function (array $options) use ($premiumVoice): bool {
                    return ($options['provider'] ?? null) === 'gemini'
                        && ($options['voice_name'] ?? null) === $premiumVoice->internal_voice_id
                        && ($options['tier'] ?? null) === 'hd';
                }),
                $this->callback(function (?Plan $plan) use ($freePlan): bool {
                    return $plan instanceof Plan
                        && $plan->slug === $freePlan->slug
                        && strtolower(trim((string) $plan->tts_provider_policy)) === 'auto';
                }),
                null,
            )
            ->willReturn([
                'audio_path' => 'audio/demo-premium.wav',
                'duration_seconds' => 3,
                'provider' => 'gemini',
            ]);

        $this->app->instance(TranslationService::class, $translation);
        $this->app->instance(TtsService::class, $tts);

        $response = $this->postJsonLocalizedRoute('demo.preview', [
            'text' => 'Hello world',
            'target_language' => 'en',
            'translate' => false,
            'voice_tier' => 'hd',
            'voice_key' => VoiceCatalog::keyFromId((int) $premiumVoice->id),
            'performance_style' => 'promo',
            'emotion' => 'excited',
        ]);

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'voice_tier' => 'hd',
            ]);
    }

    public function test_demo_preview_uses_google_for_standard_quality(): void
    {
        [$freePlan, $standardVoice] = $this->seedDemoRoutingFixtures();
        $user = \App\Models\User::factory()->create();
        $this->actingAs($user);

        $translation = $this->createMock(TranslationService::class);
        $translation->expects($this->once())
            ->method('maybeTranslate')
            ->willReturn('Hello world');

        $tts = $this->createMock(TtsService::class);
        $tts->expects($this->once())
            ->method('synthesizeAdvanced')
            ->with(
                'Hello world',
                'en',
                $this->callback(function (array $options) use ($standardVoice): bool {
                    return ($options['provider'] ?? null) === 'google_cloud'
                        && ($options['voice_name'] ?? null) === $standardVoice->internal_voice_id
                        && ($options['tier'] ?? null) === 'standard';
                }),
                $this->callback(function (?Plan $plan) use ($freePlan): bool {
                    return $plan instanceof Plan
                        && $plan->slug === $freePlan->slug
                        && strtolower(trim((string) $plan->tts_provider_policy)) === 'google_cloud';
                }),
                null,
            )
            ->willReturn([
                'audio_path' => 'audio/demo-standard.mp3',
                'duration_seconds' => 3,
                'provider' => 'google_cloud',
            ]);

        $this->app->instance(TranslationService::class, $translation);
        $this->app->instance(TtsService::class, $tts);

        $response = $this->postJsonLocalizedRoute('demo.preview', [
            'text' => 'Hello world',
            'target_language' => 'en',
            'translate' => false,
            'voice_tier' => 'standard',
            'voice_key' => VoiceCatalog::keyFromId((int) $standardVoice->id),
        ]);

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'voice_tier' => 'standard',
            ]);
    }

    /**
     * @return array{0: Plan, 1: Voice, 2: Voice}
     */
    private function seedDemoRoutingFixtures(): array
    {
        SiteSetting::set('tts_mode', 'hybrid', 'tts');
        $this->bindDemoRoutingService();

        $freePlan = Plan::query()->create([
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

        $standardVoice = Voice::query()->create([
            'language' => 'en',
            'gender' => 'female',
            'tier' => 'standard',
            'display_name' => 'Emma',
            'internal_voice_id' => 'en-US-Wavenet-C',
            'provider' => 'google_cloud',
            'provider_voice_id' => 'en-US-Wavenet-C',
            'is_active' => true,
        ]);

        $premiumVoice = Voice::query()->create([
            'language' => 'en',
            'gender' => 'female',
            'tier' => 'hd',
            'display_name' => 'Serene',
            'internal_voice_id' => 'en-Gemini-HD-Kore',
            'provider' => 'gemini',
            'provider_voice_id' => 'Kore',
            'is_active' => true,
        ]);

        return [$freePlan, $standardVoice, $premiumVoice];
    }

    private function bindDemoRoutingService(): void
    {
        $googleProvider = $this->createMock(TtsProviderInterface::class);
        $googleProvider->method('name')->willReturn('google_cloud');
        $googleProvider->method('isAvailable')->willReturn(true);

        $geminiProvider = $this->createMock(TtsProviderInterface::class);
        $geminiProvider->method('name')->willReturn('gemini');
        $geminiProvider->method('isAvailable')->willReturn(true);

        $router = $this->createMock(TtsRoutingService::class);
        $router->method('getProviderByName')
            ->willReturnCallback(function (string $name) use ($googleProvider, $geminiProvider): TtsProviderInterface {
                return $name === 'gemini' ? $geminiProvider : $googleProvider;
            });

        $this->app->instance(TtsRoutingService::class, $router);
    }
}
