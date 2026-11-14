<?php

namespace Tests\Feature\Agents;

use App\Models\Agent;
use App\Models\AgentWidgetSite;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Agents\AgentChannels;
use App\Support\Agents\AgentStamp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ModelRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * One agent, open in several places: its owner's page on a computer, the same page in the phone
 * app, and the card on visitors' screens. A change made in one reaches the others without a
 * reload: the owner's page compares the agent's mark when it is looked at again, the card reads
 * its settings again, and an open chat answers its next message from the facts as they are now.
 */
class AgentSyncTest extends TestCase
{
    use RefreshDatabase;

    private const SITE = 'https://cairosmiles.com';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.gemini_tts.api_key' => 'test-key']);
        Plan::factory()->free()->create(['monthly_chars' => 10000, 'voice_tier' => 'standard']);
        SiteSetting::set('agents_credits_per_minute', '8100', 'agents');
        SiteSetting::set('agents_chat_credits_per_reply', '50', 'agents');
        config(['listen.agents.chat_price_per_million' => ['in' => 0.0, 'out' => 0.0, 'cached' => 0.0], 'listen.agents.chat_price_changes' => []]);
    }

    private function owner(): User
    {
        $plan = Plan::factory()->create(['slug' => 'pro-'.uniqid(), 'price_cents' => 4500, 'voice_tier' => 'hd', 'monthly_chars' => 500000, 'allow_overage' => false,
            'agents_enabled' => true, 'agent_concurrent_calls' => 20]);
        $user = User::factory()->create();
        Subscription::create(['user_id' => $user->id, 'plan_id' => $plan->id, 'status' => 'active']);

        return $user;
    }

    private function liveAgent(User $owner): Agent
    {
        return Agent::create([
            'user_id' => $owner->id, 'name' => 'Sara', 'business_name' => 'Cairo Smiles', 'business_type' => 'healthcare',
            'jobs' => ['answer', 'leads'], 'language' => 'ar', 'dialect' => 'ar-EG', 'live_voice' => 'Achernar',
            'status' => Agent::LIVE, 'max_call_seconds' => 600, 'first_message' => 'أهلًا بيك', 'knowledge' => 'التبييض بـ 900 جنيه.',
        ]);
    }

    private function api(string $path, array $body): TestResponse
    {
        return $this->call('POST', '/agent-api'.$path, [], [], [], ['CONTENT_TYPE' => 'text/plain;charset=UTF-8', 'REMOTE_ADDR' => '203.0.113.9', 'HTTP_ORIGIN' => self::SITE], json_encode($body));
    }

    public function test_the_agents_mark_changes_with_what_its_owner_edits_and_with_nothing_else(): void
    {
        $owner = $this->owner();
        $agent = $this->liveAgent($owner);
        $widget = $agent->mainWidget();
        $mark = AgentStamp::of($agent);

        // What happens by itself never makes a page think it is out of date: a website loading
        // the button, a visitor's chat, the agent's own reviews.
        $this->withHeaders(['Origin' => self::SITE])->getJson('/agent-api/widgets/'.$widget->public_key)->assertOk();
        $widget->refresh()->forceFill(['last_seen_at' => now(), 'last_seen_origin' => self::SITE])->save();
        $agent->refresh()->settings = ['review' => ['hash' => 'abc', 'at' => now()->toIso8601String()]] + (array) $agent->settings;
        $agent->save();
        $this->assertSame($mark, AgentStamp::of($agent->refresh()));

        // What the owner edits does: the knowledge, a channel, the button's look.
        $agent->update(['knowledge' => 'التبييض بـ 1200 جنيه.']);
        $this->assertNotSame($mark, $afterKnowledge = AgentStamp::of($agent->refresh()));
        AgentChannels::choose($agent, true, false);
        $agent->save();
        $this->assertNotSame($afterKnowledge, $afterChannels = AgentStamp::of($agent->refresh()));
        $widget->refresh()->appearance = ['accent' => '#0E9F6E'] + (array) $widget->appearance;
        $widget->save();
        $this->assertNotSame($afterChannels, AgentStamp::of($agent->refresh()));

        // The owner's page asks for it; nobody else can.
        $noLocale = [\Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class, \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath::class];
        $url = $this->localizedRoute('voice-agents.stamp', ['agent' => $agent]);
        $this->actingAs($owner)->withoutMiddleware($noLocale)->getJson($url)->assertOk()->assertJsonPath('stamp', AgentStamp::of($agent->refresh()))->assertHeader('Cache-Control', 'no-store, private');
        $this->actingAs($this->owner())->withoutMiddleware($noLocale)->getJson($url)->assertNotFound();

        // The page carries the mark it loaded with, and where to ask again.
        app()->setLocale('en');
        $this->actingAs($owner)->getLocalizedRoute('voice-agents.show', ['agent' => $agent])->assertOk()
            ->assertSee(AgentStamp::of($agent->refresh()), false)
            ->assertSee('This agent was changed on another device.')
            ->assertSee('data-ag-elsewhere', false);
    }

    public function test_the_card_is_read_again_without_counting_as_a_page_load_and_is_never_kept_in_between(): void
    {
        $owner = $this->owner();
        $agent = $this->liveAgent($owner);
        $widget = $agent->mainWidget();

        // A card already on a page reads its settings again: nothing is noted for it.
        $this->withHeaders(['Origin' => self::SITE])->getJson('/agent-api/widgets/'.$widget->public_key.'?again=1')->assertOk()
            ->assertJsonPath('channels.call', true)
            ->assertHeader('Cache-Control', 'max-age=10, private');
        $this->assertNull($widget->refresh()->last_seen_at);
        $this->assertSame(0, AgentWidgetSite::query()->count());
        // A page load is noted, as before.
        $this->withHeaders(['Origin' => self::SITE])->getJson('/agent-api/widgets/'.$widget->public_key)->assertOk();
        $this->assertNotNull($widget->refresh()->last_seen_at);

        // Calls switched off: the very next read says so.
        AgentChannels::choose($agent, true, false);
        $agent->save();
        $this->withHeaders(['Origin' => self::SITE])->getJson('/agent-api/widgets/'.$widget->public_key.'?again=1')->assertOk()
            ->assertJsonPath('channels.call', false)->assertJsonPath('channels.chat', true);

        // The link page's card has its own address to read again, and none once the page is off.
        $this->getJson('/agent-api/links/'.$widget->slug)->assertOk()->assertJsonPath('channels.call', false)->assertHeader('Cache-Control', 'no-store, private');
        $widget->update(['link_enabled' => false]);
        $this->getJson('/agent-api/links/'.$widget->slug)->assertNotFound();
        $this->getJson('/agent-api/links/no-such-page')->assertNotFound();
    }

    public function test_an_open_chat_answers_from_the_facts_as_they_are_now(): void
    {
        Http::fake(['*:generateContent' => Http::response(['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'تمام']]]]],
            'usageMetadata' => ['promptTokenCount' => 1200, 'candidatesTokenCount' => 40]])]);
        $owner = $this->owner();
        $agent = $this->liveAgent($owner);
        $chat = $this->api('/chats', ['key' => $agent->mainWidget()->public_key, 'visitor' => 'v-1'])->assertOk();
        $say = fn (string $id, string $text) => $this->api('/chats/'.$chat->json('conversation').'/messages', ['chat_key' => $chat->json('chat_key'), 'id' => $id, 'text' => $text])->assertOk();
        $system = fn (ModelRequest $r): string => (string) data_get($r->data(), 'systemInstruction.parts.0.text');

        $say('m1', 'بكام التبييض؟');
        $say('m2', 'ومواعيدكم؟');
        // Nothing changed: nothing is said about an update.
        Http::assertSent(fn (ModelRequest $r): bool => str_contains($system($r), 'التبييض بـ 900 جنيه.') && ! str_contains($system($r), '# Updated during this conversation'));

        // The owner corrects the price from their phone while the visitor's chat is open.
        $this->travel(2)->minutes();
        $agent->update(['knowledge' => 'التبييض بـ 1200 جنيه.']);
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['*:generateContent' => Http::response(['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'السعر الآن 1200 جنيه.']]]]],
            'usageMetadata' => ['promptTokenCount' => 1200, 'candidatesTokenCount' => 40]])]);
        $this->travel(1)->minutes();
        $say('m3', 'يعني كام بالضبط؟');
        Http::assertSent(fn (ModelRequest $r): bool => str_contains($system($r), 'التبييض بـ 1200 جنيه.')
            && ! str_contains($system($r), '900 جنيه')
            && str_contains($system($r), "# Updated during this conversation\nCairo Smiles changed its information after your last answer here.")
            && str_contains($system($r), 'also where it differs from what you said earlier in this conversation'));

        // Once it has answered from them, they are simply the facts again.
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['*:generateContent' => Http::response(['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'العفو']]]]],
            'usageMetadata' => ['promptTokenCount' => 1200, 'candidatesTokenCount' => 40]])]);
        $this->travel(1)->minutes();
        $say('m4', 'شكراً');
        Http::assertSent(fn (ModelRequest $r): bool => str_contains($system($r), '1200 جنيه') && ! str_contains($system($r), '# Updated during this conversation'));
    }

    public function test_the_card_and_the_owners_page_keep_themselves_current(): void
    {
        $widget = (string) file_get_contents(public_path('js/agent-widget.js'));
        $page = (string) file_get_contents(public_path('js/agents.js'));

        // The card: read again as it opens, when the page is looked at again, every half minute
        // while it is open, and after the server refused a call or a message.
        $this->assertStringContainsString("'/agent-api/widgets/' + encodeURIComponent(ctx.key) + '?again=1'", $widget);
        $this->assertStringContainsString("'/agent-api/links/' + encodeURIComponent(ctx.slug)", $widget);
        $this->assertStringContainsString('if (v) refreshCard(false);', $widget);
        $this->assertStringContainsString("document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'visible') refreshCard(false); });", $widget);
        $this->assertStringContainsString('setInterval(function () { if (open || inline) refreshCard(false); }, 30000);', $widget);
        $this->assertStringContainsString("if ((s === 'error' || s === 'ended') && before !== s) setTimeout(function () { refreshCard(true); }", $widget);
        // Never under a call or a message on its way, and only when something changed.
        $this->assertStringContainsString("function cardBusy() { return live() || starting || sending || stateNow === 'waiting' || stateNow === 'busy' || !!demo; }", $widget);
        $this->assertStringContainsString('if (none || !next.text || cardMark(next) === cardMark(cfg)) return;', $widget);
        // "Call started" is written when the call is on, not when the button is pressed.
        $this->assertStringContainsString("if (chatOn && (s === 'listening' || s === 'speaking') && !callNoted) { callNoted = true; chatNote(chatText.call_started || ''); }", $widget);

        // The owner's page: asks when looked at again; loads the latest unless something is
        // typed and not saved, or a test call is on (then a line with one button).
        $this->assertStringContainsString("document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'visible') check(true); });", $page);
        $this->assertStringContainsString('if (!dirty && !busy()) { latest(); return; }', $page);
        $this->assertStringContainsString("if (method !== 'GET' && typeof window.__agSaved === 'function') window.__agSaved();", $page);
        $this->assertStringContainsString('window.__agOnCall = !!live;', $page);
    }
}
