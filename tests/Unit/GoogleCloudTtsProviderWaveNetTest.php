<?php

namespace Tests\Unit;

use App\Models\Voice;
use App\Services\Tts\GoogleCloudTtsProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

class GoogleCloudTtsProviderWaveNetTest extends TestCase
{
    use RefreshDatabase;

    public function test_standard_tier_fallback_uses_wavenet_variant_from_catalog(): void
    {
        Voice::query()->create([
            'language' => 'en',
            'gender' => 'female',
            'tier' => 'standard',
            'display_name' => 'Emma',
            'internal_voice_id' => 'en-us-standard-emma',
            'provider' => 'google_cloud',
            'provider_voice_id' => 'en-US-Standard-C',
            'is_active' => true,
        ]);

        $resolved = $this->invokeResolveGoogleVoiceName('', 'en', 'standard', 'female');

        $this->assertSame('en-US-Wavenet-C', $resolved);
    }

    public function test_standard_tier_without_catalog_falls_back_to_stable_wavenet_default(): void
    {
        $resolved = $this->invokeResolveGoogleVoiceName('', 'ar', 'standard', 'female');

        $this->assertSame('ar-XA-Wavenet-A', $resolved);
    }

    public function test_hd_tier_keeps_non_standard_voice_selection_unchanged(): void
    {
        $resolved = $this->invokeResolveGoogleVoiceName('en-US-Neural2-F', 'en', 'hd', 'female');

        $this->assertSame('en-US-Neural2-F', $resolved);
    }

    private function invokeResolveGoogleVoiceName(string $voiceName, string $language, string $tier, string $gender): string
    {
        $provider = new GoogleCloudTtsProvider();
        $reflection = new ReflectionClass(GoogleCloudTtsProvider::class);
        $method = $reflection->getMethod('resolveGoogleVoiceName');
        $method->setAccessible(true);

        return (string) $method->invoke($provider, $voiceName, $language, $tier, $gender);
    }
}
