<?php

namespace Tests\Feature;

use App\Models\SiteSetting;
use App\Services\Tts\GeminiKeyManager;
use App\Services\Tts\GeminiTtsProvider;
use App\Support\GeminiTtsModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A dialogue used to be made in one request, and very long ones came back
 * without audio. A long dialogue is now split at speaker turns into pieces of
 * at most 1,500 characters, each made with the same speakers and voices, and
 * the audio is joined in order through the same join as a single voice's.
 */
class DialoguePiecesTest extends TestCase
{
    use RefreshDatabase;

    private const CAST = ['Sara' => 'Kore', 'Omar' => 'Puck'];

    /** @var array<int, array{url: string, body: array<string, mixed>}> */
    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config(['services.gemini_tts.dialect_rewrite' => false, 'services.gemini_tts.retry_pause_ms' => 0]);
        SiteSetting::set(GeminiTtsModel::SETTING_KEY, GeminiTtsModel::FLASH, 'tts');
        $key = ['key' => 'test-gemini-key', 'name' => 'GEMINI_TTS_API_KEY', 'index' => 1];
        $manager = \Mockery::mock(GeminiKeyManager::class);
        $manager->shouldReceive('getAllKeys')->andReturn([$key]);
        $manager->shouldReceive('getAvailableKey')->andReturn($key);
        $manager->shouldReceive('recordUsage')->andReturnNull();
        $this->app->instance(GeminiKeyManager::class, $manager);

        // Each piece answers with its own recording, marked with the first words it was given.
        Http::fake(function ($request) {
            $this->requests[] = ['url' => $request->url(), 'body' => $request->data()];
            $first = (string) ($request->data()['contents'][0]['parts'][0]['text'] ?? '');

            return Http::response(['candidates' => [['content' => ['parts' => [['inlineData' => [
                'mimeType' => 'audio/mpeg',
                'data' => base64_encode("\xFF\xFB\x90\xC4".'<piece '.md5($first).'>'.str_repeat("\0", 64)),
            ]]]]]]]);
        });
    }

    public function test_a_long_dialogue_is_made_in_pieces_split_at_turns_with_the_same_voices_and_joined_in_order(): void
    {
        $lines = [];
        for ($i = 1; $i <= 80; $i++) {
            $lines[] = ($i % 2 === 1 ? 'Sara' : 'Omar').": This is line number {$i} of our long conversation today.";
        }
        $script = implode("\n", $lines);
        $this->assertGreaterThan(2400, mb_strlen($script));

        $result = $this->dialogue($script);

        $this->assertGreaterThan(1, count($this->requests), 'made in pieces');
        $sizes = [];
        $spoken = [];
        foreach ($this->requests as $request) {
            $this->assertSame(self::CAST, $this->voices($request), 'the same speakers and voices in every piece');
            $parts = $request['body']['contents'][0]['parts'];
            $sizes[] = mb_strlen(implode("\n", array_map(fn (array $part): string => $part['speech_metadata']['speaker'].': '.$part['text'], $parts)));
            foreach ($parts as $part) {
                $spoken[] = $part['speech_metadata']['speaker'].': '.$part['text'];
            }
        }
        // Every line once, in order, and each piece starts on a turn: no line is cut.
        $this->assertSame($lines, $spoken);
        $this->assertLessThanOrEqual(1500, max($sizes));
        $this->assertGreaterThan(max($sizes) / 2, min($sizes), 'pieces of about even length');

        // The recording holds every piece, in the order of the script.
        $audio = Storage::disk('public')->get($result['audio_path']);
        $offsets = array_map(fn (array $request): int|false => strpos($audio, '<piece '.md5($request['body']['contents'][0]['parts'][0]['text']).'>'), $this->requests);
        $this->assertNotContains(false, $offsets);
        $sorted = $offsets;
        sort($sorted);
        $this->assertSame($sorted, $offsets);
    }

    public function test_a_piece_where_only_one_speaker_talks_keeps_both_voices(): void
    {
        $lines = ['Omar: So, tell me everything.'];
        for ($i = 1; $i <= 60; $i++) {
            $lines[] = "Sara: And then, on day {$i}, we walked a little further along the river.";
        }
        $lines[] = 'Omar: What a trip.';

        $this->dialogue(implode("\n", $lines));

        $this->assertGreaterThan(1, count($this->requests));
        $onlySara = 0;
        foreach ($this->requests as $request) {
            $this->assertSame(self::CAST, $this->voices($request));
            $speakers = array_unique(array_map(fn (array $part): string => $part['speech_metadata']['speaker'], $request['body']['contents'][0]['parts']));
            $onlySara += $speakers === ['Sara'] ? 1 : 0;
        }
        $this->assertGreaterThan(0, $onlySara, 'a piece of Sara alone, still made as the same two-voice dialogue');
    }

    public function test_one_very_long_turn_is_split_at_sentence_ends_with_its_speaker(): void
    {
        $speech = trim(str_repeat('We kept going even when the road was long and the night was cold. ', 40));
        $script = "Omar: Tell me the story.\nSara: {$speech}\nOmar: Wow.";

        $this->dialogue($script);

        $this->assertGreaterThan(1, count($this->requests));
        $words = [];
        foreach ($this->requests as $request) {
            $this->assertSame(self::CAST, $this->voices($request));
            foreach ($request['body']['contents'][0]['parts'] as $part) {
                $this->assertNotSame('', $part['text']);
                $this->assertStringNotContainsString('Sara:', $part['text'], 'the name is never read aloud');
                if ($part['speech_metadata']['speaker'] === 'Sara') {
                    $words = array_merge($words, preg_split('/\s+/', $part['text']));
                }
                $this->assertLessThanOrEqual(1500, mb_strlen($part['text']));
            }
        }
        $this->assertSame(preg_split('/\s+/', $speech), $words, 'all of her words, in order, all spoken by her');
    }

    public function test_three_speakers_are_made_two_voices_a_request_each_always_with_their_own_voice(): void
    {
        $script = "Sara: Welcome, both of you.\nOmar: Thanks for having me.\nNour: Glad to be here.\nSara: Omar, you first.\nOmar: Happy to.\nNour: And then me.";

        app(GeminiTtsProvider::class)->synthesize($script, 'en', ['speakers' => [
            ['name' => 'Sara', 'voice_name' => 'Kore'],
            ['name' => 'Omar', 'voice_name' => 'Puck'],
            ['name' => 'Nour', 'voice_name' => 'Leda'],
        ]]);

        $voices = ['Sara' => 'Kore', 'Omar' => 'Puck', 'Nour' => 'Leda'];
        $spoken = [];
        foreach ($this->requests as $request) {
            $cast = $this->voices($request);
            $this->assertCount(2, $cast, 'the engine takes two voices a request');
            foreach ($cast as $name => $voice) {
                $this->assertSame($voices[$name], $voice, $name.' keeps the same voice');
            }
            foreach ($request['body']['contents'][0]['parts'] as $part) {
                $this->assertArrayHasKey($part['speech_metadata']['speaker'], $cast, 'every line is spoken by a voice of its piece');
                $spoken[] = $part['speech_metadata']['speaker'].': '.$part['text'];
            }
        }
        $this->assertSame(explode("\n", $script), $spoken);
    }

    public function test_a_short_dialogue_is_still_one_request(): void
    {
        $this->dialogue("Sara: Hello there!\nOmar: Hi, good to see you.");

        $this->assertCount(1, $this->requests);
        $this->assertSame(['Hello there!', 'Hi, good to see you.'], array_column($this->requests[0]['body']['contents'][0]['parts'], 'text'));
    }

    public function test_the_older_prompt_model_gets_the_pieces_too_each_line_with_its_speaker_name(): void
    {
        SiteSetting::set(GeminiTtsModel::SETTING_KEY, GeminiTtsModel::CURRENT, 'tts');
        $lines = [];
        for ($i = 1; $i <= 60; $i++) {
            $lines[] = ($i % 2 === 1 ? 'Sara' : 'Omar').": This is line number {$i} of our long conversation today.";
        }

        $this->dialogue(implode("\n", $lines));

        $this->assertGreaterThan(1, count($this->requests));
        $pieceOf = $this->pieceOfEachLine($lines);
        foreach ($this->requests as $request) {
            $this->assertSame(self::CAST, $this->voices($request));
            $this->assertArrayHasKey('languageCode', $request['body']['generationConfig']['speechConfig']);
        }
        // Every line, with its name, in exactly one piece; the pieces in the order of the script.
        $this->assertSame(array_keys($lines), array_keys($pieceOf));
        $sorted = $pieceOf;
        sort($sorted);
        $this->assertSame($sorted, array_values($pieceOf));
    }

    public function test_a_mood_on_a_turn_of_its_own_stays_in_the_piece_of_the_words_it_directs(): void
    {
        // Small pieces, so the turn of the mood alone would end the first piece.
        SiteSetting::set(GeminiTtsModel::SETTING_KEY, GeminiTtsModel::CURRENT, 'tts');
        config(['services.gemini_tts.max_dialogue_chars_per_request' => 300]);
        $lines = [
            'Sara: '.str_repeat('a', 60).' '.str_repeat('b', 66),
            'Omar: '.str_repeat('c', 60).' '.str_repeat('d', 66),
            'Omar: [scared]',
            'Omar: '.str_repeat('e', 70).' '.str_repeat('f', 69),
        ];

        $this->dialogue(implode("\n", $lines));

        $pieceOf = $this->pieceOfEachLine($lines);
        $this->assertCount(2, $this->requests);
        $this->assertSame([0, 0, 1, 1], array_values($pieceOf), 'the mood is not left at the end of the piece before');
    }

    /**
     * In which request each line was sent (older model: the lines are in the prompt).
     *
     * @param  array<int, string>  $lines
     * @return array<int, int>
     */
    private function pieceOfEachLine(array $lines): array
    {
        $where = [];
        foreach ($this->requests as $n => $request) {
            $prompt = $request['body']['contents'][0]['parts'][0]['text'];
            foreach ($lines as $i => $line) {
                if (str_contains($prompt, $line)) {
                    $where[$i][] = $n;
                }
            }
        }
        foreach ($where as $i => $pieces) {
            $this->assertCount(1, $pieces, 'line '.$i.' is sent once');
        }
        ksort($where);

        return array_map(fn (array $pieces): int => $pieces[0], $where);
    }

    /**
     * @return array<string, mixed>
     */
    private function dialogue(string $script): array
    {
        $this->requests = [];

        return app(GeminiTtsProvider::class)->synthesize($script, 'en', ['speakers' => [
            ['name' => 'Sara', 'voice_name' => 'Kore'],
            ['name' => 'Omar', 'voice_name' => 'Puck'],
        ]]);
    }

    /**
     * Speaker => voice, as one request was cast.
     *
     * @param  array{body: array<string, mixed>}  $request
     * @return array<string, string>
     */
    private function voices(array $request): array
    {
        $cast = [];
        foreach ($request['body']['generationConfig']['speechConfig']['multiSpeakerVoiceConfig']['speakerVoiceConfigs'] as $config) {
            $cast[$config['speaker']] = $config['voiceConfig']['prebuiltVoiceConfig']['voiceName'];
        }

        return $cast;
    }
}
