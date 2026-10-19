<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use App\Services\PlanAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The demo is for free accounts. A member who has a plan (say, one who just
 * upgraded in another tab) is sent to the dashboard: page visits by redirect,
 * the demo's own requests by a JSON answer in the page's language.
 */
class DemoAccessForPlanMembersTest extends TestCase
{
    use RefreshDatabase;

    private function memberWithPlan(): User
    {
        $member = User::factory()->create(['subscription_status_checked_at' => now()]);
        $plan = Plan::factory()->paid()->create(['slug' => 'creator', 'voice_tier' => 'hd']);
        app(PlanAccessService::class)->assignPlan($member, $plan);

        return $member;
    }

    public function test_the_demo_requests_of_a_member_with_a_plan_get_their_language(): void
    {
        $member = $this->memberWithPlan();

        $this->actingAs($member)->postJsonLocalizedRoute('demo.quota')
            ->assertForbidden()
            ->assertExactJson([
                'ok' => false,
                'message' => 'The demo is for free accounts. Your plan is active, so create your voices in the dashboard.',
                'redirect_url' => route('dashboard'),
            ]);

        app()->setLocale('ar');
        $this->actingAs($member)->postJsonLocalizedRoute('demo.run', ['text' => 'مرحبا بكم'])
            ->assertForbidden()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('message', 'العرض التجريبي مخصّص للحسابات المجانية. خطتك مفعّلة، فأنشئ أصواتك من لوحة التحكم.')
            ->assertJsonPath('redirect_url', route('dashboard'));
    }

    public function test_the_demo_page_sends_a_member_with_a_plan_to_the_dashboard(): void
    {
        $this->actingAs($this->memberWithPlan())->getLocalizedRoute('demo.page')
            ->assertRedirect(route('dashboard'));
    }

    public function test_free_accounts_still_reach_the_demo(): void
    {
        $free = User::factory()->create();

        $this->actingAs($free)->getLocalizedRoute('demo.page')->assertOk();
        $this->actingAs($free)->postJsonLocalizedRoute('demo.quota')->assertOk();
    }
}
