<?php

namespace Tests\Feature\Agents;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Agents\AgentAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Live gets the new code a minute before its migration runs. Until the agents tables exist,
 * members' pages work as before and nobody sees Agents (their pages read those tables).
 */
class AgentsBeforeMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_members_pages_work_and_agents_stay_hidden_until_the_agents_tables_exist(): void
    {
        Plan::factory()->free()->create(['monthly_chars' => 10000, 'voice_tier' => 'standard']);
        $plan = Plan::factory()->create(['slug' => 'creator', 'name' => 'Creator', 'price_cents' => 2200, 'voice_tier' => 'hd',
            'monthly_chars' => 180000, 'is_active' => true]);
        $member = User::factory()->create();
        Subscription::create(['user_id' => $member->id, 'plan_id' => $plan->id, 'status' => 'active']);

        // The server as it is between the deploy and the migration: no agents tables yet.
        foreach (['agent_contacts', 'agent_conversations', 'agent_widgets', 'agent_sources', 'agents'] as $table) {
            Schema::dropIfExists($table);
        }

        $this->assertFalse(AgentAccess::tablesReady());
        $this->assertFalse(AgentAccess::openTo($member));
        $this->actingAs($member)->getLocalizedRoute('dashboard')->assertOk();
        $this->actingAs($member)->getLocalizedRoute('voice-agents.index')->assertNotFound();
        $this->getLocalizedRoute('home')->assertOk();
        $this->getLocalizedRoute('pricing')->assertOk();
        $this->getLocalizedRoute('agents-landing')->assertNotFound();
        $this->artisan('agents:settle-calls')->assertSuccessful();
    }

    public function test_agents_open_once_the_tables_exist(): void
    {
        $this->assertTrue(AgentAccess::tablesReady());
        $this->assertTrue(AgentAccess::openTo(null));
    }
}
