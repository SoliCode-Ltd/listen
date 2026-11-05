<?php

namespace Tests\Feature\Agents;

use App\Models\Agent;
use App\Models\AgentWidget;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Agents\AgentSpeech;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The studio voice in a call (AgentSpeech): an agent set to it speaks its words with the studio's
 * speech model, a phrase at a time, streamed; only while its call is live, only as much as the call
 * may say, and what it costs counts in the call's cost. The live voice stays the fallback.
 */
class AgentSpeechTest extends TestCase
{
    use RefreshDatabase;

    private const SITE = 'https://cairosmiles.com';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.gemini_tts.api_key' => 'test-key']);
        Plan::factory()->free()->create(['monthly_chars' => 10000, 'voice_tier' => 'standard']);
        \App\Models\SiteSetting::set('agents_credits_per_minute', '8100', 'agents');
    }

    private function agent(bool $studio = true): Agent
    {
        $plan = Plan::factory()->create(['slug' => 'pro-'.uniqid(), 'price_cents' => 4500, 'voice_tier' => 'hd', 'monthly_chars' => 500000, 'allow_overage' => false,
            'agents_enabled' => true, 'agent_concurrent_calls' => 20]);
        $owner = User::factory()->create();
        Subscription::create(['user_id' => $owner->id, 'plan_id' => $plan->id, 'status' => 'active']);

        return Agent::create([
            'user_id' => $owner->id, 'name' => 'Sara', 'business_name' => 'Cairo Smiles', 'business_type' => 'healthcare',
            'jobs' => ['answer'], 'language' => 'ar', 'dialect' => 'ar-EG', 'live_voice' => 'Achernar',
            'status' => Agent::LIVE, 'max_call_seconds' => 600, 'first_message' => 'أهلًا بيك',
            'settings' => $studio ? ['voice_engine' => 'studio'] : [],
        ]);
    }

    /** @return array{0: TestResponse, 1: string, 2: string} the call's start, its id and key */
    private function startCall(Agent $agent): array
    {
        $widget = $agent->mainWidget();
        $widget->forceFill(['allowed_origins' => [self::SITE]])->save();
        $start = $this->call('POST', '/agent-api/calls', [], [], [], ['CONTENT_TYPE' => 'text/plain;charset=UTF-8', 'REMOTE_ADDR' => '203.0.113.9', 'HTTP_ORIGIN' => self::SITE],
            json_encode(['key' => $widget->public_key, 'visitor' => 'v-'.uniqid()]))->assertOk();

        return [$start, (string) $start->json('conversation'), (string) $start->json('call_key')];
    }

    private function speak(string $conversation, array $body): TestResponse
    {
        return $this->call('POST', "/agent-api/calls/{$conversation}/speak", [], [], [], ['CONTENT_TYPE' => 'text/plain;charset=UTF-8', 'HTTP_ORIGIN' => self::SITE], json_encode($body));
    }

    /** The speech model's streamed answer: two pieces of PCM, then its token counts. */
    private function fakeVoice(string $first = "\x01\x00\x02\x00", string $second = "\x03\x00"): void
    {
        $event = fn (array $e) => 'data: '.json_encode($e)."\r\n\r\n";
        Http::fake([
            '*auth_tokens' => Http::response(['name' => 'auth_tokens/live-key']),
            '*:streamGenerateContent*' => Http::response(
                $event(['candidates' => [['content' => ['parts' => [['inlineData' => ['mimeType' => 'audio/L16;codec=pcm;rate=24000', 'data' => base64_encode($first)]]]]]]])
                .$event(['candidates' => [['content' => ['parts' => [['inlineData' => ['mimeType' => 'audio/L16;codec=pcm;rate=24000', 'data' => base64_encode($second)]]]]]],
                    'usageMetadata' => ['promptTokenCount' => 200, 'candidatesTokenCount' => 1000]]),
                200, ['Content-Type' => 'text/event-stream']),
        ]);
    }

    public function test_an_agent_set_to_the_studio_voice_says_so_when_its_call_starts(): void
    {
        Http::fake(['*auth_tokens' => Http::response(['name' => 'auth_tokens/live-key'])]);
        [$start] = $this->startCall($this->agent(true));
        $this->assertSame('studio', $start->json('voice'));

        [$start] = $this->startCall($this->agent(false));
        $this->assertSame('live', $start->json('voice'));
    }

    public function test_it_streams_the_phrase_in_the_agents_voice_and_dialect_and_counts_its_cost(): void
    {
        $this->fakeVoice();
        $agent = $this->agent(true);
        [, $conversation, $key] = $this->startCall($agent);

        $response = $this->speak($conversation, ['call_key' => $key, 'text' => "  أيوه طبعًا،\u{200B} ثانية بس أشوف المواعيد  "]);
        $response->assertOk();
        $this->assertSame('application/octet-stream', $response->headers->get('Content-Type'));
        $this->assertSame('no', $response->headers->get('X-Accel-Buffering'));
        $this->assertSame(self::SITE, $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertSame("\x01\x00\x02\x00\x03\x00", $response->streamedContent());

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'gemini-3.8-flash-tts:streamGenerateContent')) {
                return false;
            }
            $part = $request['contents'][0]['parts'][0];
            $style = $part['speech_metadata']['style'];

            return $part['text'] === 'أيوه طبعًا، ثانية بس أشوف المواعيد'
                && $request['generationConfig']['speechConfig']['voiceConfig']['voice'] === 'Achernar'
                && str_contains($style, 'Egyptian Arabic (Cairene)')
                && str_contains($style, 'Cairene: ج as g')
                && str_contains($style, 'who works at Cairo Smiles, talking with a customer on the phone')
                && str_contains($style, 'Not a presenter, an announcer or someone reading');
        });

        // 200 tokens in and 1,000 out at the speech model's price, added to the call's cost when it ends.
        $c = \App\Models\AgentConversation::where('public_id', $conversation)->firstOrFail();
        $this->assertEqualsWithDelta(\App\Support\GeminiSpend::price(AgentSpeech::MODEL, 200, 1000), AgentSpeech::spent($c), 1e-6);
        $this->call('POST', "/agent-api/calls/{$conversation}/end", [], [], [], ['CONTENT_TYPE' => 'text/plain;charset=UTF-8'],
            json_encode(['call_key' => $key, 'usage' => ['promptTokensDetails' => [], 'responseTokensDetails' => []], 'reason' => 'done']))->assertOk();
        $this->assertEqualsWithDelta(AgentSpeech::spent($c), (float) $c->fresh()->cost_usd, 1e-5);
    }

    public function test_only_a_live_call_of_a_studio_voice_agent_speaks_and_only_as_much_as_it_may(): void
    {
        $this->fakeVoice();
        // An agent on the live voice: nothing to say here.
        [, $conversation, $key] = $this->startCall($this->agent(false));
        $this->speak($conversation, ['call_key' => $key, 'text' => 'أهلًا'])->assertNotFound();

        [, $conversation, $key] = $this->startCall($this->agent(true));
        // Not the call's key, or nothing to say.
        $this->speak($conversation, ['call_key' => 'wrong', 'text' => 'أهلًا'])->assertNotFound();
        $this->speak($conversation, ['call_key' => $key, 'text' => " \u{200B} "])->assertStatus(422);
        // A phrase is at most MAX_CHARS long.
        $this->assertSame(AgentSpeech::MAX_CHARS, mb_strlen(AgentSpeech::clean(str_repeat('أ', 1000))));

        // A 10-minute call may say 10 × CHARS_PER_MINUTE characters, then no more.
        $c = \App\Models\AgentConversation::where('public_id', $conversation)->firstOrFail();
        $phrase = str_repeat('ب', AgentSpeech::MAX_CHARS);
        $allowed = intdiv(10 * AgentSpeech::CHARS_PER_MINUTE, AgentSpeech::MAX_CHARS);
        for ($i = 0; $i < $allowed; $i++) {
            $this->assertTrue(AgentSpeech::reserve($c, $phrase));
        }
        $this->speak($conversation, ['call_key' => $key, 'text' => $phrase])->assertStatus(429);

        // An ended call says nothing more.
        [, $conversation, $key] = $this->startCall($this->agent(true));
        $this->call('POST', "/agent-api/calls/{$conversation}/end", [], [], [], ['CONTENT_TYPE' => 'text/plain;charset=UTF-8'], json_encode(['call_key' => $key, 'reason' => 'done']))->assertOk();
        $this->speak($conversation, ['call_key' => $key, 'text' => 'أهلًا'])->assertNotFound();
    }

    public function test_when_the_speech_model_refuses_the_call_hears_the_live_voice(): void
    {
        Http::fake([
            '*auth_tokens' => Http::response(['name' => 'auth_tokens/live-key']),
            '*:streamGenerateContent*' => Http::response(['error' => ['code' => 500, 'message' => 'internal']], 500),
        ]);
        [, $conversation, $key] = $this->startCall($this->agent(true));
        // No sound: an error the call client answers with the live voice.
        $this->speak($conversation, ['call_key' => $key, 'text' => 'أيوه طبعًا'])->assertStatus(503)->assertJson(['error' => 'voice_unavailable']);
    }

    public function test_the_call_client_speaks_with_the_studio_voice_and_keeps_the_live_one_as_its_fallback(): void
    {
        $script = (string) file_get_contents(public_path('js/agent-call.js'));

        $this->assertStringContainsString("studio = data.voice === 'studio' && !!window.fetch;", $script);
        // The live voice's sound is kept, unplayed, and its words go to the studio voice.
        $this->assertStringContainsString('(studio ? studioNative : play)(p.inlineData.data);', $script);
        $this->assertStringContainsString('else if (studio) studioWords(sc.outputTranscription.text);', $script);
        $this->assertStringContainsString("fetch(info.api + '/speak'", $script);
        // Only the voice itself is played; slow or failed, the live voice says the turn.
        $this->assertStringContainsString("indexOf('application/octet-stream') < 0) throw new Error('voice');", $script);
        $this->assertStringContainsString('var STUDIO_WAIT = 2500', $script);
        $this->assertStringContainsString('svWait = setTimeout(function () { if (!svStarted) studioLive(); }, STUDIO_WAIT);', $script);
        // The caller talking over the agent stops every phrase on its way; the goodbye waits for the last one.
        $this->assertMatchesRegularExpression('/playHead = vctx \? vctx\.currentTime : 0;\s*studioDrop\(\);/', $script);
        $this->assertStringContainsString('if ((closingDone && !studioBusy()) || Date.now() - since > (studio ? 9000 : 4000)) hungUp = true;', $script);
    }

    public function test_listens_own_agents_can_be_switched_to_the_studio_voice_and_back(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->artisan('agents:house', ['--voice' => 'studio'])->assertSuccessful();
        $ar = Agent::where('user_id', $admin->id)->where('language', 'ar')->firstOrFail();
        $this->assertTrue(AgentSpeech::enabled($ar));

        // Run again without the choice: it stays as it is.
        $this->artisan('agents:house')->assertSuccessful();
        $this->assertTrue(AgentSpeech::enabled($ar->fresh()));

        $this->artisan('agents:house', ['--voice' => 'live'])->assertSuccessful();
        $this->assertFalse(AgentSpeech::enabled($ar->fresh()));
        $this->artisan('agents:house', ['--voice' => 'robot'])->assertFailed();
    }
}
