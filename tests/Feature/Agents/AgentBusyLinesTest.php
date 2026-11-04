<?php

namespace Tests\Feature\Agents;

use App\Models\Agent;
use App\Models\AgentContact;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Agents\AgentCalls;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Every line on a member's plan taken (calls at once): the caller waits in line and the first to
 * wait gets the next free line; they count once as a missed call, and no longer once through;
 * they may leave a number instead, which the owner finds among the agent's customers. And the
 * plans' calls at once, raised for busy websites, with one agent able to use them all.
 */
class AgentBusyLinesTest extends TestCase
{
    use RefreshDatabase;

    private const SITE = 'https://cairosmiles.com';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.gemini_tts.api_key' => 'test-key']);
        Http::fake(['*auth_tokens' => Http::response(['name' => 'auth_tokens/live-key'])]);
        Plan::factory()->free()->create(['monthly_chars' => 10000, 'voice_tier' => 'standard']);
        SiteSetting::set('agents_credits_per_minute', '8100', 'agents');
    }

    private function agent(int $lines, array $attributes = []): Agent
    {
        $plan = Plan::factory()->create(['slug' => 'plan-'.uniqid(), 'name' => 'Creator', 'price_cents' => 2200, 'voice_tier' => 'hd',
            'monthly_chars' => 20 * 20 * 8100, 'allow_overage' => false, 'is_active' => true, 'agents_enabled' => true, 'agent_concurrent_calls' => $lines]);
        $owner = User::factory()->create();
        Subscription::create(['user_id' => $owner->id, 'plan_id' => $plan->id, 'status' => 'active']);

        return Agent::create(array_merge([
            'user_id' => $owner->id, 'name' => 'Sara', 'business_name' => 'Cairo Smiles', 'business_type' => 'healthcare',
            'jobs' => ['answer'], 'language' => 'ar', 'dialect' => 'ar-EG', 'live_voice' => 'Achernar', 'speaker_id' => null,
            'status' => Agent::LIVE, 'max_call_seconds' => 600, 'first_message' => 'أهلًا بيك',
        ], $attributes));
    }

    private function api(string $path, array $body, string $ip = '203.0.113.9'): TestResponse
    {
        return $this->call('POST', '/agent-api/'.$path, [], [], [], ['CONTENT_TYPE' => 'text/plain;charset=UTF-8', 'REMOTE_ADDR' => $ip, 'HTTP_ORIGIN' => self::SITE], json_encode($body));
    }

    private function callAgent(Agent $agent, string $visitor): TestResponse
    {
        return $this->api('calls', ['key' => $agent->mainWidget()->public_key, 'visitor' => $visitor]);
    }

    private function endCall(TestResponse $start): void
    {
        $this->api('calls/'.$start->json('conversation').'/end', ['call_key' => $start->json('call_key'), 'reason' => 'done'])->assertOk();
    }

    public function test_a_caller_who_finds_every_line_busy_waits_and_the_first_to_wait_goes_first(): void
    {
        config(['listen.agents.calls_per_visitor_hour' => 2]);
        $agent = $this->agent(1);
        $first = $this->callAgent($agent, 'ali')->assertOk();

        // Both lines... the one line is taken: Mona waits first, then Omar behind her.
        $this->callAgent($agent, 'mona')->assertStatus(202)->assertJsonPath('error', 'lines')->assertJsonPath('position', 1)->assertJsonPath('retry_after', 5);
        $this->callAgent($agent, 'omar')->assertStatus(202)->assertJsonPath('position', 2);
        $this->assertSame(['count' => 2, 'reason' => 'lines'], array_intersect_key(AgentCalls::missedToday($agent), ['count' => 1, 'reason' => 1]));

        // Waiting is not a call: trying again every few seconds keeps her place, counts once, and
        // never uses up her calls an hour (two here).
        for ($i = 0; $i < 4; $i++) {
            $this->travel(5)->seconds();
            $this->callAgent($agent, 'mona')->assertStatus(202)->assertJsonPath('position', 1);
            $this->callAgent($agent, 'omar')->assertStatus(202)->assertJsonPath('position', 2);
        }
        $this->assertSame(2, AgentCalls::missedToday($agent)['count']);

        // The line frees: Omar, behind her, does not jump ahead; Mona gets through and is no
        // longer a missed call.
        $this->endCall($first);
        $this->callAgent($agent, 'omar')->assertStatus(202)->assertJsonPath('position', 2);
        $this->callAgent($agent, 'mona')->assertOk()->assertJsonStructure(['token', 'call_key']);
        $this->assertSame(1, AgentCalls::missedToday($agent)['count']);
        $this->callAgent($agent, 'omar')->assertStatus(202)->assertJsonPath('position', 1);
    }

    public function test_a_caller_who_stops_waiting_gives_up_their_place(): void
    {
        $agent = $this->agent(1);
        $first = $this->callAgent($agent, 'ali')->assertOk();
        $this->callAgent($agent, 'mona')->assertStatus(202)->assertJsonPath('position', 1);

        // Mona closed the page (no more tries): once the line is free, the next caller is through.
        $this->travel(20)->seconds();
        $this->endCall($first);
        $this->callAgent($agent, 'karim')->assertOk();
    }

    public function test_a_waiting_caller_leaves_a_number_and_the_owner_sees_it_with_customers(): void
    {
        $agent = $this->agent(1);
        $key = $agent->mainWidget()->public_key;
        $this->callAgent($agent, 'ali')->assertOk();
        $this->callAgent($agent, 'mona')->assertStatus(202);

        // Only a caller who was waiting for a line; a number that is a number.
        $this->api('callbacks', ['key' => $key, 'visitor' => 'stranger', 'phone' => '01001234567'])->assertStatus(429)->assertJsonPath('error', 'not_waiting');
        $this->api('callbacks', ['key' => $key, 'visitor' => 'mona', 'phone' => 'call me'])->assertStatus(422)->assertJsonPath('error', 'phone')
            ->assertJsonPath('message', trans('agents.callback.invalid_phone', [], 'ar'));
        $this->api('callbacks', ['key' => $key, 'visitor' => 'mona', 'name' => '<b>Mona</b>', 'phone' => '+20 100 123 4567'])->assertOk()->assertJsonPath('phone', '+201001234567');

        $contact = AgentContact::query()->sole();
        $this->assertSame(['Mona', '+201001234567', $agent->id], [$contact->name, $contact->phone, $contact->agent_id]);
        $this->assertSame(trans('agents.callback.note', [], 'ar'), $contact->note);
        $this->assertSame(1, AgentCalls::missedToday($agent)['callbacks']);

        // Arabic digits work as typed.
        $this->callAgent($agent, 'huda')->assertStatus(202);
        $this->api('callbacks', ['key' => $key, 'visitor' => 'huda', 'phone' => '٠١٠٠١٢٣٤٥٦٧'])->assertOk()->assertJsonPath('phone', '01001234567');

        // The owner: how many could not get through, how many left a number, where they are, and
        // a plan with more calls at once.
        $page = $this->actingAs($agent->user)->getLocalizedRoute('voice-agents.show', ['agent' => $agent])->assertOk();
        $page->assertSee(__('agents.missed_reasons.lines'))
            ->assertSee(trans_choice('agents.missed_callbacks', 2, ['count' => 2]))
            ->assertSee('data-ag-tab-go="customers"', false)->assertSee(__('agents.missed_more_lines'))
            ->assertSee('+201001234567');
    }

    public function test_one_agent_can_use_all_of_its_plans_calls_at_once(): void
    {
        config(['listen.agents.calls_per_visitor_hour' => 20]);
        $agent = $this->agent(10);
        for ($i = 1; $i <= 10; $i++) {
            $this->callAgent($agent, 'caller-'.$i)->assertOk();
        }
        $this->callAgent($agent, 'caller-11')->assertStatus(202)->assertJsonPath('error', 'lines');
    }

    public function test_the_call_button_and_the_link_page_have_the_words_for_a_busy_line(): void
    {
        $agent = $this->agent(1);
        $widget = $agent->mainWidget();
        $text = $this->withHeaders(['Origin' => self::SITE])->getJson('/agent-api/widgets/'.$widget->public_key)->assertOk()->json('text');
        $this->assertSame(trans('agents.lines.next', [], 'ar'), $text['lines']['next']);
        $this->assertSame(trans_choice('agents.lines.ahead', 2, ['count' => 2], 'ar'), $text['lines']['ahead'][2]);
        $this->assertStringContainsString(':count', $text['lines']['ahead']['many']);
        $this->assertSame(trans('agents.callback.text', ['business' => 'Cairo Smiles'], 'ar'), $text['callback']['text']);
        $this->assertStringContainsString(':phone', $text['callback']['sent_text']);
        $this->assertArrayNotHasKey('note', $text['callback']);

        $widget->forceFill(['link_enabled' => true])->save();
        $this->get('/talk/'.$widget->slug)->assertOk()->assertSee('data-ag-callback', false)
            ->assertSee(trans('agents.callback.send', [], 'ar'))->assertSee('\u0022callback\u0022', false);
    }

    public function test_more_calls_at_once_on_every_agent_plan_and_a_number_set_by_hand_stays(): void
    {
        foreach (['starter' => 2, 'creator' => 3, 'pro' => 5, 'studio_max' => 10, 'basic' => 0] as $slug => $calls) {
            $plan = Plan::query()->where('slug', $slug)->first() ?? Plan::factory()->make(['slug' => $slug]);
            $plan->forceFill(['name' => ucfirst($slug), 'is_active' => true, 'agents_enabled' => $calls > 0, 'agent_concurrent_calls' => $calls])->save();
        }
        // Changed by hand in Admin → Plans since the earlier migration: kept.
        DB::table('plans')->where('slug', 'pro')->update(['agent_concurrent_calls' => 7]);

        $migration = require database_path('migrations/2026_11_22_120000_more_calls_at_once_on_agent_plans.php');
        $migration->up();
        $calls = fn (): array => Plan::query()->whereIn('slug', ['starter', 'creator', 'pro', 'studio_max', 'basic'])->pluck('agent_concurrent_calls', 'slug')->map(fn ($n) => (int) $n)->all();
        $this->assertEquals(['starter' => 3, 'creator' => 5, 'pro' => 7, 'studio_max' => 20, 'basic' => 0], $calls());

        $migration->down();
        $this->assertEquals(['starter' => 2, 'creator' => 3, 'pro' => 7, 'studio_max' => 10, 'basic' => 0], $calls());
    }
}
