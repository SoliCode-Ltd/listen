<?php

namespace Tests\Feature\Agents;

use App\Filament\Resources\AgentConversationResource;
use App\Filament\Resources\AgentConversationResource\Pages\ListAgentConversations;
use App\Filament\Resources\AgentConversationResource\Pages\ViewAgentConversation;
use App\Models\Agent;
use App\Models\AgentConversation;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use App\Services\Agents\AgentChats;
use App\Support\Agents\AgentVisits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A visit: one visitor's chat and the calls made from the same card are one entry in the admin,
 * read in order. Each stays its own row with its own credits; what joins them is kept when the
 * later one starts (the chat a call was made from, the call a chat follows, or the same browser
 * on the same agent a moment later).
 */
class AgentVisitsTest extends TestCase
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
        Http::fake([
            '*auth_tokens' => Http::response(['name' => 'auth_tokens/live-key']),
            '*:generateContent' => Http::response(['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'التبييض بـ 900 جنيه.']]]]],
                'usageMetadata' => ['promptTokenCount' => 1200, 'candidatesTokenCount' => 40]]),
        ]);
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
            'status' => Agent::LIVE, 'max_call_seconds' => 600, 'first_message' => 'أهلًا بيك',
        ]);
    }

    private function api(string $path, array $body, string $ip = '203.0.113.9'): TestResponse
    {
        return $this->call('POST', '/agent-api'.$path, [], [], [], ['CONTENT_TYPE' => 'text/plain;charset=UTF-8', 'REMOTE_ADDR' => $ip, 'HTTP_ORIGIN' => self::SITE], json_encode($body));
    }

    private function row(TestResponse $started): AgentConversation
    {
        return AgentConversation::query()->where('public_id', $started->json('conversation'))->sole();
    }

    private function chat(Agent $agent, string $visitor = 'v-1', string $ip = '203.0.113.9', array $more = []): TestResponse
    {
        return $this->api('/chats', ['key' => $agent->mainWidget()->public_key, 'visitor' => $visitor] + $more, $ip)->assertOk();
    }

    private function callFrom(Agent $agent, string $visitor = 'v-1', string $ip = '203.0.113.9', array $more = []): TestResponse
    {
        return $this->api('/calls', ['key' => $agent->mainWidget()->public_key, 'visitor' => $visitor] + $more, $ip)->assertOk();
    }

    public function test_a_call_made_from_a_chat_and_the_chat_after_a_call_are_one_visit(): void
    {
        // A call is charged by the second: the clock stands still, so the call ended at once
        // costs nothing however slowly the test runs, and only the chat's reply is counted.
        $this->freezeTime();
        $agent = $this->liveAgent($this->owner());

        // A chat, then a call from its card: named by the browser with the chat's key, so it
        // joins even when the visitor's address changed meanwhile (a phone leaving the wifi).
        $chat = $this->chat($agent);
        $this->api('/chats/'.$chat->json('conversation').'/messages', ['chat_key' => $chat->json('chat_key'), 'id' => 'm1', 'text' => 'بكام التبييض؟'])->assertOk();
        $call = $this->callFrom($agent, 'v-1', '198.51.100.20', ['chat' => ['conversation' => $chat->json('conversation'), 'chat_key' => $chat->json('chat_key')]]);
        $this->assertNull($this->row($chat)->reservation['visit'] ?? null, 'the first conversation is where the visit began');
        $this->assertSame($this->row($chat)->id, $this->row($call)->reservation['visit']);
        $this->assertSame([$this->row($chat)->id, $this->row($call)->id], AgentVisits::parts($this->row($call))->modelKeys());
        // Joining a visit changes nothing of what the call holds or settles.
        $this->assertGreaterThan(0, (int) $this->row($call)->reservation['credits']);
        $this->api('/calls/'.$call->json('conversation').'/end', ['call_key' => $call->json('call_key'), 'reason' => 'done'])->assertOk();
        $this->assertSame(50, (int) UsageCounter::query()->sum('total_chars'), 'the reply is charged, the unanswered call gave its hold back');

        // The other way round: a call first, then a chat that names it.
        $first = $this->callFrom($agent, 'v-2', '203.0.113.40');
        $after = $this->chat($agent, 'v-2', '198.51.100.41', ['call' => ['conversation' => $first->json('conversation'), 'call_key' => $first->json('call_key')]]);
        $this->assertSame($this->row($first)->id, $this->row($after)->reservation['visit']);
        // A third one joins the same visit, not the one before it.
        $third = $this->callFrom($agent, 'v-2', '198.51.100.41', ['chat' => ['conversation' => $after->json('conversation'), 'chat_key' => $after->json('chat_key')]]);
        $this->assertSame($this->row($first)->id, $this->row($third)->reservation['visit']);
        $this->assertCount(3, AgentVisits::parts($this->row($first)));

        // Another agent's chat cannot be named to join its visit.
        $other = $this->liveAgent($this->owner());
        $foreign = $this->callFrom($other, 'v-9', '203.0.113.90', ['chat' => ['conversation' => $chat->json('conversation'), 'chat_key' => $chat->json('chat_key')]]);
        $this->assertNull($this->row($foreign)->reservation['visit'] ?? null);
    }

    public function test_the_same_browser_a_moment_later_is_the_same_visit_and_half_an_hour_later_a_new_one(): void
    {
        $agent = $this->liveAgent($this->owner());
        $one = $this->callFrom($agent);
        $this->api('/calls/'.$one->json('conversation').'/end', ['call_key' => $one->json('call_key'), 'reason' => 'done'])->assertOk();

        // Ten minutes later, the same browser calls again, with nothing naming the first call.
        $this->travel(10)->minutes();
        $two = $this->callFrom($agent);
        $this->assertSame($this->row($one)->id, $this->row($two)->reservation['visit']);
        // Someone else at the same moment is their own visit.
        $else = $this->callFrom($agent, 'v-other', '203.0.113.77');
        $this->assertNull($this->row($else)->reservation['visit'] ?? null);

        $this->api('/calls/'.$two->json('conversation').'/end', ['call_key' => $two->json('call_key'), 'reason' => 'done'])->assertOk();
        $this->travel(AgentVisits::GAP_MINUTES + 1)->minutes();
        $later = $this->callFrom($agent);
        $this->assertNull($this->row($later)->reservation['visit'] ?? null, 'a new visit');
    }

    public function test_the_admin_lists_one_row_a_visit_and_reads_it_whole(): void
    {
        $agent = $this->liveAgent($this->owner());
        $chat = $this->chat($agent);
        $this->api('/chats/'.$chat->json('conversation').'/messages', ['chat_key' => $chat->json('chat_key'), 'id' => 'm1', 'text' => 'بكام التبييض؟'])->assertOk();
        $call = $this->callFrom($agent, 'v-1', '203.0.113.9', ['chat' => ['conversation' => $chat->json('conversation'), 'chat_key' => $chat->json('chat_key')]]);
        $this->api('/calls/'.$call->json('conversation').'/ping', ['call_key' => $call->json('call_key'), 'turns' => [['role' => 'caller', 'text' => 'عايز أحجز ميعاد']]])->assertOk();
        $alone = $this->callFrom($agent, 'v-solo', '203.0.113.60');
        // After the call the visitor writes again, in the same chat.
        $this->travel(2)->minutes();
        $this->api('/chats/'.$chat->json('conversation').'/messages', ['chat_key' => $chat->json('chat_key'), 'id' => 'm2', 'text' => 'شكراً، في انتظار التأكيد'])->assertOk();
        // The call is still on.
        $this->api('/calls/'.$call->json('conversation').'/ping', ['call_key' => $call->json('call_key'), 'turns' => []])->assertOk();
        $chatRow = $this->row($chat);
        $callRow = $this->row($call);
        $admin = User::factory()->create(['is_admin' => true]);

        // The list: the visit once (under the chat it began with), the lone call as before.
        Livewire::actingAs($admin)->test(ListAgentConversations::class)
            ->assertCanSeeTableRecords([$chatRow, $this->row($alone)])
            ->assertCanNotSeeTableRecords([$callRow])
            ->assertSee('Chat (website)')->assertSee('Website button')
            ->assertSee('2 replies · 2:0')
            // Live while its call is, and in both tabs: it has a chat and a call.
            ->set('activeTab', 'live')->assertCanSeeTableRecords([$chatRow])
            ->set('activeTab', 'chats')->assertCanSeeTableRecords([$chatRow])->assertCanNotSeeTableRecords([$this->row($alone)])
            ->set('activeTab', 'voice')->assertCanSeeTableRecords([$chatRow, $this->row($alone)]);

        // Its page: the parts with their figures, then everything written and said, in order.
        Livewire::actingAs($admin)->test(ViewAgentConversation::class, ['record' => $chatRow->getRouteKey()])
            ->assertSee('This visit')
            ->assertSee('One visit of 2 conversations')
            ->assertSee('Written chat')->assertSee('Voice call')
            ->assertSee('The whole visit, in order')
            // In the order things happened: the chat, the call, then the chat again.
            ->assertSeeInOrder(['Written chat', 'بكام التبييض؟', 'التبييض بـ 900 جنيه.', 'Voice call', 'عايز أحجز ميعاد', 'Written chat, continued', 'شكراً، في انتظار التأكيد'])
            ->assertSee(AgentConversationResource::getUrl('view', ['record' => $callRow]), false);
        // The call's own page opens too, and shows the same visit.
        Livewire::actingAs($admin)->test(ViewAgentConversation::class, ['record' => $callRow->getRouteKey()])
            ->assertSee('This visit')->assertSee('بكام التبييض؟')
            ->assertSee(AgentConversationResource::getUrl('view', ['record' => $chatRow]), false);
        // A conversation that is a whole visit reads as it did.
        Livewire::actingAs($admin)->test(ViewAgentConversation::class, ['record' => $this->row($alone)->getRouteKey()])
            ->assertDontSee('This visit')->assertSee('Conversation');
    }

    public function test_conversations_kept_before_visits_were_are_joined_once(): void
    {
        $owner = $this->owner();
        $agent = $this->liveAgent($owner);
        $make = fn (string $channel, string $visitor, int $minutesAgo, array $more = []): AgentConversation => AgentConversation::create([
            'agent_id' => $agent->id, 'user_id' => $owner->id, 'channel' => $channel, 'visitor_hash' => hash('sha256', $visitor), 'call_key_hash' => hash('sha256', uniqid()),
            'status' => AgentConversation::ENDED, 'started_at' => now()->subMinutes($minutesAgo), 'ended_at' => now()->subMinutes($minutesAgo - 2), 'last_seen_at' => now()->subMinutes($minutesAgo - 2),
            'free' => true, 'transcript' => [],
        ] + $more);
        $chat = $make('chat', 'a', 120);
        $call = $make('widget', 'a', 105);          // 13 minutes after the chat's last sign of life
        $again = $make('widget', 'a', 30);          // over an hour later: a new visit
        $other = $make('widget', 'b', 104);         // someone else
        $named = $make('chat', 'c', 20, ['reservation' => ['after_call' => $other->id]]); // another address, but kept as following that call
        $test = $make('test', 'a', 100);            // the owner's own test

        $this->artisan('agents:settle-calls')->assertSuccessful();
        $visit = fn (AgentConversation $c) => $c->refresh()->reservation['visit'] ?? null;
        $this->assertNull($visit($chat));
        $this->assertSame($chat->id, $visit($call));
        $this->assertNull($visit($again));
        $this->assertNull($visit($other));
        $this->assertSame($other->id, $visit($named));
        $this->assertNull($visit($test));

        // Once: a second run joins nothing more (a later one is joined as it starts).
        $late = $make('widget', 'a', 28);
        $this->artisan('agents:settle-calls')->assertSuccessful();
        $this->assertNull($visit($late));
        $this->assertSame(1, AgentVisits::joinPast(), 'asked for again, it joins what it finds and never one twice');
        $this->assertSame($again->id, $visit($late));
        $this->assertSame(0, AgentVisits::joinPast());
    }

    public function test_chats_and_money_are_as_they_were_for_a_conversation_in_a_visit(): void
    {
        // A call is charged by the second: the clock stands still, so this one, ended at once,
        // costs nothing however slowly the test runs, and only the chat's replies are counted.
        $this->freezeTime();
        $owner = $this->owner();
        $agent = $this->liveAgent($owner);
        $call = $this->callFrom($agent);
        $this->api('/calls/'.$call->json('conversation').'/end', ['call_key' => $call->json('call_key'), 'reason' => 'done'])->assertOk();
        // The chat joins the call's visit, then is charged a reply at a time as any chat.
        $chat = $this->chat($agent);
        $row = $this->row($chat);
        $this->assertSame($this->row($call)->id, $row->reservation['visit']);
        foreach (['a', 'b'] as $m) {
            $this->api('/chats/'.$chat->json('conversation').'/messages', ['chat_key' => $chat->json('chat_key'), 'id' => $m, 'text' => 'سؤال'])->assertOk();
        }
        $row->refresh();
        $this->assertSame($this->row($call)->id, $row->reservation['visit'], 'kept through every reply');
        $this->assertSame(100, $row->credits);
        app(AgentChats::class)->close($row, 'closed');
        $this->assertSame(100, (int) UsageCounter::query()->where('user_id', $owner->id)->sum('total_chars'));
    }
}
