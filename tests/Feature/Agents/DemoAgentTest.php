<?php

namespace Tests\Feature\Agents;

use App\Models\Agent;
use App\Models\AgentConversation;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Agents\AgentCalls;
use App\Support\Agents\AgentAccess;
use App\Support\Agents\DemoAgent;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Tests\TestCase;

/**
 * The demo page's Voice agent tab (DemoAgent): a live call with a sample business's agent. It
 * comes out of the member's free test seconds, never their credits, and runs on a practice
 * agent that is never one of their agents.
 */
class DemoAgentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.gemini_tts.api_key' => 'test-key']);
        Http::fake(['*auth_tokens' => Http::response(['name' => 'auth_tokens/live-key'])]);
        Plan::factory()->free()->create(['monthly_chars' => 10000, 'voice_tier' => 'standard']);
        SiteSetting::set('agents_free_test_seconds', '120', 'agents');
    }

    private function withoutLocale(): static
    {
        return $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class, LaravelLocalizationViewPath::class, ValidateCsrfToken::class]);
    }

    private function member(array $attributes = []): User
    {
        return User::factory()->create(array_merge(['email_verified_at' => now()], $attributes));
    }

    private function demoCall(User $user, string $preset = 'clinic'): TestResponse
    {
        return $this->withoutLocale()->actingAs($user)->postJson($this->localizedRoute('demo.agent-call'), ['preset' => $preset]);
    }

    public function test_free_members_get_a_voice_agent_tab_with_three_businesses_and_their_free_time(): void
    {
        $page = $this->actingAs($this->member())->getLocalizedRoute('demo.page')->assertOk()
            ->assertSee('data-studio-tab="agent"', false)
            ->assertSee('data-demo-agent', false)
            ->assertSee(__('agents.demo.title'))
            ->assertSee(__('agents.demo.left', ['time' => '2:00']));
        $this->assertSame(3, substr_count($page->getContent(), 'name="demo-agent-preset"'));

        // Where Agents are closed, the tab is not there.
        SiteSetting::set('agents_audience', AgentAccess::AUDIENCE_OFF, 'agents');
        $this->actingAs($this->member())->getLocalizedRoute('demo.page')->assertOk()
            ->assertDontSee('data-studio-tab="agent"', false)
            ->assertDontSee('data-demo-agent', false);
    }

    public function test_a_demo_call_is_a_free_test_call_on_a_practice_agent_that_is_never_one_of_the_members(): void
    {
        $member = $this->member();

        $this->demoCall($member)->assertOk()
            ->assertJsonPath('token', 'auth_tokens/live-key')
            ->assertJsonPath('free', true)
            ->assertJsonPath('max_seconds', 120);

        $call = AgentConversation::sole();
        $this->assertSame('test', $call->channel);
        $this->assertTrue((bool) $call->free);
        $this->assertSame($member->id, (int) $call->user_id);
        $this->assertSame(0, (int) ($call->reservation['credits'] ?? 0), 'no credits held');

        // The practice agent is not on the member's list and does not count against their plan.
        $this->assertSame(0, Agent::ownedBy($member)->count());
        $practice = Agent::withTrashed()->where('user_id', $member->id)->sole();
        $this->assertSame(Agent::DEMO, $practice->status);
        $this->assertTrue($practice->trashed());
        $this->assertSame($practice->id, (int) $call->agent_id);

        // Another business rewrites the same practice agent: still one, now the restaurant.
        app(AgentCalls::class)->end($call, [], null, 'done');
        $this->demoCall($member, 'restaurant')->assertOk();
        $this->assertSame(1, Agent::withTrashed()->where('user_id', $member->id)->count());
        $this->assertSame('restaurants', $practice->fresh()->business_type);
        $this->assertSame('restaurant', $practice->fresh()->setting('demo'));
    }

    public function test_demo_calls_use_the_free_test_seconds_and_stop_when_they_run_out(): void
    {
        $member = $this->member();
        $practice = DemoAgent::agentFor($member, 'shop', 'ar');
        // 110 of the 120 free seconds already used on test calls.
        AgentConversation::create([
            'agent_id' => $practice->id, 'user_id' => $member->id, 'channel' => 'test', 'status' => AgentConversation::ENDED,
            'started_at' => now()->subMinutes(5), 'settled_at' => now()->subMinutes(3), 'free' => true,
            'billed_seconds' => 110, 'reservation' => ['seconds' => 120, 'credits' => 0, 'free' => true], 'transcript' => [],
        ]);

        $this->demoCall($member)->assertStatus(422)
            ->assertJsonPath('reason', 'free_used')
            ->assertJsonPath('message', __('agents.demo.used_up'));
        $this->assertSame(1, AgentConversation::count(), 'no call started');

        $this->withoutLocale()->actingAs($member)->getJson($this->localizedRoute('demo.agent-left'))->assertOk()
            ->assertJsonPath('seconds', 10)
            ->assertJsonPath('can_call', false);
        // The page says so, with the plans, and the call button is held back.
        $this->actingAs($member)->getLocalizedRoute('demo.page')->assertOk()
            ->assertSee(__('agents.demo.used_up'))
            ->assertSee('data-demo-plans', false);
    }

    public function test_a_demo_call_never_spends_a_members_credits_even_on_a_plan_with_agents(): void
    {
        $plan = Plan::factory()->create(['slug' => 'creator-x', 'name' => 'Creator', 'price_cents' => 2200, 'voice_tier' => 'hd',
            'monthly_chars' => 900000, 'is_active' => true, 'agents_enabled' => true, 'agent_concurrent_calls' => 5]);
        $owner = User::factory()->create();
        Subscription::create(['user_id' => $owner->id, 'plan_id' => $plan->id, 'status' => 'active']);
        $practice = DemoAgent::agentFor($owner, 'clinic', 'en');
        SiteSetting::set('agents_free_test_seconds', '0', 'agents');

        $result = app(AgentCalls::class)->start($practice, 'test', null, ['free_only' => true]);

        $this->assertFalse($result['ok']);
        $this->assertSame('free_used', $result['error']);
        $this->assertSame(0, AgentConversation::count());
    }

    public function test_only_the_three_businesses_can_be_called_and_an_unconfirmed_email_comes_first(): void
    {
        $this->demoCall($this->member(), 'bank')->assertStatus(422)->assertJsonValidationErrors('preset');

        config(['listen.demo.require_verified_email' => true]);
        $this->demoCall($this->member(['email_verified_at' => null]))->assertStatus(403)
            ->assertJsonPath('email_verification_required', true);
        $this->assertSame(0, AgentConversation::count());
    }

    public function test_each_business_speaks_its_own_dialect_with_its_own_facts(): void
    {
        $member = $this->member();
        foreach (['ar' => ['ar-SA', 'ar-EG', 'ar-LB'], 'en' => ['en-US', 'en-GB', 'en-AU']] as $language => $dialects) {
            foreach (DemoAgent::PRESETS as $i => $key) {
                $agent = DemoAgent::agentFor($member, $key, $language);
                $this->assertSame($language, $agent->language);
                $this->assertSame($dialects[$i], $agent->dialect);
                $this->assertContains($agent->live_voice, \App\Services\Tts\GeminiTtsProvider::VOICES);
                $this->assertContains($agent->business_type, Agent::BUSINESS_TYPES);
                $this->assertEmpty(array_diff($agent->jobs, Agent::JOBS));
                $this->assertNotSame('', trim((string) $agent->knowledge));
                $card = DemoAgent::card($key, $language);
                $this->assertNotSame('', $card['dialect'], "{$language} {$key} has a dialect name");
                $this->assertCount(2, $card['try']);
            }
        }
        $this->assertSame(1, Agent::withTrashed()->where('user_id', $member->id)->count());
    }
}
