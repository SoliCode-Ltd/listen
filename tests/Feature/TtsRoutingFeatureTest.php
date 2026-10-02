<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\SiteSetting;
use App\Services\Tts\GeminiTtsProvider;
use App\Services\Tts\GoogleCloudTtsProvider;
use App\Services\Tts\TtsRoutingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TtsRoutingFeatureTest extends TestCase
{
    use RefreshDatabase;

    private function makeRouter(
        bool $geminiAvailable = true,
    ): TtsRoutingService {
        $google = $this->createMock(GoogleCloudTtsProvider::class);
        $google->method('name')->willReturn('google_cloud');
        $google->method('isAvailable')->willReturn(true);

        $gemini = $this->createMock(GeminiTtsProvider::class);
        $gemini->method('name')->willReturn('gemini');
        $gemini->method('isAvailable')->willReturn($geminiAvailable);

        return new TtsRoutingService($google, $gemini);
    }

    // ─── Global Mode from SiteSetting ─────────────────────────

    public function test_global_mode_defaults_to_google(): void
    {
        $router = $this->makeRouter();
        $this->assertSame('google', $router->getGlobalMode());
    }

    public function test_global_mode_reads_from_site_settings(): void
    {
        SiteSetting::set('tts_mode', 'hybrid');
        SiteSetting::flushGroupCache('tts');

        $router = $this->makeRouter();
        $this->assertSame('hybrid', $router->getGlobalMode());
    }

    public function test_invalid_global_mode_falls_back_to_google(): void
    {
        SiteSetting::set('tts_mode', 'invalid_mode');

        $router = $this->makeRouter();
        $this->assertSame('google', $router->getGlobalMode());
    }

    // ─── shouldFallback ───────────────────────────────────────

    public function test_fallback_disabled_in_legacy_mode(): void
    {
        $router = $this->makeRouter();
        $this->assertFalse($router->shouldFallback('legacy'));
    }

    public function test_fallback_enabled_by_default_in_hybrid_mode(): void
    {
        $router = $this->makeRouter();
        $this->assertTrue($router->shouldFallback('hybrid'));
    }

    public function test_fallback_can_be_disabled_via_settings(): void
    {
        SiteSetting::set('tts_fallback_enabled', '0');

        $router = $this->makeRouter();
        $this->assertFalse($router->shouldFallback('hybrid'));
    }

    // ─── Plan Provider Override ───────────────────────────────

    public function test_plan_override_forces_specific_provider(): void
    {
        $plan = Plan::create([
            'slug' => 'test-plan',
            'name' => 'Test Plan',
            'price_cents' => 1000,
            'monthly_minutes' => 100,
            'monthly_chars' => 100000,
            'monthly_requests' => 100,
            'max_chars_per_request' => 5000,
        ]);

        // Override test-plan to always use google_cloud
        SiteSetting::set('tts_plan_provider_test-plan', 'google_cloud');

        $router = $this->makeRouter();
        $provider = $router->resolveProvider('hybrid', $plan);
        $this->assertSame('google_cloud', $provider->name());
    }

    public function test_plan_override_auto_means_no_override(): void
    {
        $plan = Plan::create([
            'slug' => 'auto-plan',
            'name' => 'Auto Plan',
            'price_cents' => 1000,
            'monthly_minutes' => 100,
            'monthly_chars' => 100000,
            'monthly_requests' => 100,
            'max_chars_per_request' => 5000,
        ]);

        SiteSetting::set('tts_plan_provider_auto-plan', 'auto');

        $router = $this->makeRouter();
        // Paid plan in hybrid mode without override → Gemini
        $provider = $router->resolveProvider('hybrid', $plan, ['tier' => 'hd']);
        $this->assertSame('gemini', $provider->name());
    }

    // ─── Hybrid Plan Routing with DB Plans ────────────────────

    public function test_hybrid_free_plan_from_db_routes_to_google(): void
    {
        $plan = Plan::create([
            'slug' => 'free',
            'name' => 'Free Plan',
            'price_cents' => 0,
            'monthly_minutes' => 10,
            'monthly_chars' => 5000,
            'monthly_requests' => 10,
            'max_chars_per_request' => 1000,
        ]);

        $router = $this->makeRouter();
        $provider = $router->resolveProvider('hybrid', $plan);
        $this->assertSame('google_cloud', $provider->name());
    }

    public function test_hybrid_paid_plan_from_db_routes_to_gemini(): void
    {
        $plan = Plan::create([
            'slug' => 'creator',
            'name' => 'Creator Plan',
            'price_cents' => 999,
            'monthly_minutes' => 100,
            'monthly_chars' => 100000,
            'monthly_requests' => 100,
            'max_chars_per_request' => 5000,
        ]);

        $router = $this->makeRouter();
        $provider = $router->resolveProvider('hybrid', $plan, ['tier' => 'hd']);
        $this->assertSame('gemini', $provider->name());
    }

    // ─── TTS Log / Telemetry Model ───────────────────────────

    public function test_tts_log_model_can_be_created(): void
    {
        $log = \App\Models\TtsLog::create([
            'provider' => 'gemini',
            'model' => 'gemini-2.5-flash-preview-tts',
            'routing_mode' => 'hybrid',
            'voice_name' => 'Kore',
            'language' => 'ar',
            'char_count' => 500,
            'latency_ms' => 1200,
            'cost_estimate_usd' => 0.000125,
            'fallback_used' => false,
            'status' => 'success',
        ]);

        $this->assertDatabaseHas('tts_logs', [
            'id' => $log->id,
            'provider' => 'gemini',
            'status' => 'success',
        ]);
    }

    // ─── Voice Sample Model ──────────────────────────────────

    public function test_voice_sample_model_can_be_created(): void
    {
        $sample = \App\Models\VoiceSample::create([
            'title' => 'Test Sample',
            'sample_text' => 'مرحبا بالعالم',
            'audio_path' => 'audio/test.mp3',
            'language' => 'ar',
            'dialect' => 'ar-SA',
            'emotion' => 'neutral',
            'category' => 'hero',
            'voice_name' => 'Kore',
            'provider' => 'gemini',
            'is_active' => true,
            'is_featured' => true,
            'sort_order' => 1,
        ]);

        $this->assertDatabaseHas('voice_samples', [
            'id' => $sample->id,
            'title' => 'Test Sample',
            'category' => 'hero',
        ]);
    }
}
