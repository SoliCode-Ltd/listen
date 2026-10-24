<?php

namespace Tests\Feature;

use App\Models\Voice;
use App\Services\Tts\GeminiTtsProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AbeerVoiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_abeer_speaks_with_the_lighter_zephyr_under_her_old_id(): void
    {
        $this->assertSame('Zephyr', $this->resolve('ar-Gemini-HD-Pulcherrima', 'female'));
        $this->assertSame('Zephyr', $this->resolve('Abeer', 'female'));
        // Nada and the English speakers keep Pulcherrima.
        $this->assertSame('Pulcherrima', $this->resolve('ar-XA-Chirp3-HD-Pulcherrima', 'female'));
        $this->assertSame('Pulcherrima', $this->resolve('en-Gemini-HD-Pulcherrima', 'female'));
    }

    public function test_the_migration_moves_only_abeers_row(): void
    {
        $abeer = Voice::create(['language' => 'ar', 'gender' => 'female', 'tier' => 'hd', 'display_name' => 'Abeer',
            'internal_voice_id' => 'ar-Gemini-HD-Pulcherrima', 'provider' => 'gemini', 'provider_voice_id' => 'Pulcherrima', 'is_active' => true]);
        $nada = Voice::create(['language' => 'ar', 'gender' => 'female', 'tier' => 'hd', 'display_name' => 'Nada',
            'internal_voice_id' => 'ar-XA-Chirp3-HD-Pulcherrima', 'provider' => 'gemini', 'provider_voice_id' => 'ar-XA-Chirp3-HD-Pulcherrima', 'is_active' => true]);

        (require database_path('migrations/2026_10_29_120000_give_abeer_a_lighter_voice.php'))->up();

        $this->assertSame('Zephyr', $abeer->fresh()->provider_voice_id);
        $this->assertSame('ar-Gemini-HD-Pulcherrima', $abeer->fresh()->internal_voice_id);
        $this->assertSame('ar-XA-Chirp3-HD-Pulcherrima', $nada->fresh()->provider_voice_id);
    }

    private function resolve(string $name, string $gender): string
    {
        return (new \ReflectionMethod(GeminiTtsProvider::class, 'resolveGeminiVoice'))->invoke(app(GeminiTtsProvider::class), $name, $gender);
    }
}
