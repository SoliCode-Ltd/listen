<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use App\Services\PlanAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudioLayoutTest extends TestCase
{
    use RefreshDatabase;

    /** The demo's studio is the dashboard's: same fields, same order, same notes. */
    private function assertStudioOrder(\Illuminate\Testing\TestResponse $response): void
    {
        $response->assertOk()
            ->assertSeeInOrder(['id="voiceJump"', 'id="target_language"', 'data-dialogue', 'id="speed"', 'id="pitch"', 'id="translateToggle"'], false)
            ->assertSee(__('ui.dashboard.speed_hint'))
            ->assertSee(__('ui.dashboard.pitch_hint'));
    }

    public function test_the_demo_studio_matches_the_dashboard(): void
    {
        $this->assertStudioOrder($this->actingAs(User::factory()->create())->getLocalizedRoute('demo.page'));

        // A member whose plan has the premium voices, so dialogue mode is part of their studio.
        $member = User::factory()->create();
        $plan = Plan::create([
            'slug' => 'creator', 'name' => 'Creator', 'price_cents' => 2200, 'monthly_minutes' => 300,
            'monthly_chars' => 270000, 'monthly_requests' => 3000, 'max_chars_per_request' => 5000,
            'voice_tier' => 'hd', 'dialogue_enabled' => true, 'is_active' => true,
        ]);
        app(PlanAccessService::class)->assignPlan($member, $plan, 'active', now()->addMonth());
        $this->assertStudioOrder($this->actingAs($member)->getLocalizedRoute('dashboard'));
    }

    public function test_the_notes_are_translated(): void
    {
        app()->setLocale('ar');

        $this->assertStringNotContainsString('speed_hint', __('ui.dashboard.speed_hint'));
        $this->assertStringNotContainsString('pitch_hint', __('ui.dashboard.pitch_hint'));
        $this->assertMatchesRegularExpression('/\p{Arabic}/u', __('ui.dashboard.speed_hint').__('ui.dashboard.pitch_hint'));
    }
}
