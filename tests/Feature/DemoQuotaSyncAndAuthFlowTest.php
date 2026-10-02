<?php

namespace Tests\Feature;

use App\Models\DemoGuestUsage;
use App\Models\DemoIdentityLink;
use App\Models\DemoVoiceGeneration;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DemoQuotaSyncAndAuthFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_page_and_quota_endpoint_return_user_only_quota_even_if_fingerprint_matches_another_user(): void
    {
        $this->seedFreePlan();

        $existingUser = User::factory()->create();
        $newUser = User::factory()->create();

        $sharedCanvas = 'shared-canvas-fingerprint-for-demo-sync';
        $sharedWebgl = 'shared-webgl-fingerprint-for-demo-sync';

        $this->storeDemoUsage($existingUser, $sharedCanvas, $sharedWebgl, 260);

        $page = $this->actingAs($newUser)->getLocalizedRoute('demo.page');

        $page->assertOk();
        // Checked on the view data: the page embeds it with @json, which escapes quotes.
        // The page load cannot see the browser fingerprint, so it asks the browser to
        // sync through the quota endpoint below, which then answers without a sync flag.
        $page->assertViewHas('demoQuota', fn (array $quota): bool => $quota['requires_client_sync'] === true
            && $quota['verification_source'] === 'user_only'
            && $quota['used_chars'] === 0);

        $quota = $this->actingAs($newUser)->postJsonLocalizedRoute('demo.quota', [
            'fp_canvas' => $sharedCanvas,
            'fp_webgl' => $sharedWebgl,
        ]);

        $quota->assertOk();
        $quota->assertJsonPath('ok', true);
        $quota->assertJsonPath('quota.used_chars', 0);
        $quota->assertJsonPath('quota.remaining_chars', 300);
        $quota->assertJsonPath('quota.verification_source', 'user_only');
        $quota->assertJsonPath('quota.requires_client_sync', false);
    }

    public function test_login_redirects_back_to_demo_after_guest_is_sent_to_login(): void
    {
        $this->seedFreePlan();

        $password = 'Password123!';
        $user = User::factory()->create([
            'password' => Hash::make($password),
        ]);

        $guestResponse = $this->getLocalizedRoute('demo.page');

        $guestResponse->assertRedirect($this->localizedRoute('login'));

        $loginResponse = $this->postLocalizedRoute('login.submit', [
            'email' => $user->email,
            'password' => $password,
        ]);

        $loginResponse->assertRedirect($this->localizedRoute('demo.page'));
    }

    private function seedFreePlan(): Plan
    {
        return Plan::query()->create([
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
    }

    private function storeDemoUsage(User $user, string $canvasFingerprint, string $webglFingerprint, int $billableChars): void
    {
        $canvasIdentity = 'cv:'.hash('sha256', $canvasFingerprint);
        $webglIdentity = 'gl:'.hash('sha256', $webglFingerprint);

        DemoVoiceGeneration::query()->create([
            'user_id' => $user->id,
            'primary_identity_hash' => $canvasIdentity,
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

        DemoGuestUsage::query()->updateOrCreate(
            ['identity_hash' => $canvasIdentity],
            [
                'link_group' => 'sg:test-demo-sync',
                'used_chars' => $billableChars,
                'last_seen_at' => now(),
            ]
        );

        DemoGuestUsage::query()->updateOrCreate(
            ['identity_hash' => $webglIdentity],
            [
                'link_group' => 'sg:test-demo-sync',
                'used_chars' => $billableChars,
                'last_seen_at' => now(),
            ]
        );

        DemoIdentityLink::query()->create([
            'identity_hash' => $canvasIdentity,
            'link_group' => 'sg:test-demo-sync',
            'identity_type' => 'canvas',
            'confidence' => 1.0,
        ]);

        DemoIdentityLink::query()->create([
            'identity_hash' => $webglIdentity,
            'link_group' => 'sg:test-demo-sync',
            'identity_type' => 'webgl',
            'confidence' => 1.0,
        ]);
    }
}
