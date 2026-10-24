<?php

namespace Tests\Feature;

use App\Services\Tts\GeminiKeyManager;
use App\Services\Tts\GeminiTtsProvider;
use App\Support\PieceCheck;
use App\Support\WavPcm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * A long premium voice is read in pieces, each its own take of the same voice
 * and direction. Each take is checked before it is joined: one that stops
 * early, repeats itself, holds a dead silence or strays from the voice's pace
 * or pitch is taken once more and the better take is kept. The takes here are
 * a real premium voice's recording (public/audio), cut and changed; speech is
 * never generated.
 */
class PieceCheckTest extends TestCase
{
    use RefreshDatabase;

    private const TAKE = 'audio/20260304_083722_listen.studio_do6CnBaS2kFt.wav';

    /** 16-bit mono PCM at 24 kHz. */
    private string $pcm;

    protected function setUp(): void
    {
        parent::setUp();

        $wav = (string) file_get_contents(public_path(self::TAKE));
        $at = WavPcm::locate($wav);
        $this->assertNotNull($at);
        $this->assertSame([24000, 1], [$at['rate'], $at['channels']]);
        $this->pcm = substr($wav, $at['offset'], $at['length']);
    }

    public function test_a_real_take_is_measured(): void
    {
        $m = PieceCheck::measure($this->wav($this->pcm));

        $this->assertEqualsWithDelta(27, $m['seconds'], 1.5);
        $this->assertLessThan(1.5, $m['silence']);
        $this->assertGreaterThan(80, $m['pitch']);
        $this->assertLessThan(400, $m['pitch']);
        $this->assertNull(PieceCheck::measure('not audio'));
    }

    public function test_a_take_that_stops_early_or_runs_long_is_found(): void
    {
        $full = PieceCheck::measure($this->wav($this->pcm));
        $expected = $full['seconds'];

        $this->assertSame([], PieceCheck::problems($full, $expected));
        $early = PieceCheck::measure($this->wav($this->slice(0, 0.4 * $expected)));
        $this->assertContains('short', PieceCheck::problems($early, $expected));
        $twice = PieceCheck::measure($this->wav($this->pcm.$this->pcm));
        $this->assertContains('long', PieceCheck::problems($twice, $expected));
    }

    public function test_a_dead_silence_inside_a_take_is_found_unless_its_text_asks_for_a_pause(): void
    {
        $half = intdiv(strlen($this->pcm), 4) * 2;
        $stalled = PieceCheck::measure($this->wav(substr($this->pcm, 0, $half).str_repeat("\0", 2 * 24000 * 4).substr($this->pcm, $half)));

        $this->assertGreaterThan(3.9, $stalled['silence']);
        $this->assertContains('silence', PieceCheck::problems($stalled, $stalled['seconds']));
        $this->assertNotContains('silence', PieceCheck::problems($stalled, $stalled['seconds'], [], true));
    }

    public function test_a_take_at_another_pitch_or_pace_than_the_voice_stands_out(): void
    {
        $this->needsFfmpeg();
        $first = $this->slice(0, 13);
        $voice = PieceCheck::measure($this->wav($first));
        $same = PieceCheck::measure($this->wav($this->slice(13, 27)));

        $this->assertSame([], PieceCheck::problems($same, $same['seconds'], ['pace' => $voice['pace'], 'pitch' => $voice['pitch']]));
        $higher = PieceCheck::measure($this->changed($first, 'asetrate=24000*1.26,aresample=24000,atempo=0.7937'));
        $this->assertSame(['pitch'], PieceCheck::problems($higher, $voice['seconds'], ['pace' => $voice['pace'], 'pitch' => $voice['pitch']]));
        $faster = PieceCheck::measure($this->changed($first, 'atempo=1.4'));
        $this->assertContains('pace', PieceCheck::problems($faster, $faster['seconds'], ['pace' => $voice['pace'], 'pitch' => $voice['pitch']]));
    }

    public function test_a_childs_take_pitched_like_a_grown_ups_is_taken_again(): void
    {
        $this->needsFfmpeg();
        $first = $this->slice(0, 13);
        $grownUp = PieceCheck::measure($this->wav($first));
        $childWav = $this->changed($first, 'asetrate=24000*1.6,aresample=24000,atempo=0.625');
        $child = PieceCheck::measure($childWav);
        $floor = $grownUp['pitch'] * 1.3;

        $this->assertSame(['pitch'], PieceCheck::problems($grownUp, $grownUp['seconds'], ['floor' => $floor]));
        $this->assertSame([], PieceCheck::problems($child, $child['seconds'], ['floor' => $floor]));

        $asked = [];
        $history = ['paces' => [], 'pitches' => []];
        $report = PieceCheck::emptyReport();
        $kept = PieceCheck::review([['bytes' => $this->wav($first)]], [['expected' => $grownUp['seconds'], 'pauses' => false, 'floor' => $floor]], function (int $k) use (&$asked, $childWav): array {
            $asked[] = $k;

            return ['bytes' => $childWav];
        }, $history, $report);

        $this->assertSame([0], $asked);
        $this->assertSame($childWav, $kept[0]['bytes'], "the child's take is kept");
        $this->assertSame(1, $report['reasons']['pitch']);
    }

    public function test_the_odd_take_of_a_round_is_taken_again_and_the_better_take_kept(): void
    {
        $pieces = [$this->slice(0, 9), $this->slice(9, 18), $this->slice(18, 27)];
        $texts = array_map(fn ($p) => ['expected' => PieceCheck::measure($this->wav($p))['seconds'], 'pauses' => false], $pieces);
        $takes = [['bytes' => $this->wav($pieces[0])], ['bytes' => $this->wav(substr($pieces[1], 0, intdiv(strlen($pieces[1]), 6) * 2))], ['bytes' => $this->wav($pieces[2])]];
        $asked = [];
        $history = ['paces' => [], 'pitches' => []];
        $report = PieceCheck::emptyReport();

        $kept = PieceCheck::review($takes, $texts, function (int $k) use (&$asked, $pieces): array {
            $asked[] = $k;

            return ['bytes' => $this->wav($pieces[$k])];
        }, $history, $report);

        $this->assertSame([1], $asked, 'only the take that stopped early is taken again');
        $this->assertSame($this->wav($pieces[1]), $kept[1]['bytes']);
        $this->assertSame($takes[0], $kept[0]);
        $this->assertSame([3, 1, 1], [$report['pieces'], $report['retaken'], $report['kept_retake']]);
        $this->assertSame(1, $report['reasons']['short']);
        $this->assertCount(3, $history['paces'][0]);
    }

    public function test_a_part_read_with_another_direction_is_not_judged_against_the_rest(): void
    {
        $this->needsFfmpeg();
        $pieces = [$this->slice(0, 9), $this->slice(9, 18), $this->slice(18, 27)];
        // The third piece is a mood tag's part ([excited]): higher and faster on purpose.
        $takes = [['bytes' => $this->wav($pieces[0])], ['bytes' => $this->wav($pieces[1])],
            ['bytes' => $this->changed($pieces[2], 'asetrate=24000*1.26,aresample=24000,atempo=1.1')]];
        $texts = [];
        foreach ($takes as $k => $take) {
            $texts[$k] = ['expected' => PieceCheck::measure($take['bytes'])['seconds'], 'pauses' => false, 'part' => $k === 2 ? 1 : 0];
        }
        $history = ['paces' => [], 'pitches' => []];
        $report = PieceCheck::emptyReport();

        PieceCheck::review($takes, $texts, fn () => $this->fail('a mood part is meant to sound different'), $history, $report);

        $this->assertSame(0, $report['retaken']);
        $this->assertCount(2, $history['pitches'][0]);
        $this->assertCount(1, $history['pitches'][1]);
    }

    public function test_a_direction_read_in_two_pieces_keeps_them_in_one_tone(): void
    {
        $this->needsFfmpeg();
        $pieces = [$this->slice(0, 13), $this->slice(13, 27)];
        $texts = array_map(fn ($p) => ['expected' => PieceCheck::measure($this->wav($p))['seconds'], 'pauses' => false], $pieces);
        $even = array_map(fn ($p) => ['bytes' => $this->wav($p)], $pieces);
        // The first piece four semitones higher: the two halves of one reading in two tones, as a test voice's were on staging.
        $higher = [['bytes' => $this->changed($pieces[0], 'asetrate=24000*1.26,aresample=24000,atempo=0.7937')], $even[1]];

        $history = ['paces' => [], 'pitches' => []];
        $report = PieceCheck::emptyReport();
        $this->assertSame($even, PieceCheck::review($even, $texts, fn () => $this->fail('the two pieces sound alike'), $history, $report));
        $this->assertSame(0, $report['retaken']);
        $this->assertLessThan(PieceCheck::PAIR_SEMITONES / 2, $report['pitch_spread']);

        $asked = [];
        $history = ['paces' => [], 'pitches' => []];
        $report = PieceCheck::emptyReport();
        $kept = PieceCheck::review($higher, $texts, function (int $k) use (&$asked, $even): array {
            $asked[] = $k;

            return $even[$k];
        }, $history, $report);
        $this->assertSame([1, 0], $asked, 'the later piece is taken again, and as that does not bring them together, the earlier one');
        $this->assertSame($even, $kept);
        $this->assertSame([2, 1, 2], [$report['retaken'], $report['kept_retake'], $report['reasons']['pitch']]);

        // The first piece came in an earlier round: the second is still set against it.
        $history = ['paces' => [], 'pitches' => []];
        $report = PieceCheck::emptyReport();
        PieceCheck::review([0 => $higher[0]], [0 => $texts[0]], fn () => $this->fail('one piece alone'), $history, $report);
        $asked = [];
        PieceCheck::review([1 => $even[1]], [1 => $texts[1]], function (int $k) use (&$asked, $even): array {
            $asked[] = $k;

            return $even[$k];
        }, $history, $report);
        $this->assertSame([1], $asked);
        $this->assertCount(2, $history['pitches'][0]);
    }

    public function test_takes_of_one_voice_are_left_alone_and_a_retake_that_fails_keeps_the_first(): void
    {
        $pieces = [$this->slice(0, 9), $this->slice(9, 18), $this->slice(18, 27)];
        $texts = array_map(fn ($p) => ['expected' => PieceCheck::measure($this->wav($p))['seconds'], 'pauses' => false], $pieces);
        $takes = array_map(fn ($p) => ['bytes' => $this->wav($p)], $pieces);
        $history = ['paces' => [], 'pitches' => []];
        $report = PieceCheck::emptyReport();
        $this->assertSame($takes, PieceCheck::review($takes, $texts, fn () => $this->fail('nothing to take again'), $history, $report));
        // How far the kept pieces stray from the voice, kept with the voice.
        $this->assertNotNull($report['pitch_spread']);
        $this->assertLessThan(PieceCheck::PITCH_SEMITONES, $report['pitch_spread']);
        $this->assertLessThan(PieceCheck::PACE_DIFFERENCE, $report['pace_spread']);

        $takes[2] = ['bytes' => $this->wav(substr($pieces[2], 0, 24000 * 2))];
        $history = ['paces' => [], 'pitches' => []];
        $kept = PieceCheck::review($takes, $texts, fn () => throw new \RuntimeException('Gemini is busy.'), $history, $report);
        $this->assertSame($takes[2], $kept[2], 'the voice is still delivered');
    }

    public function test_a_long_premium_text_takes_its_odd_piece_again_and_tells_each_piece_it_is_one_reading(): void
    {
        $this->assertFalse((bool) config('services.gemini_tts.check_pieces'), 'off for the faked takes of other tests (phpunit.xml)');
        config(['services.gemini_tts.check_pieces' => true]);

        [$result, $asked, $styles] = $this->readLongText();

        $this->assertSame([0 => 1, 1 => 2, 2 => 1], $asked, 'the piece that stopped early is asked for once more');
        $this->assertSame([3, 1, 1], [$result['pieces_check']['pieces'], $result['pieces_check']['retaken'], $result['pieces_check']['kept_retake']]);
        $this->assertSame(1, $result['pieces_check']['reasons']['short']);
        $this->assertStringContainsString('This is part 1 of 3 of one continuous recording', $styles[0]);
        $this->assertStringContainsString('The recording goes on after it', $styles[0]);
        $this->assertStringNotContainsString('carries straight on', $styles[0]);
        $this->assertStringContainsString('part 2 of 3', $styles[1]);
        $this->assertStringContainsString('carries straight on from the part before it', $styles[2]);
        $this->assertStringNotContainsString('goes on after it', $styles[2]);
        Storage::disk('public')->assertExists($result['audio_path']);
    }

    public function test_switched_off_no_take_is_checked_or_taken_again(): void
    {
        config(['services.gemini_tts.check_pieces' => false]);

        [$result, $asked] = $this->readLongText();

        $this->assertSame([0 => 1, 1 => 1, 2 => 1], $asked);
        $this->assertSame(0, $result['pieces_check']['retaken']);
    }

    /**
     * A premium voice reads three sentences in three pieces (pieces of up to
     * 200 characters); each piece's take is the recording cut to what its
     * text needs, and the second piece's first take stops after a few words.
     *
     * @return array{0: array<string, mixed>, 1: array<int, int>, 2: array<int, string>}
     */
    private function readLongText(): array
    {
        Storage::fake('public');
        config([
            'services.gemini_tts.ffmpeg_path' => 'none',
            'services.gemini_tts.api_key' => 'test-key',
            'services.gemini_tts.retry_pause_ms' => 0,
            'services.gemini_tts.voice_retry_pause' => false,
            'services.gemini_tts.dialect_rewrite' => false,
            'services.gemini_tts.max_chars_per_request' => 200,
            'services.gemini_tts.max_parallel_requests' => 4,
            'services.gemini_tts.model' => 'gemini-3.8-flash-tts',
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

        $sentences = ['The rain had stopped by the time we reached the old bridge over the river, and we waited there for the others to come.',
            'Nobody spoke for a while; the water was loud under the stones and the light was going fast behind the hills.',
            'Then a lamp came round the bend of the road, and we knew the long night was over at last for all of us.'];
        $asked = [];
        $styles = [];
        Http::fake(function (Request $request) use ($sentences, &$asked, &$styles) {
            $part = $request->data()['contents'][0]['parts'][0];
            $k = (int) array_search(true, array_map(fn ($s) => str_contains($part['text'], mb_substr($s, 0, 30)), $sentences), true);
            $asked[$k] = ($asked[$k] ?? 0) + 1;
            $styles[$k] = (string) ($part['speech_metadata']['style'] ?? '');
            $pcm = $this->slice(0, min(27, GeminiTtsProvider::pieceText($part['text'], 1.0, [])['expected']));
            if ($k === 1 && $asked[$k] === 1) {
                $pcm = substr($pcm, 0, intdiv(strlen($pcm), 8) * 2);
            }

            return Http::response(['candidates' => [['content' => ['parts' => [['inlineData' => ['mimeType' => 'audio/L16;codec=pcm;rate=24000', 'data' => base64_encode($pcm)]]]]]]]);
        });

        $result = app(GeminiTtsProvider::class)->synthesize(implode(' ', $sentences), 'en', ['voice_name' => 'Kore', 'gender' => 'female']);
        ksort($asked);

        return [$result, $asked, $styles];
    }

    /** The recording from $from to $to seconds. */
    private function slice(float $from, float $to): string
    {
        return substr($this->pcm, (int) round($from * 24000) * 2, (int) round(($to - $from) * 24000) * 2);
    }

    private function wav(string $pcm): string
    {
        return WavPcm::header(strlen($pcm), 24000).$pcm;
    }

    private function needsFfmpeg(): void
    {
        if ((new ExecutableFinder)->find('ffmpeg') === null) {
            $this->markTestSkipped('ffmpeg is not installed.');
        }
    }

    /** $pcm through an ffmpeg filter, as a WAV. */
    private function changed(string $pcm, string $filter): string
    {
        $in = tempnam(sys_get_temp_dir(), 'piece');
        $out = $in.'.wav';
        file_put_contents($in, $this->wav($pcm));
        $process = new Process(['ffmpeg', '-hide_banner', '-loglevel', 'error', '-y', '-i', $in, '-af', $filter, '-ac', '1', '-ar', '24000', '-c:a', 'pcm_s16le', $out]);
        $process->run();
        $bytes = (string) @file_get_contents($out);
        @unlink($in);
        @unlink($out);
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());

        return $bytes;
    }
}
