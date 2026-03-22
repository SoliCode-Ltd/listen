<?php

namespace Tests\Feature;

use App\Models\DemoVoiceGeneration;
use App\Models\Plan;
use App\Models\User;
use App\Models\Voice;
use App\Support\VoiceCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoTrialFingerprintPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_trial_is_user_based_only_even_when_fingerprints_match_other_users(): void
    {
        $voice = $this->seedFreePlanAndVoice();
        $existingUser = User::factory()->create();
        $newUser = User::factory()->create();

        $this->storeDemoUsage($existingUser, 260, 'cv:'.hash('sha256', 'shared-canvas'));

        $this->actingAs($newUser);

        $response = $this->postJsonLocalizedRoute('demo.run', [
            'text' => str_repeat('a', 50),
            'target_language' => 'en',
            'voice_tier' => 'standard',
            'voice_key' => VoiceCatalog::keyFromId((int) $voice->id),
            'fp_canvas' => 'shared-canvas',
            'fp_webgl' => 'shared-webgl',
        ]);

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $this->assertSame(0, (int) data_get($response->json(), 'quota.used_chars'));
    }

    public function test_demo_trial_exhaustion_is_based_on_the_same_user_history_only(): void
    {
        $voice = $this->seedFreePlanAndVoice();
        $user = User::factory()->create();

        $this->storeDemoUsage($user, 270, 'u:'.$user->id);

        $this->actingAs($user);

        $response = $this->postJsonLocalizedRoute('demo.run', [
            'text' => str_repeat('b', 40),
            'target_language' => 'en',
            'voice_tier' => 'standard',
            'voice_key' => VoiceCatalog::keyFromId((int) $voice->id),
            'fp_canvas' => 'any-canvas',
            'fp_webgl' => 'any-webgl',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('text');
        $response->assertJsonPath('errors.text.0', __('ui.backend.demo_trial_exhausted'));
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
            'language' => 'en',
            'gender' => 'female',
            'tier' => 'standard',
            'display_name' => 'Emma',
            'internal_voice_id' => 'en-US-Wavenet-C',
            'provider' => 'google_cloud',
            'provider_voice_id' => 'en-US-Wavenet-C',
            'is_active' => true,
        ]);
    }

    private function storeDemoUsage(User $user, int $billableChars, string $identity): void
    {
        DemoVoiceGeneration::query()->create([
            'user_id' => $user->id,
            'primary_identity_hash' => $identity,
            'input_text' => str_repeat('x', max(1, $billableChars)),
            'speech_text' => str_repeat('x', max(1, $billableChars)),
            'source_language' => 'en',
            'target_language' => 'en',
            'translate' => false,
            'voice_name' => 'en-US-Wavenet-C',
            'voice_provider' => 'google_cloud',
            'voice_tier' => 'standard',
            'billable_chars' => $billableChars,
        ]);
    }
}
