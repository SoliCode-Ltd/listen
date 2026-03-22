<?php

namespace Tests\Feature;

use App\Models\DemoGuestUsage;
use App\Models\DemoIdentityLink;
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

    private const EMPTY_SHA256 = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    public function test_demo_trial_exhausts_when_strong_fingerprint_matches_another_account(): void
    {
        $voice = $this->seedFreePlanAndVoice();
        $existingUser = User::factory()->create();
        $newUser = User::factory()->create();

        $sharedCanvas = 'stable-canvas-fingerprint-123';
        $sharedWebgl = 'stable-webgl-fingerprint-123';
        $canvasIdentity = 'cv:'.hash('sha256', $sharedCanvas);
        $webglIdentity = 'gl:'.hash('sha256', $sharedWebgl);
        $this->storeDemoUsage($existingUser, $canvasIdentity, 260);
        $this->storeDemoUsage($existingUser, $webglIdentity, 260);

        DemoIdentityLink::query()->create([
            'identity_hash' => $canvasIdentity,
            'link_group' => 'lg:shared-device-1',
            'identity_type' => 'canvas',
            'confidence' => 1.0,
        ]);

        DemoIdentityLink::query()->create([
            'identity_hash' => $webglIdentity,
            'link_group' => 'lg:shared-device-1',
            'identity_type' => 'webgl',
            'confidence' => 1.0,
        ]);

        DemoGuestUsage::query()->updateOrCreate(
            ['identity_hash' => $canvasIdentity],
            ['link_group' => 'lg:shared-device-1', 'used_chars' => 260, 'last_seen_at' => now()]
        );

        DemoGuestUsage::query()->updateOrCreate(
            ['identity_hash' => $webglIdentity],
            ['link_group' => 'lg:shared-device-1', 'used_chars' => 260, 'last_seen_at' => now()]
        );

        $this->actingAs($newUser);

        $response = $this->postJsonLocalizedRoute('demo.run', [
            'text' => str_repeat('a', 50),
            'target_language' => 'en',
            'voice_tier' => 'standard',
            'voice_key' => VoiceCatalog::keyFromId((int) $voice->id),
            'fp_canvas' => $sharedCanvas,
            'fp_webgl' => $sharedWebgl,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('text');
        $response->assertJsonPath('errors.text.0', __('ui.backend.demo_trial_exhausted'));
    }

    public function test_demo_trial_ignores_placeholder_fingerprint_hash_when_checking_cross_account_usage(): void
    {
        $voice = $this->seedFreePlanAndVoice();
        $existingUser = User::factory()->create();
        $newUser = User::factory()->create();

        // Historical rows may contain this value when the frontend hashed empty
        // fingerprint components before sending them.
        $placeholderIdentityHash = 'cv:'.hash('sha256', self::EMPTY_SHA256);
        $this->storeDemoUsage($existingUser, $placeholderIdentityHash, 260);

        $this->actingAs($newUser);

        $response = $this->postJsonLocalizedRoute('demo.run', [
            'text' => str_repeat('b', 50),
            'target_language' => 'en',
            'voice_tier' => 'standard',
            'voice_key' => VoiceCatalog::keyFromId((int) $voice->id),
            'fp_canvas' => self::EMPTY_SHA256,
        ]);

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $this->assertSame(0, (int) data_get($response->json(), 'quota.used_chars'));
    }

    public function test_demo_trial_does_not_link_accounts_when_only_one_strong_signal_is_present(): void
    {
        $voice = $this->seedFreePlanAndVoice();
        $existingUser = User::factory()->create();
        $newUser = User::factory()->create();

        $sharedCanvas = 'single-signal-canvas-fingerprint';
        $sharedIdentityHash = 'cv:'.hash('sha256', $sharedCanvas);
        $this->storeDemoUsage($existingUser, $sharedIdentityHash, 260);

        $this->actingAs($newUser);

        $response = $this->postJsonLocalizedRoute('demo.run', [
            'text' => str_repeat('d', 50),
            'target_language' => 'en',
            'voice_tier' => 'standard',
            'voice_key' => VoiceCatalog::keyFromId((int) $voice->id),
            'fp_canvas' => $sharedCanvas,
        ]);

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $this->assertSame(0, (int) data_get($response->json(), 'quota.used_chars'));
    }

    public function test_demo_trial_does_not_link_accounts_using_primary_fallback_fingerprint_only(): void
    {
        $voice = $this->seedFreePlanAndVoice();
        $existingUser = User::factory()->create();
        $newUser = User::factory()->create();

        $sharedPrimary = 'mobile-fallback-primary-fingerprint';
        $sharedIdentityHash = 'fp:'.hash('sha256', $sharedPrimary);
        $this->storeDemoUsage($existingUser, $sharedIdentityHash, 260);

        $this->actingAs($newUser);

        $response = $this->withHeaders([
            'X-Listen-Fingerprint' => base64_encode(json_encode([
                'primary' => $sharedPrimary,
            ], JSON_THROW_ON_ERROR)),
        ])->postJsonLocalizedRoute('demo.run', [
            'text' => str_repeat('c', 50),
            'target_language' => 'en',
            'voice_tier' => 'standard',
            'voice_key' => VoiceCatalog::keyFromId((int) $voice->id),
        ]);

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $this->assertSame(0, (int) data_get($response->json(), 'quota.used_chars'));
    }

    public function test_demo_trial_does_not_merge_unrelated_canvas_and_webgl_collisions_without_confirmed_link_group(): void
    {
        $voice = $this->seedFreePlanAndVoice();
        $canvasUser = User::factory()->create();
        $webglUser = User::factory()->create();
        $newUser = User::factory()->create();

        $sharedCanvas = 'broad-canvas-fingerprint';
        $sharedWebgl = 'broad-webgl-fingerprint';

        $this->storeDemoUsage($canvasUser, 'cv:'.hash('sha256', $sharedCanvas), 298);
        $this->storeDemoUsage($webglUser, 'gl:'.hash('sha256', $sharedWebgl), 298);

        $this->actingAs($newUser);

        $response = $this->postJsonLocalizedRoute('demo.run', [
            'text' => str_repeat('e', 20),
            'target_language' => 'en',
            'voice_tier' => 'standard',
            'voice_key' => VoiceCatalog::keyFromId((int) $voice->id),
            'fp_canvas' => $sharedCanvas,
            'fp_webgl' => $sharedWebgl,
        ]);

        $response->assertOk();
        $response->assertJsonPath('ok', true);
        $this->assertSame(0, (int) data_get($response->json(), 'quota.used_chars'));
    }

    public function test_demo_trial_applies_cross_account_usage_when_two_strong_signals_are_confirmed_in_same_link_group(): void
    {
        $voice = $this->seedFreePlanAndVoice();
        $existingUser = User::factory()->create();
        $newUser = User::factory()->create();

        $sharedCanvas = 'confirmed-canvas-fingerprint';
        $sharedWebgl = 'confirmed-webgl-fingerprint';
        $canvasIdentity = 'cv:'.hash('sha256', $sharedCanvas);
        $webglIdentity = 'gl:'.hash('sha256', $sharedWebgl);

        $this->storeDemoUsage($existingUser, $canvasIdentity, 260);
        $this->storeDemoUsage($existingUser, $webglIdentity, 260);

        DemoIdentityLink::query()->create([
            'identity_hash' => $canvasIdentity,
            'link_group' => 'lg:confirmed-device',
            'identity_type' => 'canvas',
            'confidence' => 1.0,
        ]);

        DemoIdentityLink::query()->create([
            'identity_hash' => $webglIdentity,
            'link_group' => 'lg:confirmed-device',
            'identity_type' => 'webgl',
            'confidence' => 1.0,
        ]);

        DemoGuestUsage::query()->updateOrCreate(
            ['identity_hash' => $canvasIdentity],
            ['link_group' => 'lg:confirmed-device', 'used_chars' => 260, 'last_seen_at' => now()]
        );

        DemoGuestUsage::query()->updateOrCreate(
            ['identity_hash' => $webglIdentity],
            ['link_group' => 'lg:confirmed-device', 'used_chars' => 260, 'last_seen_at' => now()]
        );

        $this->actingAs($newUser);

        $response = $this->postJsonLocalizedRoute('demo.run', [
            'text' => str_repeat('f', 50),
            'target_language' => 'en',
            'voice_tier' => 'standard',
            'voice_key' => VoiceCatalog::keyFromId((int) $voice->id),
            'fp_canvas' => $sharedCanvas,
            'fp_webgl' => $sharedWebgl,
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

    private function storeDemoUsage(User $user, string $primaryIdentityHash, int $billableChars): void
    {
        DemoVoiceGeneration::query()->create([
            'user_id' => $user->id,
            'primary_identity_hash' => $primaryIdentityHash,
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

        // Also create DemoGuestUsage record (device-level quota tracker).
        DemoGuestUsage::query()->updateOrCreate(
            ['identity_hash' => $primaryIdentityHash],
            ['used_chars' => $billableChars, 'last_seen_at' => now()]
        );
    }
}
