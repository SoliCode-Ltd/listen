<?php

namespace Tests\Feature\Agents;

use App\Models\Agent;
use App\Models\AgentConversation;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\Subscription;
use App\Models\User;
use App\Support\ArabicDialects;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A caller's words, written again from their own voice in the agent's dialect: the live
 * transcription wrote an Arabic "thank you" as "Shukran khaseer", a protest as "unbelievable",
 * and a phone number as single digits that read backwards on the Arabic page.
 */
class AgentCallerHearingTest extends TestCase
{
    use RefreshDatabase;

    private const SITE = 'https://cairosmiles.com';

    /** What the text model answers when asked to write the caller's words. */
    private string $heard = 'شكرًا كتير';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.gemini_tts.api_key' => 'test-key']);
        Http::fake([
            '*auth_tokens' => Http::response(['name' => 'auth_tokens/live-key']),
            '*:generateContent' => fn () => Http::response(['candidates' => [['content' => ['parts' => [['text' => $this->heard]]]]]]),
        ]);
        Plan::factory()->free()->create(['monthly_chars' => 10000, 'voice_tier' => 'standard']);
        SiteSetting::set('agents_credits_per_minute', '8100', 'agents');
    }

    private function agent(): Agent
    {
        $plan = Plan::factory()->create(['slug' => 'pro-'.uniqid(), 'price_cents' => 4500, 'voice_tier' => 'hd', 'monthly_chars' => 500000, 'allow_overage' => false,
            'agents_enabled' => true, 'agent_concurrent_calls' => 20]);
        $owner = User::factory()->create();
        Subscription::create(['user_id' => $owner->id, 'plan_id' => $plan->id, 'status' => 'active']);

        return Agent::create([
            'user_id' => $owner->id, 'name' => 'Sara', 'business_name' => 'Cairo Smiles', 'business_type' => 'healthcare',
            'jobs' => ['answer', 'leads'], 'language' => 'ar', 'dialect' => 'ar-EG', 'live_voice' => 'Achernar',
            'status' => Agent::LIVE, 'max_call_seconds' => 600, 'first_message' => 'أهلًا بيك',
        ]);
    }

    private function callApi(string $path, array $body): TestResponse
    {
        return $this->call('POST', '/agent-api'.$path, [], [], [], ['CONTENT_TYPE' => 'text/plain;charset=UTF-8', 'REMOTE_ADDR' => '203.0.113.9', 'HTTP_ORIGIN' => self::SITE], json_encode($body));
    }

    /** @return array{0: string, 1: string} the call's id and key */
    private function startCall(Agent $agent): array
    {
        $start = $this->callApi('/calls', ['key' => $agent->mainWidget()->public_key, 'visitor' => 'v-hear'])->assertOk();

        return [$start->json('conversation'), $start->json('call_key')];
    }

    /** Seconds of 16-bit PCM, as the call client sends them. */
    private function voice(float $seconds, int $rate = 16000): string
    {
        return base64_encode(str_repeat(pack('v', 1200).pack('v', 64336), (int) ($seconds * $rate / 2)));
    }

    private function transcript(string $id): array
    {
        return AgentConversation::where('public_id', $id)->sole()->transcript;
    }

    public function test_a_call_offers_it_unless_it_is_switched_off(): void
    {
        $agent = $this->agent();
        $this->callApi('/calls', ['key' => $agent->mainWidget()->public_key, 'visitor' => 'v-1'])->assertOk()->assertJsonPath('transcribe', true);

        SiteSetting::set('agents_caller_transcribe', '0', 'agents');
        $this->callApi('/calls', ['key' => $agent->mainWidget()->public_key, 'visitor' => 'v-2'])->assertOk()->assertJsonPath('transcribe', false);
    }

    public function test_a_callers_line_is_written_again_in_the_agents_dialect_and_kept_in_place_of_the_heard_one(): void
    {
        $this->freezeTime();
        [$id, $key] = $this->startCall($this->agent());
        $this->travel(20)->seconds();

        // The line reached Listen first, as the live transcription wrote it.
        $this->callApi("/calls/{$id}/ping", ['call_key' => $key, 'turns' => [['role' => 'agent', 'text' => 'العفو'], ['role' => 'caller', 'text' => 'Shukran khaseer', 'n' => 1]]])->assertOk();

        $this->callApi("/calls/{$id}/transcribe", ['call_key' => $key, 'n' => 1, 'audio' => $this->voice(1.5), 'rate' => 16000, 'heard' => 'Shukran khaseer', 'said' => 'العفو'])
            ->assertOk()->assertJsonPath('text', 'شكرًا كتير');

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), ':generateContent')
            && data_get($r->data(), 'contents.0.parts.0.inline_data.mime_type') === 'audio/wav'
            && str_starts_with(base64_decode((string) data_get($r->data(), 'contents.0.parts.0.inline_data.data')), 'RIFF')
            && str_contains((string) data_get($r->data(), 'contents.0.parts.1.text'), ArabicDialects::ALL['ar-EG']['en'])
            && str_contains((string) data_get($r->data(), 'contents.0.parts.1.text'), 'never in Latin letters')
            && str_contains((string) data_get($r->data(), 'contents.0.parts.1.text'), 'Shukran khaseer'));
        $caller = collect($this->transcript($id))->firstWhere('role', 'caller');
        $this->assertSame('شكرًا كتير', $caller['text']);
        $this->assertSame('Shukran khaseer', $caller['heard']);
    }

    public function test_a_better_line_that_came_first_is_used_when_the_line_arrives(): void
    {
        [$id, $key] = $this->startCall($this->agent());
        $this->travel(10)->seconds();
        $this->heard = 'رقمي زيرو عشرة ستة تمانية واحد اتنين واحد سبعة صفر خمسة';

        $this->callApi("/calls/{$id}/transcribe", ['call_key' => $key, 'n' => 2, 'audio' => $this->voice(3), 'rate' => 16000, 'heard' => '0 10 6 8 1 2 1 7 0 5'])
            ->assertOk()->assertJsonPath('text', 'رقمي 01068121705');
        // The call ended before its client had the better line: the raw one is replaced on arrival.
        $this->callApi("/calls/{$id}/end", ['call_key' => $key, 'turns' => [['role' => 'caller', 'text' => '0 10 6 8 1 2 1 7 0 5', 'n' => 2]], 'reason' => 'done'])->assertOk();

        $caller = collect($this->transcript($id))->firstWhere('role', 'caller');
        $this->assertSame('رقمي 01068121705', $caller['text']);
        $this->assertSame('0 10 6 8 1 2 1 7 0 5', $caller['heard']);
    }

    public function test_a_line_the_client_already_fixed_keeps_what_was_heard_beside_it(): void
    {
        [$id, $key] = $this->startCall($this->agent());
        $this->callApi("/calls/{$id}/ping", ['call_key' => $key, 'turns' => [['role' => 'caller', 'text' => 'غير معقول', 'n' => 1, 'heard' => 'unbelievable']]])->assertOk();

        $caller = collect($this->transcript($id))->firstWhere('role', 'caller');
        $this->assertSame(['غير معقول', 'unbelievable', 1], [$caller['text'], $caller['heard'], $caller['n']]);
    }

    public function test_nothing_is_written_for_a_wrong_key_a_switched_off_setting_or_too_much_voice(): void
    {
        [$id, $key] = $this->startCall($this->agent());
        $this->callApi("/calls/{$id}/transcribe", ['call_key' => 'wrong', 'n' => 1, 'audio' => $this->voice(1)])->assertNotFound();
        // More than 31 s in one line is not a line.
        $this->callApi("/calls/{$id}/transcribe", ['call_key' => $key, 'n' => 1, 'audio' => $this->voice(32, 8000), 'rate' => 8000])->assertOk()->assertJsonPath('text', null);
        // Never more voice than the call has lasted (plus a little): 16 s, then 16 s more, at its start.
        $this->callApi("/calls/{$id}/transcribe", ['call_key' => $key, 'n' => 1, 'audio' => $this->voice(16, 8000), 'rate' => 8000])->assertOk()->assertJsonPath('text', 'شكرًا كتير');
        $this->callApi("/calls/{$id}/transcribe", ['call_key' => $key, 'n' => 2, 'audio' => $this->voice(16, 8000), 'rate' => 8000])->assertOk()->assertJsonPath('text', null);
        // An odd rate, or the setting switched off.
        $this->callApi("/calls/{$id}/transcribe", ['call_key' => $key, 'n' => 3, 'audio' => $this->voice(1, 44100), 'rate' => 44100])->assertOk()->assertJsonPath('text', null);
        SiteSetting::set('agents_caller_transcribe', '0', 'agents');
        $this->callApi("/calls/{$id}/transcribe", ['call_key' => $key, 'n' => 4, 'audio' => $this->voice(1)])->assertOk()->assertJsonPath('text', null);

        Http::assertSentCount(2); // the live key and the one line written
    }

    public function test_a_line_with_no_speech_in_it_is_never_sent_to_the_model(): void
    {
        [$id, $key] = $this->startCall($this->agent());
        $this->travel(30)->seconds();
        // Two seconds of faint noise: on such a line the model made a word up.
        mt_srand(7);
        $noise = '';
        for ($i = 0; $i < 32000; $i++) {
            $noise .= pack('v', mt_rand(-60, 60) & 0xFFFF);
        }
        $this->assertFalse(\App\Services\Agents\AgentCalls::voiced($noise, 16000));
        $this->assertTrue(\App\Services\Agents\AgentCalls::voiced(base64_decode($this->voice(1)), 16000));

        $this->callApi("/calls/{$id}/transcribe", ['call_key' => $key, 'n' => 1, 'audio' => base64_encode($noise), 'heard' => 'unbelievable'])
            ->assertOk()->assertJsonPath('text', null);
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), ':generateContent'));
    }

    public function test_a_call_that_ended_minutes_ago_writes_nothing_more(): void
    {
        [$id, $key] = $this->startCall($this->agent());
        $this->callApi("/calls/{$id}/end", ['call_key' => $key, 'turns' => [], 'reason' => 'done'])->assertOk();
        $this->travel(5)->minutes();

        $this->callApi("/calls/{$id}/transcribe", ['call_key' => $key, 'n' => 1, 'audio' => $this->voice(1)])->assertOk()->assertJsonPath('text', null);
    }

    public function test_an_answer_that_is_empty_a_note_or_far_longer_than_the_line_is_not_used(): void
    {
        [$id, $key] = $this->startCall($this->agent());
        $this->travel(60)->seconds();
        // Nothing, a note, far more than the line, or the heard line given back as it was.
        foreach (['', '(no speech)', str_repeat('كلام كثير جدًا ', 40), 'أيوه.'] as $i => $answer) {
            $this->heard = $answer;
            $this->callApi("/calls/{$id}/transcribe", ['call_key' => $key, 'n' => $i + 1, 'audio' => $this->voice(1), 'heard' => 'أيوه'])->assertOk()->assertJsonPath('text', null);
        }
    }
}
