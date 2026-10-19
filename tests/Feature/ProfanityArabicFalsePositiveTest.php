<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use App\Models\Voice;
use App\Support\VoiceCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfanityArabicFalsePositiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_run_accepts_benign_arabic_text_without_false_positive_block(): void
    {
        $voice = $this->seedFreePlanAndVoice();
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->postJsonLocalizedRoute('demo.run', [
            'text' => 'أحب مشاهدة الحيوانات في الحديقة كل صباح.',
            'target_language' => 'ar',
            'voice_tier' => 'standard',
            'voice_key' => VoiceCatalog::keyFromId((int) $voice->id),
        ]);

        $response->assertOk();
        $response->assertJsonPath('ok', true);
    }

    public function test_demo_run_accepts_prices_numbers_and_words_the_old_list_flagged(): void
    {
        $voice = $this->seedFreePlanAndVoice();
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->postJsonLocalizedRoute('demo.run', [
            'text' => 'السعر 45$ والحرارة 455 درجة، والعقد مفسوخ، وهذا التصريح يخول حامله.',
            'target_language' => 'ar',
            'voice_tier' => 'standard',
            'voice_key' => VoiceCatalog::keyFromId((int) $voice->id),
        ]);

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $this->assertDatabaseCount('blocked_generations', 0);
    }

    public function test_demo_run_still_blocks_explicit_profanity(): void
    {
        $voice = $this->seedFreePlanAndVoice();
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->postJsonLocalizedRoute('demo.run', [
            'text' => 'هذا نص شرموطة غير مقبول',
            'target_language' => 'ar',
            'voice_tier' => 'standard',
            'voice_key' => VoiceCatalog::keyFromId((int) $voice->id),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('text');
        $response->assertJsonPath('errors.text.0', __('ui.backend.generation_text_prohibited'));
    }

    private function seedFreePlanAndVoice(): Voice
    {
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

        return Voice::query()->create([
            'language' => 'ar',
            'gender' => 'female',
            'tier' => 'standard',
            'display_name' => 'Mariam',
            'internal_voice_id' => 'ar-XA-Wavenet-B',
            'provider' => 'google_cloud',
            'provider_voice_id' => 'ar-XA-Wavenet-B',
            'is_active' => true,
        ]);
    }
}
