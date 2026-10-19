<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\SiteSetting;
use App\Services\Tts\GeminiTtsProvider;
use App\Services\Tts\GoogleCloudTtsProvider;
use App\Services\Tts\PremiumVoiceUnavailableException;
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

    // ─── No fallback for HD ───────────────────────────────────

    public function test_hd_never_falls_back_whatever_the_old_fallback_setting_says(): void
    {
        SiteSetting::set('tts_mode', 'hybrid', 'tts');
        $plan = Plan::create([
            'slug' => 'paid-plan',
            'name' => 'Paid Plan',
            'price_cents' => 1000,
            'monthly_minutes' => 100,
            'monthly_chars' => 100000,
            'monthly_requests' => 100,
            'max_chars_per_request' => 5000,
        ]);

        foreach (['1', '0'] as $fallbackSetting) {
            SiteSetting::set('tts_fallback_enabled', $fallbackSetting, 'tts');
            $google = $this->createMock(GoogleCloudTtsProvider::class);
            $google->method('name')->willReturn('google_cloud');
            $google->expects($this->never())->method('synthesize');
            $gemini = $this->createMock(GeminiTtsProvider::class);
            $gemini->method('name')->willReturn('gemini');
            $gemini->method('isAvailable')->willReturn(true);
            $gemini->method('synthesize')->willThrowException(new \RuntimeException('Gemini TTS request failed (HTTP 503)'));

            try {
                (new TtsRoutingService($google, $gemini))->synthesize('Hello there', 'en', ['tier' => 'hd', 'voice_name' => 'Kore'], $plan);
                $this->fail('An HD request whose engine fails must fail.');
            } catch (PremiumVoiceUnavailableException $e) {
                $this->assertStringContainsString('HTTP 503', $e->getMessage(), "fallback setting {$fallbackSetting}");
            }
        }
    }

    // ─── Plan Provider Override ───────────────────────────────

    public function test_plan_override_no_longer_moves_hd_off_gemini(): void
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

        // An override saved before: test-plan "always uses google_cloud".
        SiteSetting::set('tts_plan_provider_test-plan', 'google_cloud');

        $router = $this->makeRouter();
        $this->assertSame('google_cloud', $router->resolveProvider('hybrid', $plan)->name(), 'standard voices stay on Google');
        $this->assertSame('gemini', $router->resolveProvider('hybrid', $plan, ['tier' => 'hd'])->name(), 'HD voices stay on Gemini');
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
