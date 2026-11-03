<?php

namespace Tests\Feature\Admin;

use App\Filament\Pages\AgentSettings;
use App\Filament\Resources\AgentResource\Pages\ViewAgent;
use App\Filament\Resources\PlanResource\Pages\EditPlan;
use App\Models\Agent;
use App\Models\Plan;
use App\Models\User;
use App\Support\Agents\AgentAccess;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The admin's controls over Listen Agents: Agents → Settings (who sees them,
 * the credits a call minute uses, free test minutes, the safety ceilings, with
 * what a call minute earns and costs on each plan), the Voice agents section of
 * each plan, and suspending one agent.
 */
class AgentSettingsAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->admin = User::factory()->create(['is_admin' => true]);
    }

    public function test_the_settings_page_shows_each_plans_numbers_and_saves(): void
    {
        Plan::factory()->create(['slug' => 'creator', 'name' => 'Creator', 'price_cents' => 2200, 'monthly_chars' => 180000, 'is_active' => true, 'agents_enabled' => true]);

        Livewire::actingAs($this->admin)->test(AgentSettings::class)
            ->assertSee('Creator')->assertSee('Call minutes the credits pay for')->assertSee('Stop all calls now')
            ->assertSet('data.agents_credits_per_minute', 1800)
            ->set('data.agents_credits_per_minute', 2700)
            ->set('data.agents_free_test_minutes', 1)
            ->set('data.agents_calls_stopped', true)
            ->set('data.agents_site_daily_minutes', 500)
            ->set('data.agents_audience', AgentAccess::AUDIENCE_PREVIEW)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(2700, AgentAccess::creditsPerMinute());
        $this->assertSame(60, AgentAccess::freeTestSeconds());
        $this->assertTrue(AgentAccess::callsStopped());
        $this->assertSame(500, AgentAccess::siteDailyMinutes());
        $this->assertSame(AgentAccess::AUDIENCE_PREVIEW, AgentAccess::audience());
    }

    public function test_a_plan_includes_agents_with_its_own_numbers(): void
    {
        $plan = Plan::factory()->create(['slug' => 'pro', 'name' => 'Pro', 'price_cents' => 4900, 'monthly_chars' => 450000]);

        Livewire::actingAs($this->admin)->test(EditPlan::class, ['record' => $plan->getRouteKey()])
            ->assertFormFieldExists('agents_enabled')
            ->fillForm(['agents_enabled' => true, 'agents_max' => 5, 'agent_minutes_per_month' => 200, 'agent_concurrent_calls' => 4, 'agent_max_call_minutes' => 8])
            ->assertSee('call minutes')
            ->call('save')
            ->assertHasNoFormErrors();

        $plan->refresh();
        $this->assertTrue($plan->agents_enabled);
        $this->assertSame(['included' => true, 'agents' => 5, 'minutes' => 200, 'concurrent' => 4, 'call_seconds' => 480], AgentAccess::limits($plan));
    }

    public function test_the_team_suspends_an_agent_and_lifts_it(): void
    {
        $owner = User::factory()->create();
        $agent = Agent::create([
            'user_id' => $owner->id, 'name' => 'Sara', 'business_name' => 'Cairo Smiles', 'business_type' => 'healthcare',
            'jobs' => ['answer'], 'language' => 'ar', 'dialect' => 'ar-EG', 'live_voice' => 'Achernar', 'status' => Agent::LIVE,
        ]);

        Livewire::actingAs($this->admin)->test(ViewAgent::class, ['record' => $agent->getRouteKey()])
            ->callAction('suspend', ['reason' => 'It asked callers for their card numbers.'])
            ->assertHasNoActionErrors();
        $agent->refresh();
        $this->assertSame('It asked callers for their card numbers.', $agent->setting('suspended.reason'));
        $this->assertSame(Agent::PAUSED, $agent->status);

        Livewire::actingAs($this->admin)->test(ViewAgent::class, ['record' => $agent->getRouteKey()])
            ->callAction('unsuspend');
        $this->assertNull($agent->fresh()->setting('suspended'));
    }

    public function test_members_cannot_open_the_settings(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => false]))->get(AgentSettings::getUrl())->assertForbidden();
    }
}
