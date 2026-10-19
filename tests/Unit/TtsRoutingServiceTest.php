<?php

namespace Tests\Unit;

use App\Contracts\TtsProviderInterface;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Services\Tts\GoogleCloudTtsProvider;
use App\Services\Tts\GeminiTtsProvider;
use App\Services\Tts\PremiumVoiceUnavailableException;
use App\Services\Tts\TtsRoutingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TtsRoutingServiceTest extends TestCase
{
    use RefreshDatabase;
    private TtsRoutingService $router;
    private TtsProviderInterface $google;
    private TtsProviderInterface $gemini;

    protected function setUp(): void
    {
        parent::setUp();

        // Create real provider instances — we're testing routing logic, not API calls.
        // isAvailable() may return false since there are no env keys, which is fine.
        $this->google = $this->createMock(GoogleCloudTtsProvider::class);
        $this->google->method('name')->willReturn('google_cloud');
        $this->google->method('isAvailable')->willReturn(true);
        $this->google->method('supportsDialogue')->willReturn(false);

        $this->gemini = $this->createMock(GeminiTtsProvider::class);
        $this->gemini->method('name')->willReturn('gemini');
        $this->gemini->method('isAvailable')->willReturn(true);
        $this->gemini->method('supportsDialogue')->willReturn(true);

        $this->router = new TtsRoutingService($this->google, $this->gemini);
    }

    // ─── resolveProvider – Legacy Mode ────────────────────────

    public function test_legacy_mode_always_returns_google(): void
    {
        $provider = $this->router->resolveProvider(TtsRoutingService::MODE_LEGACY, null);
        $this->assertSame('google_cloud', $provider->name());
    }

    public function test_legacy_mode_returns_google_for_paid_plan(): void
    {
        $plan = $this->makePlan('pro', 1999);
        $provider = $this->router->resolveProvider(TtsRoutingService::MODE_LEGACY, $plan);
        $this->assertSame('google_cloud', $provider->name());
    }

    // ─── resolveProvider – Hybrid Mode ────────────────────────

    public function test_hybrid_mode_free_plan_returns_google(): void
    {
        $plan = $this->makePlan('free', 0);
        $provider = $this->router->resolveProvider(TtsRoutingService::MODE_HYBRID, $plan);
        $this->assertSame('google_cloud', $provider->name());
    }

    public function test_hybrid_mode_null_plan_returns_google(): void
    {
        $provider = $this->router->resolveProvider(TtsRoutingService::MODE_HYBRID, null);
        $this->assertSame('google_cloud', $provider->name());
    }

    public function test_hybrid_mode_paid_plan_returns_gemini(): void
    {
        $plan = $this->makePlan('creator', 999);
        $provider = $this->router->resolveProvider(TtsRoutingService::MODE_HYBRID, $plan, ['tier' => 'hd']);
        $this->assertSame('gemini', $provider->name());
    }

    public function test_hybrid_mode_paid_plan_stays_on_gemini_when_gemini_unavailable(): void
    {
        $geminiUnavailable = $this->createMock(GeminiTtsProvider::class);
        $geminiUnavailable->method('name')->willReturn('gemini');
        $geminiUnavailable->method('isAvailable')->willReturn(false);
        $geminiUnavailable->expects($this->never())->method('synthesize');
        $this->google->expects($this->never())->method('synthesize');

        $router = new TtsRoutingService($this->google, $geminiUnavailable);
        $plan = $this->makePlan('pro', 1999);

        $provider = $router->resolveProvider(TtsRoutingService::MODE_HYBRID, $plan, ['tier' => 'hd']);
        $this->assertSame('gemini', $provider->name(), 'a paid HD request never moves to a standard voice');

        $this->expectException(PremiumVoiceUnavailableException::class);
        $router->synthesize('Hello there', 'en', ['tier' => 'hd'], $plan);
    }

    public function test_standard_tier_forces_google_even_for_paid_plan(): void
    {
        $plan = $this->makePlan('creator', 999);
        $provider = $this->router->resolveProvider(TtsRoutingService::MODE_HYBRID, $plan, ['tier' => 'standard']);
        $this->assertSame('google_cloud', $provider->name());
    }

    public function test_hybrid_hd_ignores_a_google_provider_hint(): void
    {
        $plan = $this->makePlan('creator', 999);
        $provider = $this->router->resolveProvider(TtsRoutingService::MODE_HYBRID, $plan, [
            'tier' => 'hd',
            'provider' => 'google_cloud',
        ]);

        $this->assertSame('gemini', $provider->name());
    }

    public function test_hybrid_hd_honors_gemini_provider_hint_when_available(): void
    {
        $plan = $this->makePlan('creator', 999);
        $provider = $this->router->resolveProvider(TtsRoutingService::MODE_HYBRID, $plan, [
            'tier' => 'hd',
            'provider' => 'gemini',
        ]);

        $this->assertSame('gemini', $provider->name());
    }

    public function test_hybrid_hd_gemini_provider_hint_stays_on_gemini_when_unavailable(): void
    {
        $geminiUnavailable = $this->createMock(GeminiTtsProvider::class);
        $geminiUnavailable->method('name')->willReturn('gemini');
        $geminiUnavailable->method('isAvailable')->willReturn(false);

        $router = new TtsRoutingService($this->google, $geminiUnavailable);
        $plan = $this->makePlan('creator', 999);

        $provider = $router->resolveProvider(TtsRoutingService::MODE_HYBRID, $plan, [
            'tier' => 'hd',
            'provider' => 'gemini',
        ]);

        $this->assertSame('gemini', $provider->name());
    }

    // ─── HD never falls back to a standard voice ──────────────

    public function test_hd_uses_gemini_in_every_mode_whatever_the_plan_policy_or_voice_hint(): void
    {
        $plan = $this->makePlan('starter', 900);
        $plan->tts_provider_policy = 'google_only';
        SiteSetting::set('tts_plan_provider_starter', 'google_cloud', 'tts');

        foreach ([TtsRoutingService::MODE_LEGACY, TtsRoutingService::MODE_GOOGLE, TtsRoutingService::MODE_HYBRID] as $mode) {
            foreach ([null, 'google_cloud', 'gemini'] as $hint) {
                $config = ['tier' => 'hd'] + ($hint ? ['provider' => $hint] : []);
                $this->assertSame('gemini', $this->router->resolveProvider($mode, $plan, $config)->name(), "{$mode} mode, hint {$hint}");
            }
            $this->assertSame('google_cloud', $this->router->resolveProvider($mode, $plan, ['tier' => 'standard', 'provider' => 'gemini'])->name(), 'standard stays on Google');
        }
    }

    public function test_hd_failure_throws_and_never_reads_the_text_with_a_standard_voice(): void
    {
        SiteSetting::set('tts_mode', TtsRoutingService::MODE_HYBRID, 'tts');
        SiteSetting::set('tts_fallback_enabled', '1', 'tts');
        $failure = new \RuntimeException('Gemini TTS request failed (HTTP 500): Internal error');
        $this->gemini->method('synthesize')->willThrowException($failure);
        $this->google->expects($this->never())->method('synthesize');

        try {
            $this->router->synthesize('Hello there', 'en', ['tier' => 'hd', 'voice_name' => 'Kore'], $this->makePlan('creator', 999), 7);
            $this->fail('An HD request whose engine fails must fail.');
        } catch (PremiumVoiceUnavailableException $e) {
            $this->assertSame($failure, $e->getPrevious());
            $this->assertStringContainsString('HTTP 500', $e->getMessage());
        }
    }

    public function test_standard_voice_result_says_no_backup_engine_was_used(): void
    {
        $this->google->method('synthesize')->willReturn(['audio_path' => 'audio/x.mp3', 'provider' => 'google_cloud']);
        $this->gemini->expects($this->never())->method('synthesize');

        $result = $this->router->synthesize('Hello there', 'en', ['tier' => 'standard'], $this->makePlan('free', 0));

        $this->assertSame('google_cloud', $result['provider']);
        $this->assertFalse($result['fallback_used']);
    }

    // ─── getProviderByName ────────────────────────────────────

    public function test_get_provider_by_name_returns_correct_provider(): void
    {
        $this->assertSame('gemini', $this->router->getProviderByName('gemini')->name());
        $this->assertSame('google_cloud', $this->router->getProviderByName('google_cloud')->name());
        // Unknown defaults to google
        $this->assertSame('google_cloud', $this->router->getProviderByName('unknown_provider')->name());
    }

    // ─── getAvailableProviders ────────────────────────────────

    public function test_available_providers_includes_both_when_configured(): void
    {
        $providers = $this->router->getAvailableProviders();

        $names = array_map(fn(TtsProviderInterface $p) => $p->name(), $providers);
        $this->assertContains('google_cloud', $names);
        $this->assertContains('gemini', $names);
    }

    public function test_available_providers_excludes_gemini_when_unavailable(): void
    {
        $geminiUnavailable = $this->createMock(GeminiTtsProvider::class);
        $geminiUnavailable->method('name')->willReturn('gemini');
        $geminiUnavailable->method('isAvailable')->willReturn(false);

        $router = new TtsRoutingService($this->google, $geminiUnavailable);
        $providers = $router->getAvailableProviders();

        $names = array_map(fn(TtsProviderInterface $p) => $p->name(), $providers);
        $this->assertContains('google_cloud', $names);
        $this->assertNotContains('gemini', $names);
    }

    // ─── Mode Constants ───────────────────────────────────────

    public function test_mode_constants_are_valid_strings(): void
    {
        $this->assertSame('legacy', TtsRoutingService::MODE_LEGACY);
        $this->assertSame('hybrid', TtsRoutingService::MODE_HYBRID);
    }

    // ─── Unknown Mode ─────────────────────────────────────────

    public function test_unknown_mode_defaults_to_google(): void
    {
        $provider = $this->router->resolveProvider('nonexistent_mode', null);
        $this->assertSame('google_cloud', $provider->name());
    }

    // ─── Helper ───────────────────────────────────────────────

    private function makePlan(string $slug, int $priceCents): Plan
    {
        $plan = new Plan();
        $plan->slug = $slug;
        $plan->price_cents = $priceCents;
        return $plan;
    }
}
