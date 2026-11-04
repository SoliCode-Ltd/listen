<?php

namespace Tests\Feature\Agents;

use App\Models\Agent;
use App\Models\AgentConversation;
use App\Models\AgentWidget;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Listen Agents: an agent, its call button and link page, and its calls.
 */
class AgentModelTest extends TestCase
{
    use RefreshDatabase;

    private function agent(array $attributes = []): Agent
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        return Agent::create(array_merge([
            'user_id' => $user->id,
            'name' => 'Sara',
            'business_name' => 'Cairo Smiles Clinic',
        ], $attributes));
    }

    public function test_an_agent_gets_an_unguessable_public_id_and_starts_as_a_draft(): void
    {
        $agent = $this->agent();

        $this->assertMatchesRegularExpression('/^agt_[a-z0-9]{24}$/', $agent->public_id);
        $this->assertSame(Agent::DRAFT, $agent->fresh()->status);
        $this->assertSame(600, $agent->fresh()->max_call_seconds);
        $this->assertTrue($agent->user->agents->contains($agent));
    }

    public function test_every_agent_has_a_call_button_and_a_readable_link(): void
    {
        $agent = $this->agent();
        $widget = $agent->mainWidget();

        $this->assertMatchesRegularExpression('/^lw_[A-Za-z0-9]{28}$/', $widget->public_key);
        $this->assertMatchesRegularExpression('/^cairo-smiles-clinic-[a-z0-9]{5}$/', $widget->slug);
        $this->assertTrue($widget->link_enabled);
        $this->assertSame('orb', $widget->look('style'));
        // The same widget each time, not a new one.
        $this->assertTrue($agent->mainWidget()->is($widget));
        $this->assertSame(1, AgentWidget::count());
    }

    public function test_a_call_button_only_loads_on_the_websites_it_allows(): void
    {
        $widget = $this->agent()->mainWidget();
        // None listed: any website.
        $this->assertTrue($widget->allowsOrigin('https://anything.example'));

        $widget->update(['allowed_origins' => ['cairosmiles.com', 'https://www.cairosmiles.com/']]);
        $this->assertTrue($widget->allowsOrigin('https://cairosmiles.com'));
        $this->assertTrue($widget->allowsOrigin('https://WWW.cairosmiles.com'));
        $this->assertFalse($widget->allowsOrigin('https://cairosmiles.com.evil.example'));
        $this->assertFalse($widget->allowsOrigin('http://cairosmiles.com'));
        $this->assertFalse($widget->allowsOrigin(null));
    }

    public function test_a_conversation_keeps_its_transcript_details_and_credits(): void
    {
        $agent = $this->agent();
        $call = AgentConversation::create([
            'agent_id' => $agent->id,
            'user_id' => $agent->user_id,
            'channel' => 'test',
            'started_at' => now(),
            'transcript' => [['role' => 'agent', 'text' => 'أهلاً بيك']],
            'lead' => ['name' => 'Omar', 'phone' => '+201000000000'],
        ]);

        $this->assertMatchesRegularExpression('/^cnv_[a-z0-9]{24}$/', $call->public_id);
        $this->assertTrue($call->fresh()->isLive());
        $this->assertSame('Omar', $call->fresh()->lead['name']);
        $this->assertSame(0, $call->fresh()->credits);
        // A deleted agent's calls keep their agent for the history.
        $agent->delete();
        $this->assertSame('Sara', $call->fresh()->agent->name);
    }
}
