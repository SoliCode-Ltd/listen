<?php

namespace Tests\Feature\Agents;

use App\Models\Agent;
use App\Models\AgentConversation;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A call never ends as a surprise. With half a minute left the agent tells the caller, in the
 * call's language, and the timer counts down; in the last seconds it says a short goodbye, which
 * plays out; the card then says the call's time is up, how long it was, and offers to call again.
 * A call that ran to its limit is kept as ended for that reason.
 */
class CallTimeWarningTest extends TestCase
{
    use RefreshDatabase;

    private const SITE = 'https://cairosmiles.com';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.gemini_tts.api_key' => 'test-key']);
        Http::fake(['*auth_tokens' => Http::response(['name' => 'auth_tokens/live-key'])]);
        Plan::factory()->free()->create(['monthly_chars' => 10000, 'voice_tier' => 'standard']);
        \App\Models\SiteSetting::set('agents_credits_per_minute', '8100', 'agents');
    }

    private function liveAgent(string $language = 'ar'): Agent
    {
        $plan = Plan::factory()->create(['slug' => 'pro-'.uniqid(), 'price_cents' => 4500, 'voice_tier' => 'hd', 'monthly_chars' => 500000, 'allow_overage' => false,
            'agents_enabled' => true, 'agent_concurrent_calls' => 20]);
        $owner = User::factory()->create();
        Subscription::create(['user_id' => $owner->id, 'plan_id' => $plan->id, 'status' => 'active']);

        return Agent::create([
            'user_id' => $owner->id, 'name' => 'Sara', 'business_name' => 'Cairo Smiles', 'business_type' => 'healthcare',
            'jobs' => ['answer', 'leads'], 'language' => $language, 'dialect' => $language === 'ar' ? 'ar-EG' : 'en-US', 'live_voice' => 'Achernar',
            'status' => Agent::LIVE, 'max_call_seconds' => 180, 'first_message' => 'أهلًا بيك',
        ]);
    }

    private function callApi(string $path, array $body): TestResponse
    {
        return $this->call('POST', '/agent-api'.$path, [], [], [], ['CONTENT_TYPE' => 'text/plain;charset=UTF-8', 'REMOTE_ADDR' => '203.0.113.9', 'HTTP_ORIGIN' => self::SITE], json_encode($body));
    }

    private function card(Agent $agent): array
    {
        return (array) $this->withHeaders(['Origin' => self::SITE])->getJson('/agent-api/widgets/'.$agent->mainWidget()->public_key)->assertOk()->json('text');
    }

    public function test_the_card_carries_the_countdown_and_the_time_up_words_in_the_agents_language(): void
    {
        $arabic = $this->card($this->liveAgent('ar'));
        $this->assertSame('تنتهي خلال :time', $arabic['ends_in']);
        $this->assertSame('انتهى وقت المكالمة', $arabic['ended_limit']);
        $this->assertSame('مدة المكالمة :time', $arabic['lasted']);
        $this->assertSame('اتصل مجدداً', $arabic['again']);

        $english = $this->card($this->liveAgent('en'));
        $this->assertSame('Ends in :time', $english['ends_in']);
        $this->assertSame('Call time is up', $english['ended_limit']);
        $this->assertSame('Call length :time', $english['lasted']);
        $this->assertSame('Call again', $english['again']);
    }

    public function test_a_call_that_ran_to_its_limit_is_kept_as_ended_by_the_limit_whatever_the_agent_gave(): void
    {
        $agent = $this->liveAgent();
        $start = $this->callApi('/calls', ['key' => $agent->mainWidget()->public_key, 'visitor' => 'v-1'])->assertOk();
        $id = $start->json('conversation');
        $key = $start->json('call_key');
        $this->assertSame(180, $start->json('max_seconds'));

        // The server tells the browser how long is left, so the warning follows the real time.
        $this->assertGreaterThan(170, $this->callApi('/calls/'.$id.'/ping', ['call_key' => $key, 'turns' => []])->assertOk()->json('seconds_left'));

        // In its last seconds the agent said goodbye and hung up with its own reason: the limit stands.
        $this->callApi('/calls/'.$id.'/tool', ['call_key' => $key, 'name' => 'end_call', 'args' => ['reason' => 'resolved']])->assertOk();
        $this->callApi('/calls/'.$id.'/end', ['call_key' => $key, 'turns' => [['role' => 'agent', 'text' => 'انتهى وقت المكالمة، مع السلامة']], 'reason' => 'limit'])->assertOk();

        $call = AgentConversation::query()->where('public_id', $id)->sole();
        $this->assertSame('limit', $call->end_reason);
        $this->assertSame(AgentConversation::ENDED, $call->status);
        $this->assertSame('انتهى وقت المكالمة، مع السلامة', collect($call->transcript)->last()['text'], 'The goodbye is in the call\'s record.');
    }

    public function test_an_abusive_callers_reason_still_stands_at_the_limit_and_other_ends_keep_the_agents_reason(): void
    {
        $agent = $this->liveAgent();
        $start = $this->callApi('/calls', ['key' => $agent->mainWidget()->public_key, 'visitor' => 'v-2'])->assertOk();
        $this->callApi('/calls/'.$start->json('conversation').'/tool', ['call_key' => $start->json('call_key'), 'name' => 'end_call', 'args' => ['reason' => 'abusive']])->assertOk();
        $this->callApi('/calls/'.$start->json('conversation').'/end', ['call_key' => $start->json('call_key'), 'reason' => 'limit'])->assertOk();
        $this->assertSame('abusive', AgentConversation::query()->where('public_id', $start->json('conversation'))->sole()->end_reason);

        $start = $this->callApi('/calls', ['key' => $agent->mainWidget()->public_key, 'visitor' => 'v-3'])->assertOk();
        $this->callApi('/calls/'.$start->json('conversation').'/tool', ['call_key' => $start->json('call_key'), 'name' => 'end_call', 'args' => ['reason' => 'resolved']])->assertOk();
        $this->callApi('/calls/'.$start->json('conversation').'/end', ['call_key' => $start->json('call_key'), 'reason' => 'done'])->assertOk();
        $this->assertSame('resolved', AgentConversation::query()->where('public_id', $start->json('conversation'))->sole()->end_reason);
    }

    public function test_the_call_warns_at_half_a_minute_says_goodbye_and_lets_it_play_out(): void
    {
        $call = (string) file_get_contents(public_path('js/agent-call.js'));

        // Thirty seconds' notice (half the call when it is under a minute), a pause looked for first.
        $this->assertStringContainsString('var WARN_LEAD = 30, LAST_LEAD = 8, LIMIT_GRACE = 5000, WARN_WAIT = 6000;', $call);
        $this->assertStringContainsString('warnLead = Math.min(WARN_LEAD, Math.floor(total / 2)) * 1000;', $call);
        $this->assertStringContainsString('if (talking() && endsAt - Date.now() > warnLead)', $call);
        // Said by the agent in the call's language, as a note it must not read out or hang up on.
        $this->assertStringContainsString("left before this call reaches its time limit and ends. Tell the caller that now, in one short, warm sentence in ' + callTongue()", $call);
        $this->assertStringContainsString("Say one short, warm goodbye now in ' + callTongue() + ', a single sentence, then stop speaking. Do not call end_call", $call);
        // The end waits for the goodbye's sound, for a few seconds at most, and is kept as the limit.
        $this->assertStringContainsString("if (!sources.size || Date.now() - since > LIMIT_GRACE) { finish('limit'); return; }", $call);
        $this->assertStringContainsString("finish(lastWords ? 'limit' : 'done')", $call);
        // A call the server ended for another reason is not called a time limit.
        $this->assertStringContainsString("if (r.stop) { if (r.ended) finish('done'); else endAtLimit(); return; }", $call);
        $this->assertStringNotContainsString("stopTimer = setTimeout(function () { finish('limit'); }", $call, 'The call is no longer cut mid-word.');
        // The server's shorter count wins, and the card is told the reason before the state.
        $this->assertStringContainsString('if (left > 0 && endsAt && Date.now() + left * 1000 < endsAt - 1500)', $call);
        $this->assertStringContainsString("opts.onEnd && opts.onEnd(reason || 'done');", $call);
    }

    public function test_the_card_counts_down_and_says_why_the_call_ended(): void
    {
        $widget = (string) file_get_contents(public_path('js/agent-widget.js'));

        $this->assertStringContainsString("function timeText() { return ending() ? String(text.ends_in).replace(':time', clock(leftNow())) : clock(elapsed()); }", $widget);
        $this->assertStringContainsString("parts.pillTime.parentNode.classList.toggle('is-ending', ending());", $widget);
        $this->assertStringContainsString("onLimit: function (at, left, lead) { endsAt = Number(at) || 0; endsLead = Math.max(5, Number(lead) || 30); timer(); },", $widget);
        $this->assertStringContainsString("onEnd: function (reason) { endedWhy = String(reason || ''); },", $widget);
        $this->assertStringContainsString("function timeUp() { return stateNow === 'ended' && endedWhy === 'limit' && !!text.ended_limit; }", $widget);
        $this->assertStringContainsString('(timeUp() && text.again) || text.start', $widget);
        // An owner who hides the line under the orb before a call still has the time limit said.
        $this->assertStringContainsString("&& look.ready_show === false && !timeUp();", $widget);
        // A card cached before these words came keeps the plain timer and the old ending.
        // The countdown shows for the last half minute, even when the notice was said a little before.
        $this->assertStringContainsString("function ending() { return live() && endsAt > 0 && !!text.ends_in && leftNow() <= endsLead; }", $widget);
    }
}
