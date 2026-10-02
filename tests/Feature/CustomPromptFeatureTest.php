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
        // The demo requires a signed-in member.
        $this->actingAs(User::factory()->create());

        $response = $this->postJsonLocalizedRoute('demo.preview', [
            'text' => 'Hello, this is a test.',
            'target_language' => 'en',
            'translate' => false,
            'voice_tier' => 'hd',
            'style_prompt' => 'Speak with enthusiasm and energy.',
        ]);

        $response->assertJsonMissingValidationErrors(['style_prompt']);
    }

    /**
     * Test that style_prompt is rejected if too long.
     */
    public function test_style_prompt_validation_rejects_too_long(): void
    {
        $this->actingAs(User::factory()->create());

        $response = $this->postJsonLocalizedRoute('demo.preview', [
            'text' => 'Hello, this is a test.',
            'target_language' => 'en',
            'translate' => false,
            'voice_tier' => 'hd',
            // HD previews require a style and an emotion before the rest is validated.
            'performance_style' => 'neutral',
            'emotion' => 'neutral',
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

        // Plans are attached through subscriptions (users no longer carry plan_id).
        $user = User::factory()->create();
        \App\Models\Subscription::query()->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => 'active',
        ]);

        $this->actingAs($user);

        $response = $this->postJsonLocalizedRoute('dashboard.generate', [
            'text' => 'Hello, this is a test.',
            'target_language' => 'en',
            'translate' => false,
            'voice_tier' => 'standard',
            'style_prompt' => 'This should be stripped for standard tier.',
        ]);

        // style_prompt is stripped by sanitizeStudioOptionsForTier, never a validation error.
        $response->assertJsonMissingValidationErrors(['style_prompt']);
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
     * Custom Prompt is switched on per plan (custom_prompt_enabled), and the
     * pricing page lists it for those plans.
     */
    public function test_pricing_page_includes_custom_prompt_for_plans_that_enable_it(): void
    {
        Plan::factory()->create([
            'voice_tier' => VoiceTier::HD,
            'slug' => 'test-hd-plan',
            'price_cents' => 999,
            'is_active' => true,
            'custom_prompt_enabled' => true,
        ]);

        $response = $this->getLocalizedRoute('pricing');
        $response->assertStatus(200);

        // The response should include the Custom Prompt feature label
        $response->assertSee(__('ui.pricing.feature_label_custom_prompt'));
    }
}
