<?php

namespace Tests\Feature\Mcp;

use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\Transcription;
use App\Models\User;
use App\Services\Transcription\AudioLink;
use App\Services\TranslationService;
use App\Services\Tts\GeminiKeyManager;
use App\Support\Api\ApiScopes;
use App\Support\AudioFormats;
use App\Support\Transcription\TranscriptionSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Process\Process;
use Tests\Feature\Api\InteractsWithDeveloperApi;
use Tests\TestCase;

/**
 * The MCP server's speech to text tools: transcribe_audio from a link,
 * get_transcription (text, subtitles, translated) and list_transcriptions,
 * through the API's own flow, so the same plan rules and minutes; a key
 * without the speech_to_text permission does not see them.
 */
class McpSpeechToTextTest extends TestCase
{
    use InteractsWithDeveloperApi;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (! AudioFormats::ffmpegPath()) {
            $this->markTestSkipped('ffmpeg is not available.');
        }
        $this->setUpDeveloperApi();
        Storage::fake('local');
        SiteSetting::set('transcription_audience', TranscriptionSettings::AUDIENCE_EVERYONE, TranscriptionSettings::GROUP);
        $key = ['key' => 'test-gemini-key', 'name' => 'GEMINI_TTS_API_KEY', 'index' => 1];
        $keys = \Mockery::mock(GeminiKeyManager::class);
        $keys->shouldReceive('keysInOrder')->andReturn([$key]);
        $keys->shouldIgnoreMissing();
        $this->app->instance(GeminiKeyManager::class, $keys);
        Http::swap(new HttpFactory);
        Http::preventStrayRequests();
        AudioLink::$resolver = fn (string $host) => $host === 'cdn.example.org' ? '93.184.216.34' : null;
    }

    protected function tearDown(): void
    {
        AudioLink::$resolver = null;
        parent::tearDown();
    }

    public function test_an_assistant_transcribes_a_link_and_reads_it_back(): void
    {
        Http::fake([
            'cdn.example.org/*' => Http::response((string) file_get_contents($this->audio(4)), 200, ['Content-Type' => 'audio/mpeg']),
            'generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode([
                'language' => 'en', 'speakers' => [['id' => 'A', 'voice' => 'calm'], ['id' => 'B', 'voice' => 'bright']],
                'paragraphs' => [['speaker' => 'A', 'text' => 'Good morning, everyone.'], ['speaker' => 'B', 'text' => 'Morning! Shall we start?']],
            ])]]], 'finishReason' => 'STOP']], 'usageMetadata' => ['promptTokenCount' => 900, 'candidatesTokenCount' => 30]]),
        ]);
        $user = $this->sttMember(120);
        $plain = $this->issueKey($user)['plain'];

        $started = $this->callTool($plain, 'transcribe_audio', ['audio_url' => 'https://cdn.example.org/standup.mp3', 'language_code' => 'en'])->assertOk();
        $this->assertNotTrue($started->json('result.isError'));
        $id = (string) $started->json('result.structuredContent.transcription_id');
        $this->assertStringStartsWith('tr_', $id);
        $this->assertStringContainsString('transcription_id: '.$id, (string) $started->json('result.content.0.text'));
        $this->assertSame('mcp', Transcription::query()->where('code', $id)->value('source'));

        $read = $this->callTool($plain, 'get_transcription', ['transcription_id' => $id])->assertOk();
        $text = (string) $read->json('result.content.0.text');
        $this->assertStringContainsString("Speaker A: Good morning, everyone.\n\nSpeaker B: Morning! Shall we start?", $text);
        $this->assertStringContainsString('2 speakers', $text);
        $this->assertSame('completed', $read->json('result.structuredContent.status'));

        $srt = (string) $this->callTool($plain, 'get_transcription', ['transcription_id' => $id, 'format' => 'srt'])->json('result.content.0.text');
        $this->assertMatchesRegularExpression('/\d\d:\d\d:\d\d,\d{3} --> \d\d:\d\d:\d\d,\d{3}/', $srt);

        $translator = $this->mock(TranslationService::class);
        $translator->shouldReceive('prefetch');
        $translator->shouldReceive('maybeTranslate')->andReturnUsing(fn (string $text) => '[fr] '.$text);
        $french = (string) $this->callTool($plain, 'get_transcription', ['transcription_id' => $id, 'translate_to' => 'fr'])->json('result.content.0.text');
        $this->assertStringContainsString('[fr] Good morning, everyone.', $french);
        $this->assertStringContainsString('Translated to fr.', $french);

        $list = $this->callTool($plain, 'list_transcriptions')->assertOk();
        $this->assertSame([$id], array_column($list->json('result.structuredContent.transcriptions'), 'transcription_id'));
        $this->assertStringContainsString('"standup.mp3"', (string) $list->json('result.content.0.text'));

        $account = (string) $this->callTool($plain, 'get_account')->json('result.content.0.text');
        $this->assertStringContainsString('Speech to text: 119 min 56 s left of 120 min this month', $account);
    }

    public function test_one_still_working_says_so_and_one_not_in_the_plan_names_the_plan(): void
    {
        $user = $this->sttMember(120);
        $plain = $this->issueKey($user)['plain'];
        $working = Transcription::query()->create(['user_id' => $user->id, 'title' => 'Long talk', 'source' => 'mcp', 'status' => Transcription::PROCESSING,
            'seconds' => 3600, 'charged_seconds' => 3600, 'parts' => 12, 'parts_done' => 3, 'work' => ['x' => 1]]);

        $text = (string) $this->callTool($plain, 'get_transcription', ['transcription_id' => $working->code])->json('result.content.0.text');
        $this->assertStringStartsWith('Still being written out (processing, ', $text);

        $this->callTool($plain, 'get_transcription', ['transcription_id' => 'tr_nope'])
            ->assertJsonPath('result.isError', true)
            ->assertJsonPath('result.content.0.text', 'transcription_not_found: No transcription was found with this id.');

        Http::fake();
        $this->sttPlan('pro', 4900, 500);
        $basic = $this->issueKey($this->sttMember(0))['plain'];
        $refused = $this->callTool($basic, 'transcribe_audio', ['audio_url' => 'https://cdn.example.org/a.mp3'])->assertJsonPath('result.isError', true);
        $this->assertSame('speech_to_text_not_in_plan: Your plan does not include speech to text. The Creator plan includes it.', $refused->json('result.content.0.text'));
        Http::assertNothingSent();
    }

    public function test_a_key_without_the_permission_does_not_see_the_tools(): void
    {
        $plain = $this->issueKey($this->sttMember(120), [ApiScopes::TEXT_TO_SPEECH, ApiScopes::VOICES_READ, ApiScopes::HISTORY_READ, ApiScopes::USER_READ])['plain'];
        $names = array_column($this->mcp($plain, 'tools/list')->json('result.tools'), 'name');

        $this->assertNotContains('transcribe_audio', $names);
        $this->assertNotContains('get_transcription', $names);
        $this->callTool($plain, 'list_transcriptions')->assertJsonPath('error.message', 'Tool [list_transcriptions] not found.');
    }

    // ------------------------------------------------------------------ helpers

    private function sttMember(int $minutes): User
    {
        return $this->apiMember($this->sttPlan('creator-'.bin2hex(random_bytes(3)), 2900, $minutes));
    }

    private function sttPlan(string $slug, int $price, int $minutes): Plan
    {
        return $this->apiPlan(['slug' => $slug, 'name' => ucfirst(explode('-', $slug)[0]), 'price_cents' => $price,
            'transcription_enabled' => $minutes > 0, 'transcription_minutes_month' => $minutes,
            'transcription_max_file_minutes' => 60, 'transcription_concurrent_jobs' => 1]);
    }

    private function audio(float $seconds): string
    {
        $file = sys_get_temp_dir().'/mcp-stt-'.bin2hex(random_bytes(4)).'.mp3';
        (new Process([AudioFormats::ffmpegPath(), '-v', 'error', '-y', '-f', 'lavfi', '-i', 'sine=frequency=300:duration='.$seconds, '-c:a', 'libmp3lame', $file]))->mustRun();

        return $file;
    }

    private function mcp(?string $key, string $method, array $params = [], int $id = 1): TestResponse
    {
        $headers = ['Accept' => 'application/json, text/event-stream', 'MCP-Protocol-Version' => '2025-06-18'];
        if ($key !== null) {
            $headers['Authorization'] = 'Bearer '.$key;
        }

        return $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method] + ($params === [] ? [] : ['params' => $params]), $headers);
    }

    /** @param  array<string, mixed>  $arguments */
    private function callTool(string $key, string $tool, array $arguments = []): TestResponse
    {
        return $this->mcp($key, 'tools/call', ['name' => $tool, 'arguments' => (object) $arguments]);
    }
}
