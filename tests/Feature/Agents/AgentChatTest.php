<?php

namespace Tests\Feature\Agents;

use App\Models\Agent;
use App\Models\AgentContact;
use App\Models\AgentConversation;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use App\Services\Agents\AgentChats;
use App\Support\Agents\AgentChannels;
use App\Support\Agents\AgentInstructions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ModelRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The written chat with an agent. The owner's plan holds the credits of the replies one
 * conversation may have; each reply uses a fixed number of credits, a message that got no answer
 * uses none, a message sent twice is charged once, and what was not used goes back when the chat
 * closes. Every message, each reply's credits, tokens and cost, and the details the customer left
 * are kept. A chat is never a call: its key opens none of a call's doors.
 */
class AgentChatTest extends TestCase
{
    use RefreshDatabase;

    private const SITE = 'https://cairosmiles.com';

    private const RATE = 50;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.gemini_tts.api_key' => 'test-key']);
        Plan::factory()->free()->create(['monthly_chars' => 10000, 'voice_tier' => 'standard']);
        \App\Models\SiteSetting::set('agents_credits_per_minute', '8100', 'agents');
        // A fixed rate for a reply, so the plans these tests make do not move it.
        \App\Models\SiteSetting::set('agents_chat_credits_per_reply', (string) self::RATE, 'agents');
        config(['listen.agents.chat_price_per_million' => ['in' => 0.0, 'out' => 0.0]]);
    }

    private function owner(int $credits = 500000): User
    {
        $plan = Plan::factory()->create(['slug' => 'pro-'.uniqid(), 'price_cents' => 4500, 'voice_tier' => 'hd', 'monthly_chars' => $credits, 'allow_overage' => false,
            'agents_enabled' => true, 'agent_concurrent_calls' => 20]);
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

    private function api(string $path, array $body, string $ip = '203.0.113.9'): TestResponse
    {
        return $this->call('POST', '/agent-api'.$path, [], [], [], ['CONTENT_TYPE' => 'text/plain;charset=UTF-8', 'REMOTE_ADDR' => $ip, 'HTTP_ORIGIN' => self::SITE], json_encode($body));
    }

    private function creditsUsed(User $user): int
    {
        return (int) UsageCounter::query()->where('user_id', $user->id)->sum('total_chars');
    }

    /** What the text model answers: words, with the tokens it counted. */
    private static function says(string $text, int $in = 1200, int $out = 40): array
    {
        return ['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => $text]]]]], 'usageMetadata' => ['promptTokenCount' => $in, 'candidatesTokenCount' => $out, 'thoughtsTokenCount' => 10]];
    }

    private static function calls(string $tool, array $args): array
    {
        return ['candidates' => [['content' => ['role' => 'model', 'parts' => [['functionCall' => ['name' => $tool, 'args' => $args]]]]]], 'usageMetadata' => ['promptTokenCount' => 1100, 'candidatesTokenCount' => 15]];
    }

    private function open(Agent $agent, string $visitor = 'v-1'): TestResponse
    {
        return $this->api('/chats', ['key' => $agent->mainWidget()->public_key, 'page_url' => self::SITE.'/prices', 'visitor' => $visitor]);
    }

    public function test_a_chat_holds_its_replies_charges_each_one_once_and_gives_the_rest_back(): void
    {
        Http::fake(['*:generateContent' => Http::sequence()
            ->push(self::says('التبييض بـ 900 جنيه.', 1500, 30))
            ->push(self::says('نفتح من 9 إلى 5.', 1600, 20))]);
        $owner = $this->owner();
        $agent = $this->liveAgent($owner);
        $replies = AgentChats::maxReplies();

        $start = $this->open($agent)->assertOk();
        $id = $start->json('conversation');
        $key = $start->json('chat_key');
        $this->assertSame($replies, $start->json('replies'));
        $this->assertSame($replies * self::RATE, $this->creditsUsed($owner), 'the whole conversation is held');

        $first = $this->api('/chats/'.$id.'/messages', ['chat_key' => $key, 'id' => 'm1', 'text' => 'بكام التبييض؟'])->assertOk();
        $this->assertSame('التبييض بـ 900 جنيه.', $first->json('reply'));
        $this->assertSame($replies - 1, $first->json('left'));

        // The same message again (a lost connection, a second tap): the same answer, no second charge, no second model call.
        $again = $this->api('/chats/'.$id.'/messages', ['chat_key' => $key, 'id' => 'm1', 'text' => 'بكام التبييض؟'])->assertOk();
        $this->assertSame('التبييض بـ 900 جنيه.', $again->json('reply'));
        Http::assertSentCount(1);

        $this->api('/chats/'.$id.'/messages', ['chat_key' => $key, 'id' => 'm2', 'text' => 'مواعيدكم؟'])->assertOk()->assertJsonPath('reply', 'نفتح من 9 إلى 5.');

        $chat = AgentConversation::query()->where('public_id', $id)->sole();
        $this->assertSame('chat', $chat->channel);
        $this->assertSame(AgentChats::OPEN, $chat->status);
        $this->assertSame(2 * self::RATE, $chat->credits);
        $this->assertSame(['chat' => true, 'replies' => 2, 'in' => 3100, 'out' => 70], $chat->usage);
        $this->assertSame(['caller', 'agent', 'caller', 'agent'], array_column($chat->transcript, 'role'));
        $this->assertSame(self::RATE, $chat->transcript[1]['credits']);
        $this->assertSame(['in' => 1500, 'out' => 40], $chat->transcript[1]['tokens']);
        $this->assertSame(self::SITE.'/prices', $chat->page_url);

        // The model read the chat's own instructions and the conversation so far.
        Http::assertSent(fn (ModelRequest $r): bool => str_contains((string) data_get($r->data(), 'systemInstruction.parts.0.text'), 'You answer its customers in a written chat')
            && data_get($r->data(), 'contents.0.parts.0.text') === 'بكام التبييض؟'
            && data_get($r->data(), 'contents.1.role') === 'model'
            && data_get($r->data(), 'contents.2.parts.0.text') === 'مواعيدكم؟'
            && ! in_array('end_call', array_column((array) data_get($r->data(), 'tools.0.functionDeclarations', []), 'name'), true));

        // Closed: two replies charged, the other thirty-eight given back.
        app(AgentChats::class)->close($chat);
        $chat->refresh();
        $this->assertSame(AgentConversation::ENDED, $chat->status);
        $this->assertSame(2 * self::RATE, $chat->credits);
        $this->assertSame(0, $chat->billed_seconds);
        $this->assertSame(2 * self::RATE, $this->creditsUsed($owner));
        // Closing twice changes nothing.
        app(AgentChats::class)->close($chat);
        $this->assertSame(2 * self::RATE, $this->creditsUsed($owner));
        // A closed chat takes no more messages.
        $this->api('/chats/'.$id.'/messages', ['chat_key' => $key, 'id' => 'm3', 'text' => 'شكراً'])->assertStatus(410)->assertJsonPath('error', 'closed');
    }

    public function test_a_message_that_got_no_answer_is_not_kept_or_charged_and_can_be_sent_again(): void
    {
        Http::fake(['*:generateContent' => Http::sequence()
            ->push(['error' => 'down'], 500)->push(['error' => 'down'], 500)
            ->push(self::says('أهلاً بك.'))]);
        $owner = $this->owner();
        $start = $this->open($this->liveAgent($owner))->assertOk();
        $id = $start->json('conversation');

        $this->api('/chats/'.$id.'/messages', ['chat_key' => $start->json('chat_key'), 'id' => 'm1', 'text' => 'مرحبا'])
            ->assertStatus(503)->assertJsonPath('error', 'failed')->assertJsonPath('message', 'لم تصل رسالتك. أرسلها مجدداً.');
        $chat = AgentConversation::query()->where('public_id', $id)->sole();
        $this->assertSame([], $chat->transcript);
        $this->assertSame(0, $chat->credits);

        $this->api('/chats/'.$id.'/messages', ['chat_key' => $start->json('chat_key'), 'id' => 'm1', 'text' => 'مرحبا'])->assertOk()->assertJsonPath('reply', 'أهلاً بك.');
        app(AgentChats::class)->close($chat->refresh());
        $this->assertSame(self::RATE, $this->creditsUsed($owner));
    }

    public function test_the_agent_saves_the_customers_details_and_shows_the_business_links(): void
    {
        Http::fake(['*:generateContent' => Http::sequence()
            ->push(self::calls('save_contact', ['name' => 'Mona', 'phone' => '+201001234567', 'note' => 'تبييض يوم السبت']))
            ->push(self::says('تم، سيتواصل معك الفريق قريباً.', 1300, 25))
            ->push(self::calls('show_links', ['networks' => ['instagram']]))
            ->push(self::says('كتبت لك الرابط هنا.', 1400, 12))]);
        $owner = $this->owner();
        $agent = $this->liveAgent($owner, ['settings' => ['links' => ['https://instagram.com/cairosmiles']]]);
        $start = $this->open($agent)->assertOk();
        $id = $start->json('conversation');
        $key = $start->json('chat_key');

        $this->api('/chats/'.$id.'/messages', ['chat_key' => $key, 'id' => 'm1', 'text' => 'اسمي منى ورقمي 01001234567 عايزة تبييض السبت'])->assertOk()
            ->assertJsonPath('reply', 'تم، سيتواصل معك الفريق قريباً.');
        $chat = AgentConversation::query()->where('public_id', $id)->sole();
        $this->assertSame('Mona', $chat->lead['name']);
        $this->assertSame('+201001234567', $chat->lead['phone']);
        $this->assertSame(1, AgentContact::query()->where('user_id', $owner->id)->where('phone', '+201001234567')->count());
        // One reply, one charge, both rounds' tokens counted.
        $this->assertSame(self::RATE, $chat->credits);
        $this->assertSame(1100 + 1300, $chat->usage['in']);
        // The second round answered the tool, as the model expects it.
        Http::assertSent(fn (ModelRequest $r): bool => data_get($r->data(), 'contents.2.parts.0.functionResponse.name') === 'save_contact'
            && data_get($r->data(), 'contents.2.parts.0.functionResponse.response.result') === 'saved');

        $links = $this->api('/chats/'.$id.'/messages', ['chat_key' => $key, 'id' => 'm2', 'text' => 'حسابكم على انستجرام؟'])->assertOk();
        $this->assertSame('https://www.instagram.com/cairosmiles', $links->json('links.0.url'));
        $this->assertSame('كتبت لك الرابط هنا.', $links->json('reply'));
        // The model heard that the links were written, never their addresses.
        Http::assertSent(fn (ModelRequest $r): bool => data_get($r->data(), 'contents.'.(count((array) data_get($r->data(), 'contents', [])) - 1).'.parts.0.functionResponse.name') === 'show_links'
            && ! str_contains(json_encode($r->data()['contents']), 'instagram.com/cairosmiles'));
        $kinds = array_map(fn ($t) => $t['kind'] ?? $t['role'], AgentConversation::query()->where('public_id', $id)->sole()->transcript);
        $this->assertSame(['caller', 'agent', 'caller', 'links', 'agent'], $kinds);
    }

    public function test_a_chat_is_never_a_call_and_a_wrong_key_opens_nothing(): void
    {
        Http::fake(['*:generateContent' => Http::response(self::says('أهلاً'))]);
        $owner = $this->owner();
        $start = $this->open($this->liveAgent($owner))->assertOk();
        $id = $start->json('conversation');
        $key = $start->json('chat_key');
        $held = $this->creditsUsed($owner);

        // Ending it as a call would hand the whole hold back: a chat's key opens none of a call's doors.
        $this->api('/calls/'.$id.'/end', ['call_key' => $key, 'reason' => 'done'])->assertNotFound();
        $this->api('/calls/'.$id.'/ping', ['call_key' => $key])->assertNotFound();
        $this->api('/calls/'.$id.'/tool', ['call_key' => $key, 'name' => 'save_contact', 'args' => ['phone' => '+201001234567']])->assertNotFound();
        $this->assertSame($held, $this->creditsUsed($owner));
        $this->assertSame(AgentChats::OPEN, AgentConversation::query()->where('public_id', $id)->sole()->status);

        $this->api('/chats/'.$id.'/messages', ['chat_key' => 'wrong', 'id' => 'm1', 'text' => 'مرحبا'])->assertNotFound();
        $this->api('/chats/'.$id.'/messages', ['chat_key' => $key, 'id' => 'm1', 'text' => '   '])->assertStatus(422)->assertJsonPath('error', 'empty');
        Http::assertNothingSent();
    }

    public function test_the_hold_follows_what_the_owner_can_pay_and_a_conversation_stops_at_its_replies(): void
    {
        Http::fake(['*:generateContent' => Http::response(self::says('تمام'))]);
        // Credits for three replies only.
        $owner = $this->owner(3 * self::RATE + 10);
        $agent = $this->liveAgent($owner);
        $start = $this->open($agent)->assertOk();
        $this->assertSame(3, $start->json('replies'));
        $this->assertSame(3 * self::RATE, $this->creditsUsed($owner));
        $id = $start->json('conversation');
        foreach (['a', 'b', 'c'] as $m) {
            $this->api('/chats/'.$id.'/messages', ['chat_key' => $start->json('chat_key'), 'id' => $m, 'text' => 'سؤال '.$m])->assertOk();
        }
        $this->api('/chats/'.$id.'/messages', ['chat_key' => $start->json('chat_key'), 'id' => 'd', 'text' => 'سؤال رابع'])
            ->assertStatus(410)->assertJsonPath('error', 'limit')->assertJsonPath('message', 'انتهت هذه المحادثة.');
        Http::assertSentCount(3);

        // Nothing left to hold a second conversation: the chat is not available, and its button says so.
        $this->open($agent, 'v-2')->assertStatus(409)->assertJsonPath('error', 'paused')->assertJsonPath('message', 'المحادثة غير متاحة الآن.');
        $this->assertFalse($this->withHeaders(['Origin' => self::SITE])->getJson('/agent-api/widgets/'.$agent->mainWidget()->public_key)->assertOk()->json('channels.chat'));
        // Never below zero.
        $this->assertSame(3 * self::RATE, $this->creditsUsed($owner));
    }

    public function test_the_owner_chooses_chat_calls_or_both_and_the_button_follows(): void
    {
        Http::fake(['*auth_tokens' => Http::response(['name' => 'auth_tokens/live-key'])]);
        $owner = $this->owner();
        $agent = $this->liveAgent($owner);
        $card = fn (): array => (array) $this->withHeaders(['Origin' => self::SITE])->getJson('/agent-api/widgets/'.$agent->mainWidget()->public_key.'?t='.uniqid())->assertOk()->json();

        // Both are on until the owner says otherwise.
        $this->assertSame(['chat' => true, 'call' => true], $card()['channels']);
        $this->assertSame('أهلًا بيك', $card()['chat']['greeting']);
        $this->assertSame('اكتب رسالتك', $card()['chat']['placeholder']);

        // Calls only.
        $this->assertTrue(AgentChannels::choose($agent, false, true));
        $agent->save();
        $this->assertSame(['chat' => false, 'call' => true], $card()['channels']);
        $this->open($agent)->assertStatus(409);
        $this->api('/calls', ['key' => $agent->mainWidget()->public_key, 'visitor' => 'v-1'])->assertOk();

        // Chat only: a call cannot start.
        $this->assertTrue(AgentChannels::choose($agent, true, false));
        $agent->save();
        $this->assertSame(['chat' => true, 'call' => false], $card()['channels']);
        $this->api('/calls', ['key' => $agent->mainWidget()->public_key, 'visitor' => 'v-2'])->assertStatus(409)->assertJsonPath('error', 'paused');
        $this->open($agent, 'v-2')->assertOk();

        // One always stays on.
        $this->assertFalse(AgentChannels::choose($agent, false, false));
        $this->assertTrue(AgentChannels::chat($agent));
    }

    public function test_a_visitor_gets_a_few_chats_an_hour_and_quiet_chats_are_closed(): void
    {
        Http::fake(['*:generateContent' => Http::response(self::says('أهلاً'))]);
        config(['listen.agents.chats_per_visitor_hour' => 2, 'listen.agents.chats_per_visitor_day' => 2]);
        $owner = $this->owner();
        $agent = $this->liveAgent($owner);
        $first = $this->open($agent)->assertOk();
        $this->open($agent)->assertOk();
        $this->open($agent)->assertStatus(429)->assertJsonPath('error', 'busy');

        // An hour later nobody has written: both are closed and every held credit is back.
        $this->api('/chats/'.$first->json('conversation').'/messages', ['chat_key' => $first->json('chat_key'), 'id' => 'm1', 'text' => 'مرحبا'])->assertOk();
        $this->travel(AgentChats::idleMinutes() + 1)->minutes();
        $this->artisan('agents:settle-calls')->assertSuccessful();
        $this->assertSame(0, AgentConversation::query()->where('status', AgentChats::OPEN)->count());
        $this->assertSame(self::RATE, $this->creditsUsed($owner));
        $this->assertSame('idle', AgentConversation::query()->where('public_id', $first->json('conversation'))->sole()->end_reason);
    }

    public function test_the_chat_instructions_are_written_for_writing_and_the_voice_ones_did_not_change(): void
    {
        $agent = $this->liveAgent($this->owner());
        $chat = AgentInstructions::build($agent, true);
        $voice = AgentInstructions::build($agent);

        $this->assertStringContainsString('You are Sara, the AI assistant of Cairo Smiles. You answer its customers in a written chat on its website.', $chat);
        $this->assertStringContainsString('# How you write', $chat);
        $this->assertStringContainsString('WRITE IN ARABIC. Reply in English only when the customer writes to you in English.', $chat);
        $this->assertStringContainsString('press the call button at the top of this chat', $chat);
        $this->assertStringContainsString('The customer has already read this greeting from you: "أهلًا بيك"', $chat);
        foreach (['# How you speak', 'end_call', 'digit by digit', 'AI voice assistant', 'read it back'] as $spoken) {
            $this->assertStringNotContainsString($spoken, $chat, "The chat's instructions still speak of a voice call: {$spoken}");
        }
        // The provider behind the agent is never named to a customer, in a chat either.
        $this->assertStringContainsString('Never name an AI model or the company that makes it.', $chat);

        $this->assertStringContainsString('You talk with its customers on a live voice call.', $voice);
        $this->assertStringContainsString('# How you speak', $voice);
        $this->assertStringNotContainsString('# How you write', $voice);

        // With calls switched off, the chat never points at a call button.
        AgentChannels::choose($agent, true, false);
        $this->assertStringNotContainsString('call button', AgentInstructions::build($agent, true));
    }
}
