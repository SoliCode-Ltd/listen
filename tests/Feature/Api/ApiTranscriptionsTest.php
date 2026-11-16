<?php

namespace Tests\Feature\Api;

use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\Transcription;
use App\Models\User;
use App\Services\Transcription\AudioLink;
use App\Services\Tts\GeminiKeyManager;
use App\Support\Api\ApiScopes;
use App\Support\AudioFormats;
use App\Support\Transcription\TranscriptionAccess;
use App\Support\Transcription\TranscriptionSettings;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * POST /v1/transcriptions (a file or a link), GET /v1/transcriptions (cursor
 * pages), GET /v1/transcriptions/{id} and its export: speech to text through
 * the page's own flow, so the same plan rules and minutes, with the API's
 * error shapes and the plan that would take it named. There is no free try
 * through the API, and nobody sees anyone else's transcripts.
 */
class ApiTranscriptionsTest extends TestCase
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
        AudioLink::$resolver = null;
        SiteSetting::set('transcription_audience', TranscriptionSettings::AUDIENCE_EVERYONE, TranscriptionSettings::GROUP);
        $key = ['key' => 'test-gemini-key', 'name' => 'GEMINI_TTS_API_KEY', 'index' => 1];
        $keys = \Mockery::mock(GeminiKeyManager::class);
        $keys->shouldReceive('keysInOrder')->andReturn([$key]);
        $keys->shouldReceive('getAllKeys')->andReturn([$key]);
        $keys->shouldReceive('getAvailableKey')->andReturn($key);
        $keys->shouldReceive('recordUsage');
        $keys->shouldIgnoreMissing();
        $this->app->instance(GeminiKeyManager::class, $keys);
        // The speech-making fake of the API's own tests answers everything; each test here sets its own answers.
        Http::swap(new HttpFactory);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        AudioLink::$resolver = null;
        parent::tearDown();
    }

    public function test_a_file_is_written_out_and_its_minutes_are_counted(): void
    {
        $this->answers([['speaker' => 'A', 'text' => 'Welcome to the show.'], ['speaker' => 'B', 'text' => 'Thanks for having me.']]);
        $user = $this->sttMember(minutes: 120);
        ['plain' => $plain, 'key' => $key] = $this->issueKey($user);

        $made = $this->upload($plain, $this->audio(6.2), ['language_code' => 'en', 'title' => 'Episode 4'])
            ->assertStatus(202)
            ->assertHeader('X-Seconds-Used', '6')
            ->json();
        $this->assertMatchesRegularExpression('/^tr_[a-z0-9]{24}$/', $made['transcription_id']);
        $this->assertSame(url('/v1/transcriptions/'.$made['transcription_id']), $this->lastLocation);
        $this->assertSame(['Episode 4', 'api', 'en', 6, false], [$made['title'], $made['source'], $made['language_requested'], $made['seconds_used'], $made['free_try']]);
        $this->assertSame(['included_in_plan' => true, 'seconds_included' => 7200, 'seconds_used' => 6, 'seconds_left' => 7194], array_slice($made['usage'], 0, 4));
        $this->assertSame($key->id, Transcription::query()->where('code', $made['transcription_id'])->value('api_key_id'));

        $item = $this->apiGet($plain, '/transcriptions/'.$made['transcription_id'])->assertOk()->json();
        $this->assertSame('completed', $item['status']);
        $this->assertSame(100, $item['progress']);
        $this->assertSame(2, $item['speaker_count']);
        $this->assertSame([['speaker_id' => 'A', 'name' => 'Speaker A'], ['speaker_id' => 'B', 'name' => 'Speaker B']], $item['speakers']);
        $this->assertSame("Speaker A: Welcome to the show.\n\nSpeaker B: Thanks for having me.", $item['text']);
        $this->assertSame(['speaker_id', 'text', 'start', 'end'], array_keys($item['paragraphs'][0]));
        $this->assertSame('Welcome to the show.', $item['sentences'][0]['text']);
        $this->assertLessThanOrEqual($item['sentences'][1]['start'], $item['sentences'][0]['end']);
        $this->assertSame(['txt', 'srt', 'vtt', 'docx'], array_keys($item['download_urls']));

        $srt = $this->apiGet($plain, '/transcriptions/'.$made['transcription_id'].'/export?format=srt')->assertOk();
        $this->assertStringContainsString('-->', $srt->getContent());
        $this->assertStringContainsString('Thanks for having me.', $srt->getContent());
        $docx = $this->apiGet($plain, '/transcriptions/'.$made['transcription_id'].'/export?format=docx')->assertOk();
        $this->assertStringStartsWith('PK', $docx->getContent());
        $this->assertStringContainsString('attachment;', (string) $docx->headers->get('Content-Disposition'));

        // The account endpoint shows the same minutes.
        $this->assertSame(7194, $this->apiGet($plain, '/user/subscription')->assertOk()->json('speech_to_text.seconds_left'));
    }

    public function test_a_link_is_fetched_and_written_out(): void
    {
        $bytes = (string) file_get_contents($this->audio(3));
        AudioLink::$resolver = fn (string $host) => $host === 'cdn.example.org' ? '93.184.216.34' : null;
        Http::fake([
            'cdn.example.org/*' => Http::response($bytes, 200, ['Content-Type' => 'audio/mpeg']),
            'generativelanguage.googleapis.com/*' => $this->answer([['speaker' => 'A', 'text' => 'From a link.']]),
        ]);
        $user = $this->sttMember(minutes: 120);
        ['plain' => $plain] = $this->issueKey($user);

        $made = $this->apiPost($plain, '/transcriptions', ['url' => 'https://cdn.example.org/clips/intro.mp3'])->assertStatus(202)->json();
        $this->assertSame('intro.mp3', $made['title']);
        $this->assertSame('From a link.', $this->apiGet($plain, '/transcriptions/'.$made['transcription_id'])->json('text'));

        Http::fake(['cdn.example.org/*' => Http::response('<html>', 200, ['Content-Type' => 'text/html'])]);
        $this->assertApiError($this->apiPost($plain, '/transcriptions', ['url' => 'https://cdn.example.org/page']), 422, 'url_not_fetchable', 'invalid_request_error', 'url');
    }

    public function test_a_plan_without_speech_to_text_is_refused_naming_the_plan_that_has_it(): void
    {
        Http::fake();
        $this->sttPlan('pro', 4900, 500);
        $user = $this->sttMember(minutes: 0);
        ['plain' => $plain] = $this->issueKey($user);

        $response = $this->assertApiError($this->upload($plain, $this->audio(3)), 403, 'speech_to_text_not_in_plan', 'permission_error');
        $this->assertSame('Pro', $response->json('error.upgrade_plan'));
        $this->assertStringContainsString('Pro', $response->json('error.message'));
        $this->assertSame(0, Transcription::query()->count(), 'no free try through the API');
        Http::assertNothingSent();
    }

    public function test_a_file_longer_than_the_minutes_left_is_refused_before_anything_is_used(): void
    {
        Http::fake();
        $user = $this->sttMember(minutes: 1);
        ['plain' => $plain] = $this->issueKey($user);
        Transcription::query()->create(['user_id' => $user->id, 'title' => 'Earlier', 'status' => Transcription::READY, 'seconds' => 55,
            'charged_seconds' => 55, 'period_ym' => TranscriptionAccess::balance($user)['period']]);

        $response = $this->assertApiError($this->upload($plain, $this->audio(8)), 402, 'minutes_exceeded', 'quota_error');
        $this->assertSame([8, 5], [$response->json('error.seconds_needed'), $response->json('error.seconds_left')]);
        $this->assertSame(1, Transcription::query()->count());
        Http::assertNothingSent();
    }

    public function test_the_key_needs_the_speech_to_text_permission(): void
    {
        $user = $this->sttMember(minutes: 120);
        ['plain' => $plain] = $this->issueKey($user, [ApiScopes::TEXT_TO_SPEECH, ApiScopes::HISTORY_READ]);

        $this->assertApiError($this->apiGet($plain, '/transcriptions'), 403, 'missing_permission', 'permission_error');
        $this->assertApiError($this->upload($plain, $this->audio(2)), 403, 'missing_permission', 'permission_error');
    }

    public function test_an_unknown_language_is_refused(): void
    {
        $user = $this->sttMember(minutes: 120);
        ['plain' => $plain] = $this->issueKey($user);

        $this->assertApiError($this->upload($plain, $this->audio(2), ['language_code' => 'xx']), 422, 'unsupported_language', 'invalid_request_error', 'language_code');
        $this->assertApiError($this->apiPost($plain, '/transcriptions', []), 422, 'validation_failed', 'invalid_request_error');
    }

    public function test_the_list_is_the_members_own_newest_first_in_cursor_pages(): void
    {
        $user = $this->sttMember(minutes: 120);
        ['plain' => $plain] = $this->issueKey($user);
        $codes = [];
        foreach (range(1, 3) as $n) {
            $codes[] = $this->stored($user, ['title' => "Talk {$n}"])->code;
        }
        $this->stored($user, ['source' => 'demo', 'title' => 'Demo']);
        $this->stored($user, ['status' => Transcription::PROCESSING, 'title' => 'Working']);
        $other = $this->stored(User::factory()->create(), ['title' => 'Not yours']);

        $page = $this->apiGet($plain, '/transcriptions?page_size=2&status=completed')->assertOk()->json();
        $this->assertSame([$codes[2], $codes[1]], array_column($page['transcriptions'], 'transcription_id'));
        $this->assertSame([true, $codes[1]], [$page['has_more'], $page['next_cursor']]);
        $page = $this->apiGet($plain, '/transcriptions?page_size=2&status=completed&before='.$page['next_cursor'])->assertOk()->json();
        $this->assertSame([$codes[0]], array_column($page['transcriptions'], 'transcription_id'));
        $this->assertFalse($page['has_more']);

        $all = array_column($this->apiGet($plain, '/transcriptions')->json('transcriptions'), 'title');
        $this->assertSame(['Working', 'Talk 3', 'Talk 2', 'Talk 1'], $all, 'never a demo, never anyone else\'s');
        $this->assertArrayNotHasKey('paragraphs', $this->apiGet($plain, '/transcriptions')->json('transcriptions.0'), 'the words only on the one asked for');

        $this->assertApiError($this->apiGet($plain, '/transcriptions/'.$other->code), 404, 'transcription_not_found');
        $this->assertApiError($this->apiGet($plain, '/transcriptions/not-an-id'), 404, 'transcription_not_found');
        $this->assertApiError($this->apiGet($plain, '/transcriptions?before=tr_unknownunknownunknown00'), 422, 'invalid_cursor', null, 'before');
    }

    public function test_a_transcript_still_working_has_nothing_to_download_yet(): void
    {
        $user = $this->sttMember(minutes: 120);
        ['plain' => $plain] = $this->issueKey($user);
        $working = $this->stored($user, ['status' => Transcription::PROCESSING, 'paragraphs' => null, 'segments' => null]);

        $item = $this->apiGet($plain, '/transcriptions/'.$working->code)->assertOk()->json();
        $this->assertSame(['processing', null, null], [$item['status'], $item['text'], $item['download_urls']]);
        $this->assertApiError($this->apiGet($plain, '/transcriptions/'.$working->code.'/export?format=txt'), 409, 'transcription_not_ready');
        $this->assertApiError($this->apiGet($plain, '/transcriptions/'.$working->code.'/export?format=pdf'), 422, 'validation_failed');
    }

    public function test_a_failed_transcript_says_why_and_used_nothing(): void
    {
        $user = $this->sttMember(minutes: 120);
        ['plain' => $plain] = $this->issueKey($user);
        $failed = $this->stored($user, ['status' => Transcription::FAILED, 'charged_seconds' => 0, 'error_code' => 'not_written',
            'error' => 'We could not write out this recording. Nothing was taken from your minutes.', 'paragraphs' => null, 'segments' => null]);

        $item = $this->apiGet($plain, '/transcriptions/'.$failed->code)->assertOk()->json();
        $this->assertSame(['failed', 0], [$item['status'], $item['seconds_used']]);
        $this->assertSame(['code' => 'not_written', 'message' => 'We could not write out this recording. Nothing was taken from your minutes.'], $item['error']);
    }

    /**
     * A playlist lists other files on the server by their path, and ffmpeg would read them and have
     * their sound written out for the sender. Sent as a file (named .m3u8) or as a link, it is refused
     * as having no sound, and nothing is used.
     */
    public function test_a_playlist_sent_as_a_file_or_a_link_is_never_read_as_one(): void
    {
        $user = $this->sttMember(minutes: 60);
        ['plain' => $plain] = $this->issueKey($user);
        $other = $this->audio(4);
        $playlist = sys_get_temp_dir().'/api-stt-'.bin2hex(random_bytes(4)).'.m3u8';
        $lines = "#EXTM3U\n#EXT-X-TARGETDURATION:5\n#EXT-X-VERSION:3\n#EXTINF:4.0,\n{$other}\n#EXT-X-ENDLIST\n";
        file_put_contents($playlist, $lines);

        $this->assertApiError($this->upload($plain, $playlist, [], 'other.m3u8'), 422, 'no_audio_found', 'invalid_request_error', 'file');

        AudioLink::$resolver = fn (string $host) => $host === 'cdn.example.org' ? '93.184.216.34' : null;
        Http::fake(['cdn.example.org/*' => Http::response($lines, 200, ['Content-Type' => 'audio/x-mpegurl'])]);
        $this->assertApiError($this->apiPost($plain, '/transcriptions', ['url' => 'https://cdn.example.org/live/other.m3u8']), 422, 'no_audio_found', 'invalid_request_error', 'file');

        $this->assertSame(0, Transcription::query()->count());
        $this->assertSame(0, TranscriptionAccess::balance($user)['used']);
    }

    /** A new translation within the limits; beyond them, a clear 429 (kept ones still download, free). */
    public function test_translations_through_the_api_have_their_limits(): void
    {
        $user = $this->sttMember(minutes: 60);
        ['plain' => $plain] = $this->issueKey($user);
        $five = array_fill_keys(['fr', 'de', 'es', 'it', 'tr'], ['paragraphs' => [['speaker' => 'A', 'text' => 'x', 'start' => 0.4, 'end' => 1.6]],
            'segments' => [['speaker' => 'A', 'text' => 'x', 'paragraph' => 0, 'start' => 0.4, 'end' => 1.6]], 'at' => now()->toIso8601String()]);
        $t = $this->stored($user, ['translations' => $five]);

        $this->apiGet($plain, '/transcriptions/'.$t->code.'/export?format=txt&translate_to=fr')->assertOk();
        $response = $this->apiGet($plain, '/transcriptions/'.$t->code.'/export?format=txt&translate_to=ar');
        $this->assertApiError($response, 429, 'translation_limit', 'rate_limit_error');
        $this->assertStringContainsString('5 languages', $response->json('error.message'));
    }

    // ------------------------------------------------------------------ helpers

    private ?string $lastLocation = null;

    /** @param  array<string, mixed>  $fields */
    private function upload(string $plain, string $path, array $fields = [], string $name = 'episode.mp3'): TestResponse
    {
        $file = new UploadedFile($path, $name, 'audio/mpeg', null, true);
        $response = $this->post('/v1/transcriptions', $fields + ['file' => $file], ['X-Listen-Api-Key' => $plain, 'Accept' => 'application/json']);
        $this->lastLocation = $response->headers->get('Location');

        return $response;
    }

    private function sttMember(int $minutes, int $fileMinutes = 30): User
    {
        $plan = $this->sttPlan('creator-'.bin2hex(random_bytes(3)), 2900, $minutes, $fileMinutes);

        return $this->apiMember($plan);
    }

    private function sttPlan(string $slug, int $price, int $minutes, int $fileMinutes = 60): Plan
    {
        return $this->apiPlan(['slug' => $slug, 'name' => ucfirst(explode('-', $slug)[0]), 'price_cents' => $price,
            'transcription_enabled' => $minutes > 0, 'transcription_minutes_month' => $minutes,
            'transcription_max_file_minutes' => $fileMinutes, 'transcription_concurrent_jobs' => 1]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function stored(User $user, array $attributes = []): Transcription
    {
        return Transcription::query()->create($attributes + [
            'user_id' => $user->id, 'title' => 'Talk', 'source' => 'api', 'status' => Transcription::READY, 'seconds' => 12, 'charged_seconds' => 12,
            'language_requested' => 'auto', 'language' => 'en', 'finished_at' => now(),
            'paragraphs' => [['speaker' => 'A', 'text' => 'Hello there.', 'start' => 0.4, 'end' => 1.6]],
            'segments' => [['speaker' => 'A', 'text' => 'Hello there.', 'paragraph' => 0, 'start' => 0.4, 'end' => 1.6]],
        ]);
    }

    /** A recording of tone, $seconds long. */
    private function audio(float $seconds): string
    {
        $file = sys_get_temp_dir().'/api-stt-'.bin2hex(random_bytes(4)).'.mp3';
        (new Process([AudioFormats::ffmpegPath(), '-v', 'error', '-y', '-f', 'lavfi', '-i', 'sine=frequency=300:duration='.$seconds, '-c:a', 'libmp3lame', $file]))->mustRun();

        return $file;
    }

    /** @param  array<int, array{speaker: string, text: string}>  $paragraphs */
    private function answers(array $paragraphs): void
    {
        Http::fake(['*' => $this->answer($paragraphs)]);
    }

    /** @param  array<int, array{speaker: string, text: string}>  $paragraphs */
    private function answer(array $paragraphs): PromiseInterface
    {
        $speakers = array_map(fn ($l) => ['id' => $l, 'voice' => 'calm'], array_values(array_unique(array_column($paragraphs, 'speaker'))));

        return Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode(['language' => 'en', 'speakers' => $speakers,
            'paragraphs' => $paragraphs], JSON_UNESCAPED_UNICODE)]]], 'finishReason' => 'STOP']],
            'usageMetadata' => ['promptTokenCount' => 900, 'candidatesTokenCount' => 30,
                'promptTokensDetails' => [['modality' => 'AUDIO', 'tokenCount' => 300], ['modality' => 'TEXT', 'tokenCount' => 600]]]]);
    }
}
