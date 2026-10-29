<?php

namespace Tests\Feature\Mcp;

use App\Filament\Resources\AudioGenerationResource;
use App\Filament\Resources\AudioGenerationResource\Pages\ListAudioGenerations;
use App\Jobs\GenerateAudioJob;
use App\Models\ApiRequestLog;
use App\Models\AudioGeneration;
use App\Models\Plan;
use App\Models\TextItem;
use App\Models\User;
use App\Support\Api\ApiScopes;
use App\Support\Credits;
use App\Support\GenerationSource;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Feature\Api\InteractsWithDeveloperApi;
use Tests\TestCase;

/**
 * The MCP server at /mcp: AI apps sign in with an API key and make speech
 * through the developer API's own checks, credits and limits. Answers are
 * JSON-RPC; a recording comes back with a signed link the person can open.
 * Nothing leaves the machine and no real audio is made.
 */
class McpServerTest extends TestCase
{
    use InteractsWithDeveloperApi;
    use RefreshDatabase;

    private const TOOLS = ['list_voices', 'get_voice_options', 'text_to_speech', 'get_speech', 'list_speech_history', 'get_account'];

    private Plan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDeveloperApi();
        $this->plan = $this->apiPlan();
    }

    private function member(): User
    {
        return $this->apiMember($this->plan);
    }

    public function test_an_assistant_connects_and_sees_the_tools_its_key_allows(): void
    {
        $plain = $this->issueKey($this->member())['plain'];

        $this->mcp($plain, 'initialize', [
            'protocolVersion' => '2025-06-18',
            'capabilities' => (object) [],
            'clientInfo' => ['name' => 'test-client', 'version' => '1.0'],
        ])->assertOk()
            ->assertJsonPath('result.protocolVersion', '2025-06-18')
            ->assertJsonPath('result.serverInfo.name', 'Listen')
            ->assertJsonPath('result.capabilities.tools.listChanged', false)
            ->assertJsonMissingPath('result.capabilities.resources');
        $this->assertStringContainsString('text_to_speech', (string) $this->mcp($plain, 'initialize', ['protocolVersion' => '2025-06-18'])->json('result.instructions'));

        $this->assertSame(self::TOOLS, array_column($this->mcp($plain, 'tools/list')->assertOk()->json('result.tools'), 'name'));

        // A key that may only read voices sees only the voice tools, and cannot run the others.
        $voicesOnly = $this->issueKey($this->member(), [ApiScopes::VOICES_READ])['plain'];
        $this->assertSame(['list_voices', 'get_voice_options'], array_column($this->mcp($voicesOnly, 'tools/list')->json('result.tools'), 'name'));
        $this->callTool($voicesOnly, 'text_to_speech', ['text' => 'Hello', 'voice_id' => 'voice_1'])
            ->assertJsonPath('error.message', 'Tool [text_to_speech] not found.');

        // Each tool says what it does to the account.
        $tools = collect($this->mcp($plain, 'tools/list')->json('result.tools'))->keyBy('name');
        $this->assertFalse($tools['text_to_speech']['annotations']['readOnlyHint']);
        $this->assertTrue($tools['list_voices']['annotations']['readOnlyHint']);
        $this->assertSame(['text', 'voice_id'], $tools['text_to_speech']['inputSchema']['required']);
    }

    public function test_without_a_valid_key_the_server_refuses_and_says_why(): void
    {
        $this->mcp(null, 'tools/list', id: 7)
            ->assertStatus(401)
            ->assertHeader('WWW-Authenticate')
            ->assertJsonPath('jsonrpc', '2.0')
            ->assertJsonPath('id', 7)
            ->assertJsonPath('error.data.code', 'missing_api_key');

        $key = $this->issueKey($this->member());
        $key['key']->revoke();
        $this->mcp($key['plain'], 'tools/list')->assertStatus(401)->assertJsonPath('error.data.code', 'invalid_api_key');

        // A plan without the API.
        $noApi = $this->issueKey($this->apiMember($this->apiPlan(['slug' => 'starter-no-api', 'api_access_enabled' => false])))['plain'];
        $this->mcp($noApi, 'tools/list')->assertStatus(403)->assertJsonPath('error.data.code', 'api_access_not_in_plan');

        $this->assertSame(['missing_api_key', 'invalid_api_key', 'api_access_not_in_plan'],
            ApiRequestLog::query()->orderBy('id')->pluck('error_code')->all());
        $this->assertSame(['mcp'], ApiRequestLog::query()->distinct()->pluck('endpoint')->all());
    }

    public function test_text_to_speech_makes_speech_charged_like_the_api_with_a_link_to_play(): void
    {
        $user = $this->member();
        ['key' => $key, 'plain' => $plain] = $this->issueKey($user);
        $voice = $this->premiumVoice();

        $result = $this->callTool($plain, 'text_to_speech', ['text' => 'Hello there, this is a test.', 'voice_id' => self::voiceId($voice), 'emotion' => 'warm'])
            ->assertOk()
            ->assertJsonPath('result.isError', false)
            ->json('result');

        $generation = AudioGeneration::query()->sole();
        $credits = Credits::forSeconds($this->audioSeconds);
        $this->assertSame('done', $generation->status);
        $this->assertSame((int) $key->id, (int) $generation->api_key_id);
        $this->assertSame('warm', $generation->emotion);
        $this->assertSame(GenerationSource::MCP, $generation->textItem->source_type);
        $this->assertSame($credits, $this->creditsSpent($user));
        $this->assertSame($credits, $this->keyCreditsSpent($key));

        $speech = $result['structuredContent'];
        $this->assertSame((int) $generation->id, $speech['history_item_id']);
        $this->assertSame('completed', $speech['status']);
        $this->assertSame($credits, $speech['credits_used']);
        $this->assertSame(90000 - $credits, $speech['credits_remaining']);
        $this->assertSame('text', $result['content'][0]['type']);
        $this->assertStringContainsString('Done: 4 seconds of speech with Maya', $result['content'][0]['text']);
        $this->assertStringContainsString($speech['audio_url'], $result['content'][0]['text']);
        $this->assertSame(['type' => 'resource_link', 'uri' => $speech['audio_url'], 'name' => 'listen-'.$generation->id.'.mp3'],
            array_intersect_key($result['content'][1], array_flip(['type', 'uri', 'name'])));

        // The link plays the recording, for anyone who has it, without the key.
        $audio = $this->get($speech['audio_url'])->assertOk();
        $this->assertSame(Storage::disk('public')->get($generation->audio_path), $audio->streamedContent() ?: $audio->getContent());
        $audio->assertHeader('Content-Disposition', 'inline; filename="listen-'.$generation->id.'.mp3"');

        // The admin's request log shows the tool and what it cost.
        $logged = ApiRequestLog::query()->where('endpoint', 'mcp.text_to_speech')->sole();
        $this->assertSame([(int) $generation->id, $credits, 200], [$logged->history_item_id, $logged->credits, $logged->status]);
        $this->assertSame((int) $key->id, (int) $logged->api_key_id);
    }

    /**
     * Speech still on the queue when the wait is over comes back with its
     * history item (reserved, not lost), and get_speech brings the link once
     * it is done.
     */
    public function test_speech_not_ready_in_time_comes_back_with_its_history_item(): void
    {
        config(['queue.default' => 'database', 'listen.mcp.wait_seconds' => 0]);
        $user = $this->member();
        $plain = $this->issueKey($user)['plain'];

        $result = $this->callTool($plain, 'text_to_speech', ['text' => 'Hello there, this is a test.', 'voice_id' => self::voiceId($this->premiumVoice())])
            ->assertJsonPath('result.isError', false)
            ->assertJsonPath('result.structuredContent.status', 'queued')
            ->assertJsonPath('result.structuredContent.audio_url', null)
            ->json('result');
        $id = $result['structuredContent']['history_item_id'];
        $this->assertStringContainsString('Call get_speech with history_item_id '.$id, $result['content'][0]['text']);
        $this->assertCount(1, $result['content']);

        GenerateAudioJob::dispatchSync($id);

        $this->callTool($plain, 'get_speech', ['history_item_id' => $id])
            ->assertJsonPath('result.structuredContent.status', 'completed')
            ->assertJsonPath('result.content.1.type', 'resource_link');
        $this->assertSame(Credits::forSeconds($this->audioSeconds), $this->creditsSpent($user));
    }

    public function test_a_link_opens_only_its_own_recording_and_only_while_the_plan_keeps_audio(): void
    {
        $user = $this->member();
        $plain = $this->issueKey($user)['plain'];
        $mine = $this->storedGeneration($user);
        $other = $this->storedGeneration($this->member());

        // The plan keeps audio history for 7 days, so the link works for 7 days.
        $speech = $this->callTool($plain, 'get_speech', ['history_item_id' => $mine->id])->json('result');
        $link = $speech['structuredContent']['audio_url'];
        $this->assertSame(now()->addDays(7)->toIso8601String(), $speech['structuredContent']['audio_url_expires_at']);
        $this->assertStringContainsString('the link works until '.now()->addDays(7)->utc()->format('j F Y'), $speech['content'][0]['text']);
        $this->get($link)->assertOk();
        $this->get(str_replace('/mcp/audio/'.$mine->id, '/mcp/audio/'.$other->id, $link))->assertForbidden();
        $this->get(strtok($link, '?'))->assertForbidden();

        $this->travel(8)->days();
        $this->get($link)->assertForbidden();

        // A plan that keeps audio for 30 days: 30 days.
        $this->plan->update(['recent_voices_days' => 30]);
        $link = $this->callTool($plain, 'get_speech', ['history_item_id' => $mine->id])->json('result.structuredContent.audio_url');
        $this->travel(29)->days();
        $this->get($link)->assertOk();
        $this->travel(2)->days();
        $this->get($link)->assertForbidden();

        // A plan that keeps audio always: the link has no end date.
        $this->travelBack();
        $this->plan->update(['recent_voices_days' => 0]);
        $speech = $this->callTool($plain, 'list_speech_history')->assertJsonPath('result.isError', false)->json('result.structuredContent.history.0');
        $this->assertSame((int) $mine->id, $speech['history_item_id']);
        $this->assertNull($speech['audio_url_expires_at']);
        $this->assertStringNotContainsString('expires=', $speech['audio_url']);
        $this->assertStringContainsString('the link has no end date',
            (string) $this->callTool($plain, 'get_speech', ['history_item_id' => $mine->id])->json('result.content.0.text'));
        $this->travel(400)->days();
        $this->get($speech['audio_url'])->assertOk();
    }

    /**
     * Admin → Audio Generations tells apart what AI apps made from the
     * studio, the extension and the API, with the key that made it.
     */
    public function test_the_admin_sees_where_each_recording_came_from(): void
    {
        $user = $this->member();
        ['key' => $key, 'plain' => $plain] = $this->issueKey($user, null, null, 'Assistant key');
        $this->callTool($plain, 'text_to_speech', ['text' => 'Made by an AI app.', 'voice_id' => self::voiceId($this->premiumVoice())])->assertJsonPath('result.isError', false);
        $byApp = AudioGeneration::query()->sole();
        $inStudio = $this->storedGeneration($user, ['text_item_id' => TextItem::create(['user_id' => $user->id, 'source_type' => GenerationSource::STUDIO, 'content' => 'Hi', 'status' => 'done'])->id]);
        $inExtension = $this->storedGeneration($user, ['text_item_id' => TextItem::create(['user_id' => $user->id, 'source_type' => GenerationSource::EXTENSION, 'content' => 'Hi', 'status' => 'done'])->id]);

        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ListAudioGenerations::class)
            ->assertSee(['AI app (MCP)', 'Key: Assistant key', 'Studio', 'Browser extension'])
            ->filterTable('source', GenerationSource::MCP)
            ->assertCanSeeTableRecords([$byApp])
            ->assertCanNotSeeTableRecords([$inStudio, $inExtension]);

        $this->get(AudioGenerationResource::getUrl('view', ['record' => $byApp]))
            ->assertOk()
            ->assertSee('AI app (MCP), key &quot;Assistant key&quot;', false);
        $this->assertSame((int) $key->id, (int) $byApp->api_key_id);
    }

    public function test_refused_speech_is_a_tool_error_and_costs_nothing(): void
    {
        $user = $this->member();
        $plain = $this->issueKey($user)['plain'];

        $this->callTool($plain, 'text_to_speech', ['text' => 'Hello there.', 'voice_id' => 'voice_999999'])
            ->assertOk()
            ->assertJsonPath('result.isError', true)
            ->assertJsonPath('result.content.0.text', 'voice_not_found: No voice was found with this id.');

        $this->callTool($plain, 'text_to_speech', ['voice_id' => self::voiceId($this->premiumVoice())])
            ->assertJsonPath('result.isError', true)
            ->assertJsonPath('result.content.0.text', 'validation_failed: The text field is required.');

        // A key with its own small monthly budget.
        $limited = $this->issueKey($user, null, 10)['plain'];
        $refused = $this->callTool($limited, 'text_to_speech', ['text' => 'Hello there, this is a test.', 'voice_id' => self::voiceId($this->premiumVoice(['display_name' => 'Nour', 'internal_voice_id' => 'en-Gemini-HD-Aoede', 'provider_voice_id' => 'Aoede']))])
            ->assertJsonPath('result.isError', true);
        $this->assertStringStartsWith('key_credit_limit_exceeded: ', (string) $refused->json('result.content.0.text'));

        $this->assertNothingCharged($user);
        $this->assertSame(['voice_not_found', 'validation_failed'], ApiRequestLog::query()->where('endpoint', 'mcp.text_to_speech')->orderBy('id')->limit(2)->pluck('error_code')->all());
    }

    public function test_speech_history_shows_the_members_own_recordings_with_fresh_links(): void
    {
        $user = $this->member();
        $plain = $this->issueKey($user)['plain'];
        $older = $this->storedGeneration($user, ['input_text' => 'The first one.']);
        $newer = $this->storedGeneration($user, ['input_text' => 'The second one.']);
        $notMine = $this->storedGeneration($this->member());

        $page = $this->callTool($plain, 'list_speech_history', ['limit' => 1])->assertJsonPath('result.isError', false)->json('result.structuredContent');
        $this->assertSame([(int) $newer->id], array_column($page['history'], 'history_item_id'));
        $this->assertTrue($page['has_more']);
        $this->assertStringContainsString('/mcp/audio/'.$newer->id.'?', $page['history'][0]['audio_url']);
        $this->assertSame([(int) $older->id], array_column($this->callTool($plain, 'list_speech_history', ['before' => $page['next_cursor']])->json('result.structuredContent.history'), 'history_item_id'));

        $this->callTool($plain, 'get_speech', ['history_item_id' => $older->id])
            ->assertJsonPath('result.structuredContent.text', 'The first one.')
            ->assertJsonPath('result.content.1.type', 'resource_link');
        $this->callTool($plain, 'get_speech', ['history_item_id' => $notMine->id])
            ->assertJsonPath('result.isError', true)
            ->assertJsonPath('result.content.0.text', 'history_item_not_found: No history item was found with this id.');
    }

    public function test_voices_options_and_account_answer_from_the_api(): void
    {
        $plain = $this->issueKey($this->member())['plain'];
        $maya = $this->premiumVoice();
        $omar = $this->premiumVoice(['language' => 'ar', 'gender' => 'male', 'display_name' => 'Omar', 'internal_voice_id' => 'ar-Gemini-HD-Puck', 'provider_voice_id' => 'Puck']);

        $voices = $this->callTool($plain, 'list_voices')->assertJsonPath('result.isError', false)->json('result.structuredContent');
        $this->assertEqualsCanonicalizing([self::voiceId($maya), self::voiceId($omar)], array_column($voices['voices'], 'voice_id'));
        $this->assertSame([self::voiceId($omar)], array_column($this->callTool($plain, 'list_voices', ['language' => 'ar'])->json('result.structuredContent.voices'), 'voice_id'));
        $this->assertSame([self::voiceId($maya)], array_column($this->callTool($plain, 'list_voices', ['search' => 'may'])->json('result.structuredContent.voices'), 'voice_id'));

        $options = $this->callTool($plain, 'get_voice_options')->json('result');
        $this->assertContains('ar-EG', array_column($options['structuredContent']['dialects']['ar'], 'value'));
        $this->assertStringContainsString('ar-EG (', $options['content'][0]['text']);

        $account = $this->callTool($plain, 'get_account')->json('result');
        $this->assertSame(90000, $account['structuredContent']['credits']['remaining']);
        $this->assertStringContainsString('Credits left: 90,000 of 90,000', $account['content'][0]['text']);
    }

    public function test_the_newest_protocol_works_without_a_handshake(): void
    {
        $plain = $this->issueKey($this->member())['plain'];
        $meta = ['_meta' => [
            'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
            'io.modelcontextprotocol/clientCapabilities' => (object) [],
        ]];
        $headers = ['MCP-Protocol-Version' => '2026-07-28'];

        $this->mcp($plain, 'server/discover', $meta, headers: $headers + ['Mcp-Method' => 'server/discover'])
            ->assertOk()
            ->assertJsonPath('result.supportedVersions', ['2026-07-28']);

        $this->mcp($plain, 'tools/call', $meta + ['name' => 'get_account', 'arguments' => (object) []], headers: $headers + ['Mcp-Method' => 'tools/call', 'Mcp-Name' => 'get_account'])
            ->assertOk()
            ->assertJsonPath('result.isError', false);
    }

    public function test_the_server_card_tells_apps_where_and_how_to_connect(): void
    {
        $card = $this->get('/mcp/server-card')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/mcp-server-card+json')
            ->assertHeader('Access-Control-Allow-Origin', '*');
        $this->assertSame('https://static.modelcontextprotocol.io/schemas/v1/server-card.schema.json', $card->json('$schema'));
        $this->assertMatchesRegularExpression('#^[a-zA-Z0-9.-]+/[a-zA-Z0-9._-]+$#', $card->json('name'));
        $this->assertLessThanOrEqual(100, mb_strlen($card->json('description')));
        $this->assertSame(url('/mcp'), $card->json('remotes.0.url'));
        $this->assertSame('streamable-http', $card->json('remotes.0.type'));
        $this->assertContains('2025-06-18', $card->json('remotes.0.supportedProtocolVersions'));

        $this->get('/mcp/server-card', ['If-None-Match' => $card->headers->get('ETag')])->assertStatus(304);

        $this->get('/.well-known/mcp/server-card.json')
            ->assertOk()
            ->assertJsonPath('serverInfo.name', 'Listen')
            ->assertJsonPath('transport.endpoint', url('/mcp'))
            ->assertJsonPath('capabilities.tools.listChanged', false);
    }

    /**
     * @param  array<string, mixed>  $params
     * @param  array<string, string>  $headers
     */
    private function mcp(?string $key, string $method, array $params = [], int $id = 1, array $headers = []): TestResponse
    {
        $headers += ['Accept' => 'application/json, text/event-stream', 'MCP-Protocol-Version' => '2025-06-18'];
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
