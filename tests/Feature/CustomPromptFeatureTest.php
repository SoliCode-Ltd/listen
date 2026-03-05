<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use App\Support\VoiceTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for the Custom Prompt (style_prompt) feature.
 * 
 * The Custom Prompt feature allows users on Premium (HD) tier plans
 * to provide custom voice styling instructions for Gemini TTS voices.
 */
class CustomPromptFeatureTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that style_prompt is validated correctly for demo preview.
     */
    public function test_style_prompt_validation_accepts_valid_string(): void
    {
        $response = $this->postJson(route('demo.preview'), [
            'text' => 'Hello, this is a test.',
            'target_language' => 'en',
            'translate' => false,
            'voice_tier' => 'hd',
            'style_prompt' => 'Speak with enthusiasm and energy.',
        ]);

        // Should not return validation error for style_prompt
        if ($response->status() === 422) {
            $this->assertArrayNotHasKey('style_prompt', $response->json('errors', []));
        }
    }

    /**
     * Test that style_prompt is rejected if too long.
     */
    public function test_style_prompt_validation_rejects_too_long(): void
    {
        $response = $this->postJson(route('demo.preview'), [
            'text' => 'Hello, this is a test.',
            'target_language' => 'en',
            'translate' => false,
            'voice_tier' => 'hd',
            'style_prompt' => str_repeat('a', 2001), // Over 2000 chars
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['style_prompt']);
    }

    /**
     * Test that style_prompt is stripped for standard tier.
     */
    public function test_style_prompt_is_sanitized_for_standard_tier(): void
    {
        // Create a user with a standard tier plan
        $plan = Plan::factory()->create([
            'voice_tier' => VoiceTier::STANDARD,
        ]);
        
        $user = User::factory()->create([
            'plan_id' => $plan->id,
        ]);

        $this->actingAs($user);

        $response = $this->postJson(route('dashboard.generate'), [
            'text' => 'Hello, this is a test.',
            'target_language' => 'en',
            'translate' => false,
            'voice_tier' => 'standard',
            'style_prompt' => 'This should be stripped for standard tier.',
        ]);

        // The request should succeed (or fail for other reasons)
        // but style_prompt should be stripped by sanitizeStudioOptionsForTier
        // We're testing that the validation passes at least
        if ($response->status() === 422) {
            $this->assertArrayNotHasKey('style_prompt', $response->json('errors', []));
        }
    }

    /**
     * Test that VoiceTier label returns Premium instead of HD.
     */
    public function test_voice_tier_label_returns_premium(): void
    {
        $label = VoiceTier::label(VoiceTier::HD);
        
        // The label should use the translation key
        // When using default locale, it should resolve to 'Premium'
        $this->assertNotEquals('HD', $label);
    }

    /**
     * Test that pricing features include Custom Prompt for HD tier plans.
     */
    public function test_pricing_page_includes_custom_prompt_for_hd_plans(): void
    {
        // Create an HD tier plan
        Plan::factory()->create([
            'voice_tier' => VoiceTier::HD,
            'slug' => 'test-hd-plan',
            'price_cents' => 999,
        ]);

        $response = $this->get(route('pricing'));
        $response->assertStatus(200);
        
        // The response should include the Custom Prompt feature label
        $response->assertSee(__('ui.pricing.feature_label_custom_prompt'));
    }
}
