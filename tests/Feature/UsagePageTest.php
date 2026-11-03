<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\AgentConversation;
use App\Models\AudioGeneration;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\TextItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Where the credits went, in the studio's overview and on the Agents page, read from what was charged. */
class UsagePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_shows_where_the_credits_went_by_kind_and_item(): void
    {
        $plan = Plan::factory()->create(['slug' => 'pro-u', 'price_cents' => 4900, 'monthly_chars' => 4000000, 'agents_enabled' => true]);
        $user = User::factory()->create();
        Subscription::create(['user_id' => $user->id, 'plan_id' => $plan->id, 'status' => 'active']);

        $text = new TextItem();
        $text->forceFill(['user_id' => $user->id, 'source_type' => 'dashboard', 'title' => 'Friday offer', 'content' => 'Hello', 'status' => 'done', 'received_at' => now()])->save();
        $audio = new AudioGeneration();
        $audio->forceFill(['user_id' => $user->id, 'text_item_id' => $text->id, 'input_text' => 'Hello', 'target_language' => 'en', 'status' => 'done', 'billable_chars' => 12600, 'duration_seconds' => 840])->save();
        // A failed one used nothing and is not listed.
        $failed = new AudioGeneration();
        $failed->forceFill(['user_id' => $user->id, 'input_text' => 'Hi', 'target_language' => 'en', 'status' => 'failed', 'billable_chars' => 999])->save();

        $agent = Agent::create(['user_id' => $user->id, 'name' => 'Sara', 'business_name' => 'Cairo Smiles', 'business_type' => 'healthcare', 'language' => 'ar', 'status' => Agent::LIVE]);
        $call = new AgentConversation();
        $call->forceFill(['agent_id' => $agent->id, 'user_id' => $user->id, 'channel' => 'widget', 'status' => 'ended', 'free' => false,
            'started_at' => now()->subMinutes(5), 'settled_at' => now(), 'billed_seconds' => 187, 'credits' => 25300, 'transcript' => [],
            'public_id' => 'cnv_'.Str::random(20), 'call_key_hash' => hash('sha256', 'k')])->save();
        // A free test call used nothing and is not listed.
        $test = new AgentConversation();
        $test->forceFill(['agent_id' => $agent->id, 'user_id' => $user->id, 'channel' => 'test', 'status' => 'ended', 'free' => true,
            'started_at' => now()->subMinutes(2), 'settled_at' => now(), 'billed_seconds' => 60, 'credits' => 0, 'transcript' => [],
            'public_id' => 'cnv_'.Str::random(20), 'call_key_hash' => hash('sha256', 't')])->save();

        // In the studio's overview and on the Agents page: the same credits pay for both.
        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            foreach (['dashboard', 'voice-agents.index'] as $route) {
                $this->actingAs($user)->getLocalizedRoute($route)->assertOk()
                    ->assertSee('id="usage-breakdown"', false)
                    ->assertSee(__('usage.where'))
                    ->assertSee(__('usage.kinds.studio'))->assertSee(__('usage.kinds.agent'))
                    ->assertSee('Friday offer')->assertSee(__('usage.call_with', ['name' => 'Sara']))
                    ->assertSee('12,600')->assertSee('25,300')->assertSee('14:00')
                    ->assertDontSee('<b>999</b>', false);
            }
        }
        $usage = \App\Support\CreditUsage::for($user);
        $this->assertSame(12600, $usage['parts']['studio']['credits']);
        $this->assertSame(25300, $usage['parts']['agent']['credits']);
        $this->assertCount(2, $usage['items']);
    }

    public function test_the_studio_meter_and_the_call_time_card_open_it(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->getLocalizedRoute('dashboard')->assertOk()->assertSee('href="#usage-breakdown"', false);
    }
}
