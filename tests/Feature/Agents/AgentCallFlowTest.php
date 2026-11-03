<?php

namespace Tests\Feature\Agents;

use App\Models\Agent;
use App\Models\AgentContact;
use App\Models\AgentConversation;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use App\Services\Agents\AgentCalls;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Tests\TestCase;

/**
 * A caller's side of Agents: the link page, the call button's settings on a
 * website, and a call from start to settled, with the owner's credits held
 * for the longest call and the unused part given back.
 */
class AgentCallFlowTest extends TestCase
{
    use RefreshDatabase;

    private const SITE = 'https://cairosmiles.com';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.gemini_tts.api_key' => 'test-key']);
        Http::fake(['*auth_tokens' => Http::response(['name' => 'auth_tokens/live-key'])]);
        Plan::factory()->free()->create(['monthly_chars' => 10000, 'voice_tier' => 'standard']);
    }

    /** An owner on a plan with agents (room for many calls at once unless a test says otherwise). */
    private function owner(int $credits = 500000, array $plan = []): User
    {
        $plan = Plan::factory()->create(array_merge(['slug' => 'pro-'.uniqid(), 'price_cents' => 4500, 'voice_tier' => 'hd', 'monthly_chars' => $credits, 'allow_overage' => false,
            'agents_enabled' => true, 'agent_concurrent_calls' => 20], $plan));
        $user = User::factory()->create();
        Subscription::create(['user_id' => $user->id, 'plan_id' => $plan->id, 'status' => 'active']);

        return $user;
    }

    private function liveAgent(User $owner, array $attributes = []): Agent
    {
        return Agent::create(array_merge([
            'user_id' => $owner->id, 'name' => 'Sara', 'business_name' => 'Cairo Smiles', 'business_type' => 'healthcare',
            'jobs' => ['answer', 'leads'], 'language' => 'ar', 'dialect' => 'ar-EG', 'live_voice' => 'Achernar',
            'status' => Agent::LIVE, 'max_call_seconds' => 600, 'first_message' => 'أهلًا بيك',
        ], $attributes));
    }

    /** The call client sends JSON as text/plain, from the business's website. */
    private function callApi(string $path, array $body, ?string $origin = self::SITE): TestResponse
    {
        $server = ['CONTENT_TYPE' => 'text/plain;charset=UTF-8', 'REMOTE_ADDR' => '203.0.113.9'];
        if ($origin) {
            $server['HTTP_ORIGIN'] = $origin;
        }

        return $this->call('POST', '/agent-api'.$path, [], [], [], $server, json_encode($body));
    }

    private function creditsUsed(User $user): int
    {
        return (int) UsageCounter::query()->where('user_id', $user->id)->sum('total_chars');
    }

    public function test_the_link_page_belongs_to_the_business_and_is_never_indexed(): void
    {
        $agent = $this->liveAgent($this->owner());
        $slug = $agent->mainWidget()->slug;

        $page = $this->get('/talk/'.$slug)->assertOk();

        $page->assertSee('Cairo Smiles')->assertSee('data-ag-talk', false)
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            // Listen's own support chat is not on a business's page, nor the owner's costs.
            ->assertDontSee('id="whatsappWidget"', false)
            ->assertDontSee('data-ag-free', false);
        $this->get('/talk/no-such-agent')->assertNotFound();
    }

    public function test_the_call_button_loads_its_settings_only_where_it_is_allowed(): void
    {
        $agent = $this->liveAgent($this->owner());
        $widget = $agent->mainWidget();
        $widget->forceFill(['allowed_origins' => [self::SITE]])->save();

        $this->withHeaders(['Origin' => 'https://copycat.example'])->getJson('/agent-api/widgets/'.$widget->public_key)->assertForbidden();
        $this->getJson('/agent-api/widgets/lw_'.str_repeat('x', 28))->assertNotFound();

        $config = $this->withHeaders(['Origin' => self::SITE])->getJson('/agent-api/widgets/'.$widget->public_key.'?via=wordpress')
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', self::SITE)
            ->assertJsonPath('available', true)
            ->assertJsonPath('dir', 'rtl')
            ->json();
        $this->assertStringContainsString('/js/agent-widget.js?v=', $config['assets']['widget']);
        $this->assertStringContainsString('/js/agent-call.js?v=', $config['assets']['call']);
        // Where it was seen, so the owner knows it works on their website.
        $widget->refresh();
        $this->assertSame(self::SITE, $widget->last_seen_origin);
        $this->assertSame('wordpress', $widget->installed_via);
    }

    public function test_a_call_holds_the_longest_call_and_charges_only_what_was_used(): void
    {
        $owner = $this->owner();
        $agent = $this->liveAgent($owner);
        $key = $agent->mainWidget()->public_key;

        $start = $this->callApi('/calls', ['key' => $key, 'page_url' => self::SITE.'/prices', 'visitor' => 'v-1'])->assertOk();

        $this->assertSame('auth_tokens/live-key', $start->json('token'));
        $this->assertSame(600, $start->json('max_seconds'));
        $this->assertFalse($start->json('free'));
        $this->assertSame(AgentCalls::creditsFor(600), $this->creditsUsed($owner), 'the whole call is held');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/auth_tokens') && $r['uses'] === 1
            && $r['bidiGenerateContentSetup']['generationConfig']['speechConfig']['voiceConfig']['prebuiltVoiceConfig']['voiceName'] === 'Achernar');

        $id = $start->json('conversation');
        $callKey = $start->json('call_key');
        $this->callApi('/calls/'.$id.'/ping', ['call_key' => 'wrong', 'turns' => []])->assertNotFound();

        $this->travel(30)->seconds();
        $this->callApi('/calls/'.$id.'/ping', ['call_key' => $callKey, 'turns' => [['role' => 'agent', 'text' => 'أهلًا بيك'], ['role' => 'caller', 'text' => 'بكام التبييض؟']]])
            ->assertOk()->assertJsonPath('stop', false);
        $this->callApi('/calls/'.$id.'/tool', ['call_key' => $callKey, 'name' => 'save_contact', 'args' => ['name' => 'Ahmed', 'phone' => '+20 101 234 5678', 'note' => 'Whitening on Saturday']])
            ->assertOk()->assertJsonPath('result', 'saved');

        $this->travel(35)->seconds();
        $this->callApi('/calls/'.$id.'/end', ['call_key' => $callKey, 'turns' => [['role' => 'agent', 'text' => 'مع السلامة']], 'usage' => [
            'promptTokenCount' => 3000, 'responseTokenCount' => 2000,
            'promptTokensDetails' => [['modality' => 'TEXT', 'tokenCount' => 1000], ['modality' => 'AUDIO', 'tokenCount' => 2000]],
            'responseTokensDetails' => [['modality' => 'AUDIO', 'tokenCount' => 2000]],
        ], 'reason' => 'done'])->assertOk()->assertJsonPath('seconds', 65);

        $call = AgentConversation::where('public_id', $id)->sole();
        $this->assertSame(AgentConversation::ENDED, $call->status);
        $this->assertSame(65, $call->billed_seconds);
        $this->assertSame(AgentCalls::creditsFor(65), $call->credits);
        $this->assertSame(AgentCalls::creditsFor(65), $this->creditsUsed($owner), 'the unused part of the hold is given back');
        $this->assertCount(3, $call->transcript);
        $this->assertEqualsWithDelta(0.00075 + 0.006 + 0.024, (float) $call->cost_usd, 0.00001); // text in, audio in, audio out
        $this->assertSame('+201012345678', AgentContact::where('agent_id', $agent->id)->sole()->phone);

        // Ending twice never gives back twice.
        $this->callApi('/calls/'.$id.'/end', ['call_key' => $callKey, 'turns' => []])->assertOk();
        $this->assertSame(AgentCalls::creditsFor(65), $this->creditsUsed($owner));
    }

    public function test_a_call_whose_browser_went_quiet_is_charged_up_to_its_last_ping(): void
    {
        $owner = $this->owner();
        $agent = $this->liveAgent($owner);
        $start = $this->callApi('/calls', ['key' => $agent->mainWidget()->public_key, 'visitor' => 'v-2'])->assertOk();

        $this->travel(40)->seconds();
        $this->callApi('/calls/'.$start->json('conversation').'/ping', ['call_key' => $start->json('call_key')])->assertOk();
        $this->travel(5)->minutes();

        $this->assertSame(1, app(AgentCalls::class)->settleStale());

        $call = AgentConversation::where('public_id', $start->json('conversation'))->sole();
        $this->assertSame(40, $call->billed_seconds);
        $this->assertSame(AgentCalls::creditsFor(40), $this->creditsUsed($owner));
    }

    public function test_calls_stop_for_a_paused_agent_another_website_or_too_many_tries(): void
    {
        $owner = $this->owner();
        $agent = $this->liveAgent($owner);
        $widget = $agent->mainWidget();
        $widget->forceFill(['allowed_origins' => [self::SITE]])->save();

        $this->callApi('/calls', ['key' => $widget->public_key], 'https://copycat.example')->assertForbidden();
        $this->assertSame(0, AgentConversation::count());

        for ($i = 0; $i < 6; $i++) {
            $this->callApi('/calls', ['key' => $widget->public_key, 'visitor' => 'same'])->assertOk();
        }
        $this->callApi('/calls', ['key' => $widget->public_key, 'visitor' => 'same'])->assertStatus(429);

        $agent->forceFill(['status' => Agent::PAUSED])->save();
        $this->callApi('/calls', ['slug' => $widget->slug, 'visitor' => 'someone-new'], null)->assertStatus(409)->assertJsonPath('error', 'paused');
    }

    public function test_a_new_browser_id_never_buys_more_calls_from_one_network(): void
    {
        config(['listen.agents.calls_per_visitor_hour' => 1]);
        $key = $this->liveAgent($this->owner())->mainWidget()->public_key;

        for ($i = 1; $i <= 5; $i++) {
            $this->callApi('/calls', ['key' => $key, 'visitor' => 'browser-'.$i])->assertOk();
        }

        $this->callApi('/calls', ['key' => $key, 'visitor' => 'browser-new'])->assertStatus(429);
    }

    public function test_calls_that_stop_reporting_still_count_toward_the_eight_at_once(): void
    {
        config(['listen.agents.calls_per_visitor_hour' => 10]);
        $key = $this->liveAgent($this->owner())->mainWidget()->public_key;
        for ($i = 1; $i <= 8; $i++) {
            $this->callApi('/calls', ['key' => $key, 'visitor' => 'silent-'.$i])->assertOk();
        }

        $this->travel(2)->minutes();

        $this->callApi('/calls', ['key' => $key, 'visitor' => 'one-more'])->assertStatus(429)->assertJsonPath('error', 'busy');
    }

    public function test_an_owner_without_credits_is_never_named_to_the_caller(): void
    {
        $owner = $this->owner(100);
        $agent = $this->liveAgent($owner);

        $this->callApi('/calls', ['key' => $agent->mainWidget()->public_key])
            ->assertStatus(409)->assertJsonPath('error', 'paused')->assertJsonPath('message', __('agents.error_paused'));
        Http::assertNothingSent();
    }

    public function test_the_owners_first_test_minutes_are_free(): void
    {
        $owner = $this->owner();
        $agent = $this->liveAgent($owner, ['status' => Agent::READY]);
        $withoutLocale = fn () => $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class, LaravelLocalizationViewPath::class, ValidateCsrfToken::class]);

        $first = $withoutLocale()->actingAs($owner)->postJson($this->localizedRoute('voice-agents.test-call', ['agent' => $agent]))->assertOk();

        $this->assertTrue($first->json('free'));
        $this->assertSame((int) config('listen.agents.free_test_seconds'), $first->json('max_seconds'));
        $this->assertSame(0, $this->creditsUsed($owner));
        $this->travel(2)->minutes();
        $this->callApi('/calls/'.$first->json('conversation').'/end', ['call_key' => $first->json('call_key')], null)->assertOk();

        // The free seconds are used up: the next test call is held on the plan.
        $second = $withoutLocale()->actingAs($owner)->postJson($this->localizedRoute('voice-agents.test-call', ['agent' => $agent]))->assertOk();
        $this->assertFalse($second->json('free'));
        $this->assertSame(AgentCalls::creditsFor(600), $this->creditsUsed($owner));
        $this->assertNotNull($agent->fresh()->tested_at);
    }
}
