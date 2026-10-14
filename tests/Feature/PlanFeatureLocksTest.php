<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\AudioFormats;
use App\Support\PlanFeatures;
use App\Support\VoiceTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanFeatureLocksTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_starter_member_sees_every_option_locked_with_the_plan_that_unlocks_it(): void
    {
        $starter = Plan::factory()->create(['slug' => 'starter', 'name' => 'Starter', 'voice_tier' => VoiceTier::STANDARD, 'is_active' => true, 'price_cents' => 900]);
        Plan::factory()->create(['slug' => 'creator', 'name' => 'Creator', 'voice_tier' => VoiceTier::HD, 'is_active' => true, 'price_cents' => 2200, 'sound_effects_enabled' => true]);
        Plan::factory()->create(['slug' => 'pro', 'name' => 'Pro', 'voice_tier' => VoiceTier::HD, 'is_active' => true, 'price_cents' => 4900, 'dialogue_enabled' => true, 'custom_prompt_enabled' => true, 'sound_effects_enabled' => true]);
        $user = $this->memberOf($starter);

        $html = $this->actingAs($user)->getLocalizedRoute('dashboard')->assertOk()->getContent();

        // Dialect, style and emotion, speed and pitch: on the premium voices.
        $this->assertStringContainsString('name="emotion"', $html);
        $this->assertStringContainsString('name="speed"', $html);
        $this->assertStringContainsString('Upgrade to Creator', $html);
        $this->assertStringContainsString('billing?plan=creator', $html);
        // Dialogue and the custom prompt: only Pro has them.
        $this->assertStringContainsString('Upgrade to Pro', $html);
        $this->assertStringContainsString('billing?plan=pro', $html);
        $this->assertStringContainsString('name="style_prompt"', $html);
        $this->assertGreaterThanOrEqual(4, substr_count($html, 'data-vs-locked'));
        if (AudioFormats::serverCanConvert()) {
            $this->assertStringContainsString('data-vs-pick="audio_effect"', $html);
        }
    }

    public function test_options_no_plan_sells_stay_hidden_and_owned_options_are_not_locked(): void
    {
        $pro = Plan::factory()->create(['slug' => 'pro', 'name' => 'Pro', 'voice_tier' => VoiceTier::HD, 'is_active' => true, 'price_cents' => 4900, 'dialogue_enabled' => true, 'custom_prompt_enabled' => true, 'sound_effects_enabled' => true]);

        $html = $this->actingAs($this->memberOf($pro))->getLocalizedRoute('dashboard')->assertOk()->getContent();
        $this->assertStringNotContainsString('data-vs-locked', $html);
        $this->assertStringNotContainsString('vs-upgrade', $html);

        $this->assertNull(PlanFeatures::lock(PlanFeatures::DIALOGUE, true, false));
        $pro->update(['dialogue_enabled' => false]);
        $this->assertTrue(PlanFeatures::lock(PlanFeatures::DIALOGUE, false, false)['none'] ?? false, 'no plan on sale has it');
    }

    public function test_the_arabic_button_names_the_plan_in_arabic(): void
    {
        $starter = Plan::factory()->create(['slug' => 'starter', 'voice_tier' => VoiceTier::STANDARD, 'is_active' => true, 'price_cents' => 900]);
        Plan::factory()->create(['slug' => 'creator', 'name' => 'Creator', 'name_ar' => 'المبدع', 'voice_tier' => VoiceTier::HD, 'is_active' => true, 'price_cents' => 2200]);
        app()->setLocale('ar');

        $html = $this->actingAs($this->memberOf($starter))->getLocalizedRoute('dashboard')->assertOk()->getContent();

        $this->assertStringContainsString('رقِّ إلى المبدع', $html);
        $this->assertStringContainsString('22 لغة أخرى مع باقة المبدع', $html);
    }

    private function memberOf(Plan $plan): User
    {
        $user = User::factory()->create(['subscription_status_checked_at' => now()]);
        Subscription::query()->create(['user_id' => $user->id, 'plan_id' => $plan->id, 'status' => 'active']);

        return $user;
    }
}
