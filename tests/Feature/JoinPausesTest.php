<?php

namespace Tests\Feature;

use App\Services\Tts\GeminiKeyManager;
use App\Services\Tts\GeminiTtsProvider;
use App\Services\VoiceCloningService;
use App\Support\JoinPauses;
use App\Support\SpeechJoin;
use App\Support\TextChunker;
use App\Support\WavPcm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A long text is read in pieces. At each join the pieces' own quiet is cut
 * and the pause is the one the text calls for: longer after a sentence than
 * after a comma, barely a gap where a piece had to stop mid-sentence. The
 * takes are saved as WAV (FFMPEG_PATH=none) so the gaps can be measured to
 * the sample; speech is faked.
 */
class JoinPausesTest extends TestCase
{
    use RefreshDatabase;

    /** The faked engine's take: 0.25 s of quiet, 0.6 s of voice, 0.3 s of quiet. */
    private const LEAD = 0.25;

    private const VOICE = 0.6;

    private const TAIL = 0.3;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config([
            'services.gemini_tts.ffmpeg_path' => 'none',
            'services.gemini_tts.api_key' => 'test-key',
            'services.gemini_tts.retry_pause_ms' => 0,
            'services.gemini_tts.voice_retry_pause' => false,
            'services.gemini_tts.dialect_rewrite' => false,
            'services.gemini_tts.max_chars_per_request' => 200,
            'services.gemini_tts.max_parallel_requests' => 1,
        ]);
        $key = ['key' => 'test-gemini-key', 'name' => 'GEMINI_TTS_API_KEY', 'index' => 1];
        $manager = \Mockery::mock(GeminiKeyManager::class);
        $manager->shouldReceive('getAllKeys')->andReturn([$key]);
        $manager->shouldReceive('getAvailableKey')->andReturn($key);
        $manager->shouldReceive('keysInOrder')->andReturn([$key]);
        $manager->shouldReceive('isOutForToday')->andReturn(false);
        $manager->shouldReceive('recordUsage')->andReturnNull();
        $manager->shouldReceive('markKeyExhausted')->andReturnNull();
        $this->app->instance(GeminiKeyManager::class, $manager);
    }

    public function test_each_join_has_the_pause_its_text_calls_for_and_the_pieces_quiet_is_cut(): void
    {
        $joined = SpeechJoin::toFile([$this->take(), $this->take(), $this->take(), $this->take()],
            [JoinPauses::SENTENCE, JoinPauses::CLAUSE, JoinPauses::MID_SENTENCE], [], SpeechJoin::PAUSE, true);
        $wav = (string) file_get_contents($joined['path']);
        unlink($joined['path']);

        [$lead, $gaps, $tail] = $this->silences($wav);
        $this->assertEqualsWithDelta(JoinPauses::SENTENCE, $gaps[0], 0.011);
        $this->assertEqualsWithDelta(JoinPauses::CLAUSE, $gaps[1], 0.011);
        $this->assertEqualsWithDelta(JoinPauses::MID_SENTENCE, $gaps[2], 0.011);
        // The take's own edges are left to the packaging (cut to 0.2 s and 0.35 s there).
        $this->assertEqualsWithDelta(self::LEAD, $lead, 0.011);
        $this->assertEqualsWithDelta(self::TAIL, $tail, 0.011);
        $this->assertCount(4, $joined['pieces']);
    }

    public function test_before_every_join_had_about_0_85_s_whatever_the_text(): void
    {
        // What a join used to be: the fixed 0.3 s on top of each piece's own quiet.
        $joined = SpeechJoin::toFile([$this->take(), $this->take()]);
        [, $gaps] = $this->silences((string) file_get_contents($joined['path']));
        unlink($joined['path']);

        $this->assertEqualsWithDelta(self::TAIL + SpeechJoin::PAUSE + self::LEAD, $gaps[0], 0.011);
    }

    public function test_a_long_premium_text_pauses_where_its_text_does(): void
    {
        $text = $this->story();
        $expected = $this->expectedPauses($text);
        Http::fake(fn () => Http::response(['candidates' => [['content' => ['parts' => [[
            'inlineData' => ['mimeType' => 'audio/L16;codec=pcm;rate=24000', 'data' => base64_encode($this->take())],
        ]]]]]]));

        $result = app(GeminiTtsProvider::class)->synthesize($text, 'en', ['voice_name' => 'Kore', 'gender' => 'female']);

        $this->assertStringEndsWith('.wav', $result['audio_path']);
        [, $gaps] = $this->silences((string) Storage::disk('public')->get($result['audio_path']));
        $this->assertCount(count($expected), $gaps);
        foreach ($expected as $k => $pause) {
            $this->assertEqualsWithDelta($pause, $gaps[$k], 0.011, 'join '.($k + 1));
        }
    }

    public function test_a_long_cloned_script_pauses_where_its_text_does(): void
    {
        $text = $this->story();
        $expected = $this->expectedPauses($text);
        Http::fake(fn () => Http::response(['output_audio' => ['data' => base64_encode(WavPcm::header(strlen($this->take()), 24000).$this->take())]]));

        $result = app(VoiceCloningService::class)->synthesizeWithClonedVoice($text, 'en-US', 'voice_story');

        [, $gaps] = $this->silences((string) Storage::disk('public')->get($result['audio_path']));
        $this->assertCount(count($expected), $gaps);
        foreach ($expected as $k => $pause) {
            $this->assertEqualsWithDelta($pause, $gaps[$k], 0.011, 'join '.($k + 1));
        }
    }

    public function test_a_long_dialogue_is_joined_on_disk_with_a_readers_pause_between_its_pieces(): void
    {
        config(['services.gemini_tts.max_dialogue_chars_per_request' => 300]);
        $script = implode("\n", [
            'Sara: '.str_repeat('We should leave early tomorrow. ', 4),
            'Omar: '.str_repeat('The road is long and the bridge is closed. ', 3),
            'Sara: '.str_repeat('Then we take the coast road instead. ', 4),
            'Omar: '.str_repeat('Fine, I will pack the car tonight. ', 4),
        ]);
        Http::fake(fn () => Http::response(['candidates' => [['content' => ['parts' => [[
            'inlineData' => ['mimeType' => 'audio/L16;codec=pcm;rate=24000', 'data' => base64_encode($this->take())],
        ]]]]]]));

        $result = app(GeminiTtsProvider::class)->synthesize($script, 'en', ['speakers' => [
            ['name' => 'Sara', 'voice_name' => 'Kore'],
            ['name' => 'Omar', 'voice_name' => 'Puck'],
        ]]);

        // Each piece ends at the end of a turn: a sentence's pause, its own quiet cut.
        $this->assertStringEndsWith('.wav', $result['audio_path']);
        [, $gaps] = $this->silences((string) Storage::disk('public')->get($result['audio_path']));
        $this->assertGreaterThanOrEqual(1, count($gaps));
        foreach ($gaps as $k => $gap) {
            $this->assertEqualsWithDelta(JoinPauses::SENTENCE, $gap, 0.011, 'join '.($k + 1));
        }
    }

    public function test_a_piece_with_no_sound_is_joined_whole(): void
    {
        $quiet = str_repeat("\0", 2 * 24000);
        $joined = SpeechJoin::toFile([$this->take(), $quiet, $this->take()], [JoinPauses::SENTENCE, JoinPauses::SENTENCE], [], SpeechJoin::PAUSE, true);
        $size = filesize($joined['path']);
        unlink($joined['path']);

        // Nothing of the quiet piece is cut: it may be a pause of its own.
        $this->assertGreaterThan(WavPcm::HEADER_BYTES + 2 * (int) (24000 * (2 * (self::VOICE) + 1.0 + 2 * JoinPauses::SENTENCE - 0.1)), $size);
    }

    /**
     * Three paragraphs' worth of text that the 200-character pieces split at a
     * sentence end, at a comma and inside a long clause.
     */
    private function story(): string
    {
        return 'The rain had stopped by the time we reached the old bridge over the river. We waited there for a while and watched the water run under the stones. '
            .'When the lights of the town finally came on one by one along the far bank of the river and the boats began to move slowly toward the harbour again, '
            .'we got up and walked home through the quiet streets without saying a word. '
            .'And then we walked along the empty road past the farms and the fields and the long rows of trees that stood dark against the sky for what felt like hours and hours until at last we came to the gate of the old house where we had lived as children.';
    }

    /**
     * @return array<int, float>
     */
    private function expectedPauses(string $text): array
    {
        $pieces = TextChunker::pieces($text, 200, 'mb_strlen', 1);
        $pauses = array_map(fn (array $p): float => JoinPauses::after($p['text'], $p['gap']), array_slice($pieces, 0, -1));
        // The story is built so all three kinds of join are in it.
        $this->assertContains(JoinPauses::SENTENCE, $pauses);
        $this->assertContains(JoinPauses::CLAUSE, $pauses);
        $this->assertContains(JoinPauses::MID_SENTENCE, $pauses);

        return $pauses;
    }

    /**
     * One faked take of 16-bit PCM at 24 kHz: quiet, a 220 Hz voice, quiet.
     */
    private function take(): string
    {
        $voice = [];
        for ($i = 0; $i < (int) (self::VOICE * 24000); $i++) {
            $voice[] = (int) round(8000 * sin(2 * M_PI * 220 * $i / 24000));
        }

        return str_repeat("\0", 2 * (int) (self::LEAD * 24000)).pack('v*', ...$voice).str_repeat("\0", 2 * (int) (self::TAIL * 24000));
    }

    /**
     * The quiet before the first sound, between sounds (each run of 10 ms
     * windows under -50 dB longer than 50 ms) and after the last, in seconds.
     *
     * @return array{0: float, 1: array<int, float>, 2: float}
     */
    private function silences(string $wav): array
    {
        $pcm = WavPcm::locate($wav);
        $loud = [];
        for ($at = $pcm['offset']; $at + 480 <= $pcm['offset'] + $pcm['length']; $at += 480) {
            $samples = unpack('s*', substr($wav, $at, 480));
            $loud[] = max($samples) >= 104 || min($samples) <= -104;
        }
        $first = array_search(true, $loud, true);
        $last = count($loud) - 1 - (int) array_search(true, array_reverse($loud), true);
        $gaps = [];
        $run = 0;
        for ($i = (int) $first; $i <= $last; $i++) {
            if (! $loud[$i]) {
                $run++;
            } elseif ($run > 0) {
                if ($run > 5) {
                    $gaps[] = $run / 100;
                }
                $run = 0;
            }
        }

        return [(int) $first / 100, $gaps, (count($loud) - 1 - $last) / 100];
    }
}
