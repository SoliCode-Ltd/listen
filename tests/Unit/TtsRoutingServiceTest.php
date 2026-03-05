<?php

namespace Tests\Unit;

use App\Contracts\TtsProviderInterface;
use App\Models\Plan;
use App\Services\Tts\GoogleCloudTtsProvider;
use App\Services\Tts\GeminiTtsProvider;
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

    public function test_hybrid_mode_paid_plan_falls_back_to_google_when_gemini_unavailable(): void
    {
        $geminiUnavailable = $this->createMock(GeminiTtsProvider::class);
        $geminiUnavailable->method('name')->willReturn('gemini');
        $geminiUnavailable->method('isAvailable')->willReturn(false);

        $router = new TtsRoutingService($this->google, $geminiUnavailable);
        $plan = $this->makePlan('pro', 1999);

        $provider = $router->resolveProvider(TtsRoutingService::MODE_HYBRID, $plan, ['tier' => 'hd']);
        $this->assertSame('google_cloud', $provider->name());
    }

    public function test_standard_tier_forces_google_even_for_paid_plan(): void
    {
        $plan = $this->makePlan('creator', 999);
        $provider = $this->router->resolveProvider(TtsRoutingService::MODE_HYBRID, $plan, ['tier' => 'standard']);
        $this->assertSame('google_cloud', $provider->name());
    }

    public function test_hybrid_hd_honors_google_provider_hint(): void
    {
        $plan = $this->makePlan('creator', 999);
        $provider = $this->router->resolveProvider(TtsRoutingService::MODE_HYBRID, $plan, [
            'tier' => 'hd',
            'provider' => 'google_cloud',
        ]);

        $this->assertSame('google_cloud', $provider->name());
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

    public function test_hybrid_hd_gemini_provider_hint_falls_back_to_google_when_unavailable(): void
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

        $this->assertSame('google_cloud', $provider->name());
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
