<?php

namespace Tests\Feature;

use App\Models\Voice;
use App\Models\SiteSetting;
use App\Support\VoiceCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VoiceCatalogDefaultVoicePreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_arabic_gemini_default_prefers_fahad_over_abdulaziz(): void
    {
        Voice::query()->create([
            'language' => 'ar',
            'gender' => 'male',
            'tier' => 'hd',
            'display_name' => 'Abdulaziz',
            'internal_voice_id' => 'ar-Gemini-HD-Orus',
            'provider' => 'gemini',
            'provider_voice_id' => 'Orus',
            'is_active' => true,
        ]);

        Voice::query()->create([
            'language' => 'ar',
            'gender' => 'male',
            'tier' => 'hd',
            'display_name' => 'Fahad',
            'internal_voice_id' => 'ar-Gemini-HD-Iapetus',
            'provider' => 'gemini',
            'provider_voice_id' => 'Iapetus',
            'is_active' => true,
        ]);

        $this->assertSame(
            'ar-Gemini-HD-Iapetus',
            VoiceCatalog::defaultForLanguageAndProvider('ar', 'hd', 'gemini')
        );

        $this->assertSame(
            'ar-Gemini-HD-Iapetus',
            VoiceCatalog::defaultForLanguageByProvider('ar', 'gemini', 'hd', 'male')
        );
    }

    public function test_arabic_gemini_default_falls_back_when_fahad_not_available(): void
    {
        Voice::query()->create([
            'language' => 'ar',
            'gender' => 'male',
            'tier' => 'hd',
            'display_name' => 'Abdulaziz',
            'internal_voice_id' => 'ar-Gemini-HD-Orus',
            'provider' => 'gemini',
            'provider_voice_id' => 'Orus',
            'is_active' => true,
        ]);

        $this->assertSame(
            'ar-Gemini-HD-Orus',
            VoiceCatalog::defaultForLanguageAndProvider('ar', 'hd', 'gemini')
        );
    }

    public function test_admin_default_voice_override_takes_priority_when_valid(): void
    {
        Voice::query()->create([
            'language' => 'ar',
            'gender' => 'male',
            'tier' => 'hd',
            'display_name' => 'Abdulaziz',
            'internal_voice_id' => 'ar-Gemini-HD-Orus',
            'provider' => 'gemini',
            'provider_voice_id' => 'Orus',
            'is_active' => true,
        ]);

        Voice::query()->create([
            'language' => 'ar',
            'gender' => 'male',
            'tier' => 'hd',
            'display_name' => 'Fahad',
            'internal_voice_id' => 'ar-Gemini-HD-Iapetus',
            'provider' => 'gemini',
            'provider_voice_id' => 'Iapetus',
            'is_active' => true,
        ]);

        SiteSetting::set('tts_default_voice_ar', 'ar-Gemini-HD-Orus', 'tts');

        $this->assertSame(
            'ar-Gemini-HD-Orus',
            VoiceCatalog::defaultForLanguageAndProvider('ar', 'hd', 'gemini')
        );
    }
}
