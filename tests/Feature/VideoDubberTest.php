<?php

namespace Tests\Feature;

use App\Console\Commands\DubMeasureCommand;
use App\Services\Dubbing\VideoDubber;
use App\Services\Tts\GeminiKeyManager;
use App\Services\Tts\GeminiTtsProvider;
use App\Support\AudioFormats;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The "Dub my video" prototype end to end on a real video, with the text
 * model and the speech engine faked (no network): the timed lines come back,
 * each is voiced, placed at its time and put back onto the picture, and a
 * marked preview is made.
 */
class VideoDubberTest extends TestCase
{
    // The studio's emotion list is read from the site settings.
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        if (! AudioFormats::ffmpegPath()) {
            $this->markTestSkipped('ffmpeg is not installed here.');
        }
        Storage::fake('public');
        $this->dir = sys_get_temp_dir().'/dub-test-'.bin2hex(random_bytes(4));
        @mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function test_a_video_comes_back_dubbed_with_what_it_cost(): void
    {
        $ffmpeg = AudioFormats::ffmpegPath();
        $video = $this->dir.'/in.mp4';
        (new Process([$ffmpeg, '-v', 'error', '-y', '-f', 'lavfi', '-i', 'testsrc2=size=320x240:rate=15', '-f', 'lavfi', '-i', 'sine=frequency=300:duration=6',
            '-t', '6', '-c:v', 'libx264', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-shortest', $video]))->mustRun();

        $keys = \Mockery::mock(GeminiKeyManager::class);
        $keys->shouldReceive('keysInOrder')->andReturn([['key' => 'k1', 'name' => 'GEMINI_TTS_API_KEY', 'index' => 1]]);
        $keys->shouldReceive('recordUsage');
        Http::fake(['*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => json_encode(['background' => 'music', 'speakers' => [['id' => 'A', 'gender' => 'male', 'voice' => 'young energetic host, fast'], ['id' => 'B', 'gender' => 'female', 'voice' => 'warm woman, calm']], 'segments' => [
                ['start' => 3.0, 'end' => 5.5, 'speaker' => 'B', 'emotion' => 'warm', 'delivery' => 'soft and smiling', 'original' => 'See you tomorrow.', 'dubbed' => 'نشوفك بكرة.'],
                ['start' => 0.2, 'end' => 2.8, 'speaker' => 'A', 'emotion' => 'excited', 'delivery' => 'big and bright, like a show host', 'original' => 'Hello there, welcome.', 'dubbed' => 'هلا والله، حياك.'],
            ]])]]]]],
            'usageMetadata' => ['promptTokenCount' => 260, 'candidatesTokenCount' => 90, 'thoughtsTokenCount' => 40,
                'promptTokensDetails' => [['modality' => 'TEXT', 'tokenCount' => 68], ['modality' => 'AUDIO', 'tokenCount' => 192]]],
        ])]);

        // Each line voiced: a real short clip on the public disk, the second one too long for its room.
        $tts = \Mockery::mock(GeminiTtsProvider::class);
        $n = 0;
        $voiced = [];
        $tts->shouldReceive('synthesize')->twice()->andReturnUsing(function (string $text, string $language, array $config) use ($ffmpeg, &$n, &$voiced) {
            $voiced[$text] = $config;
            $this->assertSame('ar', $language);
            $this->assertSame('ar-SA', $config['dialect']);
            $this->assertFalse(config('services.gemini_tts.dialect_rewrite'), 'the lines are already in the dialect');
            $path = 'audio/line-'.(++$n).'.mp3';
            $file = Storage::disk('public')->path($path);
            @mkdir(dirname($file), 0755, true);
            (new Process([$ffmpeg, '-v', 'error', '-y', '-f', 'lavfi', '-i', 'sine=frequency=500:duration='.($n === 1 ? '3.4' : '2'), '-c:a', 'libmp3lame', $file]))->mustRun();

            return ['audio_path' => $path, 'duration_seconds' => 2];
        });

        $dubber = new VideoDubber($keys, $tts);
        $stages = [];
        $r = $dubber->dub($video, ['language' => 'ar', 'dialect' => 'ar-SA', 'voice' => 'Puck', 'voices' => ['male' => ['Puck', 'Orus'], 'female' => ['Kore', 'Leda']], 'each_speaker' => true,
            'out_dir' => $this->dir, 'name' => 'out', 'progress' => function (string $stage) use (&$stages) { $stages[] = $stage; }]);

        // The one who says the most reads in the member's voice, the woman in a woman's voice; each line in the original's mood.
        $this->assertSame('Puck', $voiced['هلا والله، حياك.']['voice_name']);
        $this->assertSame('Kore', $voiced['نشوفك بكرة.']['voice_name']);
        $this->assertSame('excited', $voiced['هلا والله، حياك.']['emotion']);
        // Each line with its speaker's one description of their voice, so they sound the same person all through.
        $this->assertStringContainsString('young energetic host, fast', $voiced['هلا والله، حياك.']['style_prompt']);
        $this->assertStringContainsString('warm woman, calm', $voiced['نشوفك بكرة.']['style_prompt']);
        $this->assertStringNotContainsString('like a show host', $voiced['هلا والله، حياك.']['style_prompt']);
        $this->assertSame(['listening', 'voicing', 'voicing', 'mixing', 'marking'], $stages);
        $this->assertSame('music', $r['background']);
        $this->assertTrue($r['kept_background']);
        // Each line brought to the loudness of the original speech.
        $this->assertNotNull($r['loudness']['target_db']);
        $this->assertCount(2, $r['loudness']['gains_db']);
        $this->assertSame('B', $r['lines'][1]['speaker']);

        $this->assertEqualsWithDelta(6.0, $r['video_seconds'], 0.1);
        $this->assertSame(2, $r['segments']);
        $this->assertSame(['input_tokens' => 260, 'audio_tokens' => 192, 'output_tokens' => 90, 'thinking_tokens' => 40], array_intersect_key($r['transcribe'], array_flip(['input_tokens', 'audio_tokens', 'output_tokens', 'thinking_tokens'])));
        $this->assertSame(mb_strlen('هلا والله، حياك.') + mb_strlen('نشوفك بكرة.'), $r['voicing']['characters']);
        // In order of time; the first line (3.4 s for 2.8 s) is sped up, the second (2 s for 2.5 s) drawn out a little to fill its time.
        $this->assertSame('هلا والله، حياك.', $r['lines'][0]['dubbed']);
        $this->assertGreaterThan(1.0, $r['lines'][0]['tempo']);
        $this->assertSame(VideoDubber::MIN_TEMPO, $r['lines'][1]['tempo']);
        $this->assertFileExists($r['output']);
        $this->assertFileExists($r['preview']);
        // The voiced lines are not left on the disk.
        $this->assertSame([], Storage::disk('public')->allFiles('audio'));
        // The dubbed video is as long as the original and has both picture and sound.
        $probe = new Process([$ffmpeg, '-hide_banner', '-i', $r['output']]);
        $probe->run();
        $this->assertMatchesRegularExpression('/Duration: 00:00:0[56]\./', $probe->getErrorOutput());
        $this->assertStringContainsString('Video:', $probe->getErrorOutput());
        $this->assertStringContainsString('Audio: aac', $probe->getErrorOutput());
    }

    public function test_lines_keep_to_the_original_timing_within_natural_speeds(): void
    {
        $plan = VideoDubber::plan([
            ['start' => 0.5, 'end' => 1.9, 'voiced_seconds' => 1.4], // as long as the original: as it is
            ['start' => 2.0, 'end' => 4, 'voiced_seconds' => 3.0],   // 3 s for 1.95 s of room: x1.25 at most, 0.45 s over
            ['start' => 4.0, 'end' => 4.9, 'voiced_seconds' => 0.85], // a little short: drawn out to end with the original
            ['start' => 6.0, 'end' => 9, 'voiced_seconds' => 2.0],   // far shorter: drawn out only as far as sounds natural
        ], 10.0);

        $this->assertSame([500, 2000, 4000, 6000], array_column($plan, 'delay_ms'));
        $this->assertSame([1.0, VideoDubber::MAX_TEMPO, 0.944, VideoDubber::MIN_TEMPO], array_column($plan, 'tempo'));
        $this->assertEqualsWithDelta(0.45, $plan[1]['overrun'], 0.01);
    }

    public function test_each_line_starts_and_ends_where_the_original_speaker_does(): void
    {
        // 10 s, quiet at -60 dB; speech at -20 dB from 1.3 to 3.2 s and from 4.0 to 6.0 s; nothing near 8 s.
        $envelope = [];
        for ($f = 0; $f < 1000; $f++) {
            $t = $f / 100;
            $envelope[] = ($t >= 1.3 && $t < 3.2) || ($t >= 4.0 && $t < 6.0) ? -20.0 : -60.0;
        }
        $segments = VideoDubber::refineTimes([
            ['start' => 1.0, 'end' => 3.0],   // heard a little early, and ending early
            ['start' => 4.4, 'end' => 6.3],   // heard late, and ending late
            ['start' => 7.8, 'end' => 8.6],   // no speech there: as it was
        ], $envelope, 10.0);

        $this->assertSame([1.3, 4.0, 7.8], array_column($segments, 'start'));
        $this->assertSame([3.2, 6.0, 8.6], array_column($segments, 'end'));
        $this->assertSame([1.0, 4.4, 7.8], array_column($segments, 'heard_start'), 'what the model said stays for the report');
    }

    public function test_speech_the_lines_leave_out_is_found(): void
    {
        // 60 s, quiet at -60 dB; speech at -20 dB from 1 to 9 s, from 20 to 26 s and from 40 to 52 s.
        $envelope = [];
        for ($f = 0; $f < 6000; $f++) {
            $t = $f / 100;
            $envelope[] = ($t >= 1 && $t < 9) || ($t >= 20 && $t < 26) || ($t >= 40 && $t < 52) ? -20.0 : -60.0;
        }
        $spans = VideoDubber::unheard([
            ['start' => 1.0, 'end' => 5.0, 'dubbed' => 'هلا والله، حياك يا صديقي.'],
            ['start' => 5.0, 'end' => 9.0, 'dubbed' => 'وش أخبارك اليوم؟ عساك طيب.'],
            // Twelve seconds of speech in two words: the model summed it up.
            ['start' => 40.0, 'end' => 52.0, 'dubbed' => 'شكراً لكم.'],
        ], $envelope);

        // The line far too short for its speech is heard again, and so are the 6 s no line covers; the longest first.
        $this->assertSame([['from' => 40.0, 'to' => 52.0, 'replace' => 2], ['from' => 20.0, 'to' => 26.0, 'replace' => null]], $spans);
        // Nothing to go by when no speech stands out of the rest of the sound.
        $this->assertSame([], VideoDubber::unheard([['start' => 1.0, 'end' => 2.0, 'dubbed' => 'هلا.']], array_fill(0, 6000, -20.0)));
    }

    public function test_lines_heard_again_take_the_place_of_what_was_left_out(): void
    {
        $segments = [
            ['start' => 1.0, 'end' => 9.0, 'speaker' => 'A', 'dubbed' => 'هلا والله، حياك يا صديقي.'],
            ['start' => 40.0, 'end' => 52.0, 'speaker' => 'B', 'dubbed' => 'شكراً لكم.'],
        ];
        // The part from 40 to 52 s heard on its own, cut out from 39.5 s.
        $fresh = [
            ['start' => 0.2, 'end' => 4.5, 'speaker' => 'A', 'dubbed' => 'شكراً لكم على هالوقت الحلو اللي قضيناه سوا.'],
            ['start' => 4.5, 'end' => 8.5, 'speaker' => 'A', 'dubbed' => 'وإن شاء الله نشوفكم في الحلقة الجاية.'],
            ['start' => 8.5, 'end' => 12.4, 'speaker' => 'C', 'dubbed' => 'لا تنسون تشتركون بالقناة.'],
            // The start of what comes after the part: not its line.
            ['start' => 12.4, 'end' => 13.0, 'speaker' => 'A', 'dubbed' => 'مع'],
        ];
        $merged = VideoDubber::withHeardAgain($segments, ['from' => 40.0, 'to' => 52.0, 'replace' => 1], $fresh, 39.5);
        $this->assertSame([1.0, 39.7, 44.0, 48.0], array_column($merged, 'start'));
        $this->assertSame([9.0, 44.0, 48.0, 51.9], array_column($merged, 'end'));
        $this->assertSame(['A', 'B', 'B', 'B'], array_column($merged, 'speaker'), 'in the voice of the line they replace');
        $this->assertSame([false, true, true, true], array_map(fn (array $s): bool => ! empty($s['again']), $merged));

        // Heard again, it says no more than before: the line stays.
        $this->assertSame($segments, VideoDubber::withHeardAgain($segments, ['from' => 40.0, 'to' => 52.0, 'replace' => 1],
            [['start' => 0.5, 'end' => 12.0, 'speaker' => 'A', 'dubbed' => 'شكراً.']], 39.5));

        // Speech no line covered (20 to 26 s): its lines go in between, giving way to the line before.
        $merged = VideoDubber::withHeardAgain([['start' => 1.0, 'end' => 20.5, 'speaker' => 'A', 'dubbed' => 'هلا.'], $segments[1]],
            ['from' => 20.0, 'to' => 26.0, 'replace' => null], [['start' => 0.3, 'end' => 6.2, 'speaker' => 'C', 'dubbed' => 'وش صار بعدين؟ قول لي كل شي.']], 19.5);
        $this->assertSame([[1.0, 20.5], [20.5, 25.7], [40.0, 52.0]], array_map(fn (array $s): array => [$s['start'], $s['end']], $merged));
        $this->assertSame('A', $merged[1]['speaker'], 'the nearest line\'s voice');

        // What it heard there was said in the line before (the model's times a little off): not said twice.
        $before = [['start' => 1.0, 'end' => 19.0, 'speaker' => 'A', 'original' => 'Well, and then what happened? Tell me everything.', 'dubbed' => 'طيب، وش صار بعدين؟ قول لي كل شي.'], $segments[1]];
        $this->assertSame($before, VideoDubber::withHeardAgain($before, ['from' => 20.0, 'to' => 26.0, 'replace' => null],
            [['start' => 0.6, 'end' => 4.0, 'speaker' => 'A', 'original' => 'Then what happened? Tell me everything!', 'dubbed' => 'وش صار؟ قول لي كل شي!']], 19.5));
    }

    public function test_the_old_voice_never_comes_back_however_short_the_new_line(): void
    {
        $plan = VideoDubber::plan([
            ['start' => 0.5, 'end' => 3.0, 'voiced_seconds' => 2.0, 'line' => 0],
            // Far shorter than the original (a verse left as it was): still no old voice after it, to where the original ends.
            ['start' => 4.0, 'end' => 16.0, 'voiced_seconds' => 1.0, 'line' => 1],
        ], 20.0);

        $this->assertSame([3.0, 16.0], array_column($plan, 'until'));
        $this->assertSame([0, 1], array_column($plan, 'line'));
    }

    public function test_phrases_are_found_on_the_sound_from_pause_to_pause(): void
    {
        // 20 s, quiet at -60 dB. Speech at -20 dB from 1 to 2.5 s; a pause of 0.3 s; speech to 4 s (with a 0.1 s gap between
        // two words, no pause); 2 s of quiet; then 9.5 s without a pause, a little softer for a moment at 10 s.
        $envelope = [];
        for ($f = 0; $f < 2000; $f++) {
            $t = $f / 100;
            $speech = ($t >= 1 && $t < 2.5) || ($t >= 2.8 && $t < 4 && ! ($t >= 3.4 && $t < 3.5)) || ($t >= 6 && $t < 15.5);
            $envelope[] = $speech ? ($f >= 1000 && $f < 1005 ? -30.0 : -20.0) : -60.0;
        }

        // The long one is cut where it is softest, so no phrase runs over 8 s.
        $this->assertSame([[0.97, 2.53], [2.77, 4.03], [5.97, 10.0], [10.0, 15.53]], VideoDubber::phrasesFromLevels($envelope));
        // No quiet to tell pauses by (music all through): none, and the text model times the lines itself.
        $this->assertSame([], VideoDubber::phrasesFromLevels(array_map(fn (int $f): float => -20.0 - ($f % 7), range(0, 1999))));
    }

    public function test_the_model_answer_for_phrases_is_timed_by_the_phrases(): void
    {
        $phrases = [[0.5, 2.0], [2.3, 4.0], [4.4, 5.0], [6.0, 8.0]];
        $heard = VideoDubber::readAnchored(['background' => 'none', 'speakers' => [['id' => 'A', 'gender' => 'female']], 'phrases' => [
            ['n' => 1, 'speaker' => 'A', 'start' => 9.9, 'original' => 'Hello there,', 'dubbed' => 'هلا والله،'],
            ['n' => 1, 'speaker' => 'A', 'original' => 'twice', 'dubbed' => 'مرتين'],
            // Words heard, nothing dubbed (a quotation the model would not touch).
            ['n' => 2, 'speaker' => 'A', 'original' => 'إن الله وملائكته', 'dubbed' => ''],
            // A laugh: no words, no line (a sound tag alone is not a line: the speech engine takes none).
            ['n' => 3, 'speaker' => 'A', 'original' => '', 'dubbed' => '<laugh>'],
            ['n' => 9, 'speaker' => 'A', 'original' => 'not a phrase', 'dubbed' => 'لا'],
        ]], $phrases);

        $this->assertEquals([['start' => 0.5, 'end' => 2.0, 'phrase' => 0, 'dubbed' => 'هلا والله،']],
            array_map(fn (array $s): array => array_intersect_key($s, array_flip(['start', 'end', 'phrase', 'dubbed'])), $heard['segments']));
        // The quotation and the phrase it gave no answer for are to be heard again.
        $this->assertSame([['phrase' => 1, 'original' => 'إن الله وملائكته', 'speaker' => 'A'], ['phrase' => 3, 'original' => '', 'speaker' => '']], $heard['unsaid']);
        $this->assertSame('female', $heard['speakers'][0]['gender']);
    }

    public function test_the_phrases_of_a_sentence_are_read_in_one_take(): void
    {
        $line = fn (string $speaker, float $start, float $end, string $dubbed, ?int $phrase) => ['speaker' => $speaker, 'start' => $start, 'end' => $end, 'dubbed' => $dubbed]
            + ($phrase !== null ? ['phrase' => $phrase] : []);
        $lines = VideoDubber::lines([
            $line('A', 0.5, 2.0, 'هلا والله،', 0),
            $line('A', 2.3, 4.0, 'حياك الله.', 1),   // the sentence ends: the next is a new take
            $line('A', 4.2, 5.0, 'كيف الحال؟', 2),
            $line('B', 5.1, 6.0, 'بخير،', 3),        // another speaker
            $line('B', 7.5, 8.0, 'والحمد لله.', 4),  // after a pause of 1.5 s
            $line('B', 9.0, 10.0, 'وأنت؟', null),     // timed by the model itself: on its own
        ]);

        $this->assertSame([[0, 1], [2], [3], [4], [5]], array_column($lines, 'segments'));
        $this->assertSame('هلا والله، <short pause> حياك الله.', $lines[0]['text'], 'a pause where the take is cut');
    }

    public function test_a_take_is_cut_at_the_pauses_nearest_to_its_phrases(): void
    {
        // A 4 s take: speech at -20 dB, a pause of 0.4 s at 1.2 s, a gap of 0.1 s between two words at 2 s, a pause of 0.2 s at 2.8 s.
        $levels = [];
        for ($f = 0; $f < 400; $f++) {
            $levels[] = ($f >= 120 && $f < 160) || ($f >= 200 && $f < 210) || ($f >= 280 && $f < 300) ? -70.0 : -20.0;
        }

        $this->assertSame([[1.2, 1.6]], VideoDubber::cuts($levels, [1.2, 2.0]));
        $this->assertSame([[1.2, 1.6], [2.8, 3.0]], VideoDubber::cuts($levels, [1.2, 1.3, 0.8]));
        $this->assertNull(VideoDubber::cuts($levels, [1.0, 1.0, 1.0, 1.0]), 'too few pauses: placed whole');
        $this->assertNull(VideoDubber::cuts($levels, [0.2, 3.8]), 'no pause near where the joint should be');
        $this->assertSame([], VideoDubber::cuts($levels, [2.0]));
    }

    public function test_every_phrase_gets_the_chosen_voice_and_each_part_goes_on_its_own_phrase(): void
    {
        $ffmpeg = AudioFormats::ffmpegPath();
        $video = $this->phraseVideo();
        $quote = 'إن الله وملائكته وأهل السماوات والأرض ليصلون على معلم الناس الخير';
        $answer = fn (array $phrases) => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode(['background' => 'none',
            'speakers' => [['id' => 'A', 'gender' => 'male', 'voice' => 'calm']], 'phrases' => $phrases])]]]]], 'usageMetadata' => []]);
        $sent = [];
        Http::fake(function ($request) use (&$sent, $answer, $quote) {
            $sent[] = $request->data();

            // The saying is left without a line, the first time and when heard again on its own.
            return count($sent) === 1
                ? $answer([['n' => 1, 'speaker' => 'A', 'original' => 'Hi there,', 'dubbed' => 'هلا والله،'], ['n' => 2, 'speaker' => 'A', 'original' => 'welcome.', 'dubbed' => 'حياك الله.'],
                    ['n' => 3, 'speaker' => 'A', 'original' => $quote, 'dubbed' => '']])
                : $answer([['n' => 1, 'speaker' => 'A', 'original' => $quote, 'dubbed' => '']]);
        });
        $keys = \Mockery::mock(GeminiKeyManager::class);
        $keys->shouldReceive('keysInOrder')->andReturn([['key' => 'k1', 'name' => 'GEMINI_TTS_API_KEY', 'index' => 1]]);
        $keys->shouldReceive('recordUsage');
        // The first two phrases in one take, with the asked-for pause between them; the saying's first take stops short.
        $takes = ["0.4*sin(2*PI*800*t)*(lt(t,1)+between(t,1.4,2.3)):d=2.3", '0.4*sin(2*PI*800*t):d=0.3', '0.4*sin(2*PI*800*t):d=2.4'];
        $texts = [];
        $tts = \Mockery::mock(GeminiTtsProvider::class);
        $tts->shouldReceive('synthesize')->times(3)->andReturnUsing(function (string $text, string $language, array $config) use ($ffmpeg, &$takes, &$texts) {
            $texts[] = [$text, $config['voice_name']];
            $path = 'audio/take-'.count($takes).'.wav';
            @mkdir(dirname(Storage::disk('public')->path($path)), 0755, true);
            [$expression, $duration] = explode(':d=', array_shift($takes));
            (new Process([$ffmpeg, '-v', 'error', '-y', '-f', 'lavfi', '-i', "aevalsrc='{$expression}':s=24000:d={$duration}", Storage::disk('public')->path($path)]))->mustRun();

            return ['audio_path' => $path, 'duration_seconds' => 2];
        });

        $r = (new VideoDubber($keys, $tts))->dub($video, ['language' => 'ar', 'dialect' => 'ar-SA', 'voice' => 'Kore', 'preview' => false, 'out_dir' => $this->dir, 'name' => 'dubbed']);

        $this->assertSame(['method' => 'loudness', 'phrases' => 3], array_intersect_key($r['speech_map'], array_flip(['method', 'phrases'])));
        $this->assertStringContainsString('3 numbered phrases', $sent[0]['contents'][0]['parts'][1]['text']);
        $this->assertStringContainsString('sayings of the Prophet', $sent[0]['contents'][0]['parts'][1]['text']);
        // Heard again on its own, in its own time.
        $this->assertCount(2, $sent);
        $this->assertStringContainsString('short part cut out', $sent[1]['contents'][0]['parts'][1]['text']);
        $this->assertStringContainsString('1. 0.5', $sent[1]['contents'][0]['parts'][1]['text']);
        $this->assertSame(0, $r['heard_again'][0]['filled']);
        // Still without a line: read as it was said, in the chosen voice. Never the old voice.
        $this->assertSame(0, $r['phrases_left_quiet']);
        $this->assertSame(['هلا والله، <short pause> حياك الله.', $quote, $quote], array_column($texts, 0));
        $this->assertSame(['Kore'], array_values(array_unique(array_column($texts, 1))));
        $this->assertSame([false, false, true], array_column($r['lines'], 'as_said'));
        // The take of the first two is cut at its pause, each part on its own phrase.
        $this->assertSame([0, 0, 1], array_column($r['lines'], 'take'));
        $this->assertSame([false, false, false], array_column($r['lines'], 'placed_whole'));
        $this->assertEqualsWithDelta(2.8, $r['lines'][1]['start'], 0.1);
        $this->assertEqualsWithDelta(0.9, $r['lines'][1]['voiced_seconds'], 0.1);
        $this->assertSame(['requests' => 3, 'retaken' => 1, 'takes' => 2, 'placed_parts' => 3],
            array_intersect_key($r['voicing'], array_flip(['requests', 'retaken', 'takes', 'placed_parts'])));
        // The second part plays on the second phrase, the pause between them stays quiet, and the old voice is out under every phrase.
        $this->assertGreaterThan(-25, $this->level($r['output'], 2.9, 0.7, 'bandpass=f=800:w=100,'));
        $this->assertLessThan(-45, $this->level($r['output'], 2.55, 0.15, 'bandpass=f=800:w=100,'));
        foreach ([[0.6, 1.8], [2.9, 1.0], [5.1, 2.3]] as [$from, $length]) {
            $this->assertLessThan(-45, $this->level($r['output'], $from, $length, 'bandpass=f=500:w=60,'), 'no old voice at '.$from.' s');
        }
        $this->assertSame([], Storage::disk('public')->allFiles('audio'), 'every take is deleted');
    }

    public function test_an_answer_the_model_will_not_give_is_asked_for_again_without_copying_quotations(): void
    {
        $video = $this->phraseVideo();
        $sent = [];
        // The model will not copy a well-known saying word for word: no answer at all (RECITATION). Asked again without, it answers.
        Http::fake(function ($request) use (&$sent) {
            $sent[] = $request->data();

            return count($sent) === 1
                ? Http::response(['candidates' => [['finishReason' => 'RECITATION', 'index' => 0]], 'usageMetadata' => ['promptTokenCount' => 300]])
                : Http::response(['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => json_encode(['background' => 'none', 'phrases' => [
                    ['n' => 1, 'speaker' => 'A', 'original' => 'Hi there,', 'dubbed' => 'هلا والله،'], ['n' => 2, 'speaker' => 'A', 'original' => 'welcome.', 'dubbed' => 'حياك الله.'],
                    ['n' => 3, 'speaker' => 'A', 'original' => '…', 'dubbed' => 'ربنا والملايكة وأهل السما والأرض بيصلّوا على اللي بيعلّم الناس الخير'],
                ]])]]]]], 'usageMetadata' => ['promptTokenCount' => 300]]);
        });

        $r = $this->dubWith($video, 2);

        $this->assertCount(2, $sent);
        // Quotations are never asked for word for word; asked again, not even their first words.
        $this->assertStringContainsString('never copy them out: for a quotation write only its first three words', $sent[0]['contents'][0]['parts'][1]['text']);
        $this->assertStringContainsString('for a quotation write only "…" as its original', $sent[1]['contents'][0]['parts'][1]['text']);
        $this->assertSame(['RECITATION', 'STOP'], $r['transcribe']['finish']);
        $this->assertSame(600, $r['transcribe']['input_tokens'], 'both requests counted');
        $this->assertSame(3, $r['segments']);
    }

    public function test_an_empty_answer_is_never_taken_for_a_video_nobody_speaks_in(): void
    {
        $video = $this->phraseVideo();
        $sent = 0;
        Http::fake(function () use (&$sent) {
            $sent++;

            return Http::response(['candidates' => [['finishReason' => 'RECITATION', 'index' => 0]]]);
        });
        $dubber = null;

        try {
            $this->dubWith($video, 0, $dubber);
            $this->fail('The dub should have failed.');
        } catch (\RuntimeException $e) {
            // The member is told something went wrong on our side, not that nobody speaks.
            $this->assertStringContainsString('could not be written out', $e->getMessage());
            $this->assertStringContainsString('RECITATION', $e->getMessage());
            $this->assertStringNotContainsString('No speech', $e->getMessage());
        }
        // Asked for the phrases twice, then to time the lines itself twice.
        $this->assertSame(4, $sent);
        // What it had done up to there, for the admin.
        $this->assertSame(3, $dubber->lastReport['speech_map']['phrases']);
        $this->assertStringContainsString('RECITATION', $dubber->lastReport['error']);
    }

    public function test_when_the_phrases_get_no_answer_the_model_times_the_lines_itself(): void
    {
        $video = $this->phraseVideo();
        $sent = [];
        Http::fake(function ($request) use (&$sent) {
            $sent[] = $request->data();

            return count($sent) <= 2
                ? Http::response(['candidates' => [['finishReason' => 'RECITATION', 'index' => 0]]])
                : Http::response(['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => json_encode(['background' => 'none', 'segments' => [
                    ['start' => 0.5, 'end' => 4.0, 'speaker' => 'A', 'original' => 'Hi there, welcome.', 'dubbed' => 'هلا والله، حياك الله.'],
                    ['start' => 5.0, 'end' => 7.5, 'speaker' => 'A', 'original' => 'How are you today?', 'dubbed' => 'كيف حالك اليوم؟'],
                ]])]]]]]]);
        });

        $r = $this->dubWith($video, 2);

        $this->assertCount(3, $sent);
        $this->assertStringContainsString('Split what is said into segments', $sent[2]['contents'][0]['parts'][1]['text']);
        $this->assertSame(['RECITATION', 'RECITATION', 'STOP'], $r['transcribe']['finish']);
        $this->assertFalse($r['speech_map']['used']);
        $this->assertSame(2, $r['segments']);
    }

    public function test_a_line_the_speech_engine_fails_on_stays_quiet_rather_than_failing_the_dub(): void
    {
        $video = $this->phraseVideo();
        Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode(['background' => 'none', 'phrases' => [
            ['n' => 1, 'speaker' => 'A', 'original' => 'Hi there.', 'dubbed' => 'هلا والله.'],
            ['n' => 2, 'speaker' => 'A', 'original' => 'Welcome.', 'dubbed' => 'حياك الله.'],
            ['n' => 3, 'speaker' => 'A', 'original' => 'How are you?', 'dubbed' => 'كيف حالك؟'],
        ]])]]]]]])]);

        // The second line fails twice; the others are voiced.
        $r = $this->dubWith($video, 4, $dubber, fail: fn (string $text): bool => $text === 'حياك الله.');

        $this->assertSame(['quiet_lines' => 1, 'quiet_because' => 'The speech engine failed.'], array_intersect_key($r['voicing'], array_flip(['quiet_lines', 'quiet_because'])));
        $this->assertSame([false, true, false], array_column($r['lines'], 'quiet'));
        $this->assertNull($r['lines'][1]['tempo']);
        // No old voice under the quiet line either.
        $this->assertLessThan(-45, $this->level($r['output'], 2.9, 1.0, 'bandpass=f=500:w=60,'));

        // Every line failing: one is let go quiet, the next fails the dub with the speech engine's own reason.
        $this->expectExceptionMessage('The speech engine failed.');
        $this->dubWith($video, 4, $dubber, fail: fn (string $text): bool => true);
    }

    public function test_when_the_phrases_give_no_line_the_model_times_the_lines_itself(): void
    {
        $video = $this->phraseVideo();
        $sent = [];
        // It heard no words in any phrase (it would not keep to them); timing the lines itself, it does.
        Http::fake(function ($request) use (&$sent) {
            $sent[] = $request->data();
            $data = count($sent) === 1
                ? ['background' => 'none', 'phrases' => [['n' => 1, 'original' => '', 'dubbed' => ''], ['n' => 2, 'original' => '', 'dubbed' => ''], ['n' => 3, 'original' => '', 'dubbed' => '']]]
                : ['background' => 'none', 'segments' => [['start' => 0.5, 'end' => 4.0, 'speaker' => 'A', 'original' => 'Hi there, welcome.', 'dubbed' => 'هلا والله، حياك الله.'],
                    ['start' => 5.0, 'end' => 7.5, 'speaker' => 'A', 'original' => 'How are you today?', 'dubbed' => 'كيف حالك اليوم؟']]];

            return Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode($data)]]]]], 'usageMetadata' => []]);
        });

        $r = $this->dubWith($video, 2);

        $this->assertCount(2, $sent);
        $this->assertStringContainsString('Split what is said into segments', $sent[1]['contents'][0]['parts'][1]['text']);
        $this->assertFalse($r['speech_map']['used']);
        $this->assertSame(2, $r['segments']);
    }

    /** Someone speaks from 0.5 to 2.5 s, pauses, speaks to 4 s, then from 5 to 7.5 s: three phrases. */
    private function phraseVideo(): string
    {
        $video = $this->dir.'/phrases.mp4';
        (new Process([AudioFormats::ffmpegPath(), '-v', 'error', '-y', '-f', 'lavfi', '-i', 'testsrc2=size=320x240:rate=15',
            '-f', 'lavfi', '-i', "aevalsrc='0.5*sin(2*PI*500*t)*(between(t,0.5,2.5)+between(t,2.8,4)+between(t,5,7.5))':s=16000:d=8",
            '-t', '8', '-c:v', 'libx264', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-shortest', $video]))->mustRun();

        return $video;
    }

    /**
     * Dubs $video with the text model as faked by the caller and a speech engine that reads each take as a tone ($takes requests
     * expected; those $fail says fail).
     *
     * @param  (callable(string): bool)|null  $fail
     * @return array<string, mixed>
     */
    private function dubWith(string $video, int $takes, ?VideoDubber &$dubber = null, ?callable $fail = null): array
    {
        $ffmpeg = AudioFormats::ffmpegPath();
        $keys = \Mockery::mock(GeminiKeyManager::class);
        $keys->shouldReceive('keysInOrder')->andReturn([['key' => 'k1', 'name' => 'GEMINI_TTS_API_KEY', 'index' => 1]]);
        $keys->shouldReceive('recordUsage');
        $tts = \Mockery::mock(GeminiTtsProvider::class);
        $tts->shouldReceive('synthesize')->times($takes)->andReturnUsing(function (string $text, string $language, array $config) use ($ffmpeg, $fail) {
            if ($fail !== null && $fail($text)) {
                throw new \RuntimeException('The speech engine failed.');
            }
            $path = 'audio/take-'.bin2hex(random_bytes(3)).'.wav';
            @mkdir(dirname(Storage::disk('public')->path($path)), 0755, true);
            // As long as its words need: never taken again.
            $seconds = round(0.9 * VideoDubber::needs($text, $config), 2);
            (new Process([$ffmpeg, '-v', 'error', '-y', '-f', 'lavfi', '-i', 'sine=frequency=800:sample_rate=24000:duration='.$seconds, Storage::disk('public')->path($path)]))->mustRun();

            return ['audio_path' => $path, 'duration_seconds' => 2];
        });
        $dubber = new VideoDubber($keys, $tts);

        return $dubber->dub($video, ['language' => 'ar', 'dialect' => 'ar-SA', 'voice' => 'Kore', 'preview' => false, 'out_dir' => $this->dir, 'name' => 'dubbed']);
    }

    public function test_tokens_and_characters_turn_into_money(): void
    {
        $cost = DubMeasureCommand::cost(
            ['transcribe' => ['input_tokens' => 2000, 'audio_tokens' => 1900, 'output_tokens' => 300, 'thinking_tokens' => 200], 'voicing' => ['characters' => 800]],
            ['in' => 0.5, 'audio' => 1.0, 'out' => 3.0, 'tts' => 20.0],
        );

        // 100 x 0.5 + 1900 x 1 + 500 x 3 = 3450 per million; 800 x 20 = 16000 per million.
        $this->assertEqualsWithDelta(0.00345, $cost['model'], 1e-9);
        $this->assertEqualsWithDelta(0.016, $cost['voice'], 1e-9);
    }

    public function test_the_music_plays_on_while_the_old_voice_gives_way_to_the_new_one(): void
    {
        $ffmpeg = AudioFormats::ffmpegPath();
        // Music on the sides of a stereo mix, a voice in the middle, all the way through.
        $video = $this->dir.'/talk.mp4';
        (new Process([$ffmpeg, '-v', 'error', '-y', '-f', 'lavfi', '-i', 'testsrc2=size=320x240:rate=15',
            '-f', 'lavfi', '-i', 'aevalsrc=0.2*sin(2*PI*220*t)+0.25*sin(2*PI*700*t)*(0.6+0.4*sin(2*PI*3*t))|0.2*sin(2*PI*330*t)+0.25*sin(2*PI*700*t)*(0.6+0.4*sin(2*PI*3*t)):s=48000:d=6',
            '-t', '6', '-c:v', 'libx264', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-b:a', '192k', '-shortest', $video]))->mustRun();

        $answer = fn (string $background) => ['candidates' => [['content' => ['parts' => [['text' => json_encode(['background' => $background,
            'segments' => [['start' => 1.0, 'end' => 3.0, 'speaker' => 'A', 'original' => 'Hi.', 'dubbed' => 'هلا.']]])]]]]], 'usageMetadata' => []];
        Http::fake(['*' => Http::sequence()->push($answer('music'))->push($answer('none'))->push($answer('music'))]);

        // The model's "no music" does not drop the sound; only the member's choice does.
        foreach (['music' => true, 'none' => true, 'replace' => false] as $background => $kept) {
            $out = $this->dubSilently($video, $background, 'mix-'.$background, $background === 'replace' ? ['background' => 'replace'] : []);
            $this->assertSame($kept, $out['report']['kept_background']);
            $before = $this->level($video, 4.0, 1.5);
            $after = $this->level($out['file'], 4.0, 1.5);
            if ($kept) {
                // Between lines the original is as it was…
                $this->assertEqualsWithDelta($before, $after, 1.5, 'the music between lines');
                // …and under the line its middle (the voice, at 700 Hz) is carved out while the sides (the music) stay.
                $this->assertLessThan($this->level($video, 1.4, 1.2, 'bandpass=f=700:w=60,') - 15, $this->level($out['file'], 1.4, 1.2, 'bandpass=f=700:w=60,'), 'the old voice under the new line');
                $this->assertEqualsWithDelta($this->level($video, 1.4, 1.2, 'bandpass=f=330:w=40,'), $this->level($out['file'], 1.4, 1.2, 'bandpass=f=330:w=40,'), 4, 'the music under the new line');
            } else {
                $this->assertLessThan(-60, $after, 'the member chose to replace all the sound: nothing of it is left');
            }
        }
    }

    public function test_with_the_separation_model_the_old_voices_are_taken_out_of_the_whole_track(): void
    {
        $ffmpeg = AudioFormats::ffmpegPath();
        $video = $this->dir.'/talk.mp4';
        (new Process([$ffmpeg, '-v', 'error', '-y', '-f', 'lavfi', '-i', 'testsrc2=size=320x240:rate=15', '-f', 'lavfi', '-i', 'sine=frequency=700:sample_rate=48000:d=6',
            '-t', '6', '-c:v', 'libx264', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-shortest', $video]))->mustRun();
        $answer = ['candidates' => [['content' => ['parts' => [['text' => json_encode(['background' => 'music',
            'segments' => [['start' => 1.0, 'end' => 3.0, 'speaker' => 'A', 'original' => 'Hi.', 'dubbed' => 'هلا.']]])]]]]], 'usageMetadata' => []];
        Http::fake(['*' => Http::response($answer)]);

        // A stand-in for the model that hears all of this sound as voices: they play between the new lines, never under one.
        $this->fakeSeparator('head -c "$(stat -c %s "$in")" /dev/zero > "$rest"; cp "$in" "$voices"; echo "progress 1 1"');
        $out = $this->dubSilently($video, 'music', 'separated');
        $this->assertSame('model', $out['report']['separation']['method']);
        $this->assertEqualsWithDelta($this->level($video, 4.0, 1.5), $this->level($out['file'], 4.0, 1.5), 1.5, 'between lines, the original voices (crowds, singing, recitation) as they were');
        $this->assertLessThan(-60, $this->level($out['file'], 1.4, 1.2), 'under the new line no old voice is heard');

        // One that hears it all as music: it plays all through, under the new line too.
        $this->fakeSeparator('cp "$in" "$rest"; head -c "$(stat -c %s "$in")" /dev/zero > "$voices"');
        $out = $this->dubSilently($video, 'music', 'music-only');
        $this->assertEqualsWithDelta($this->level($video, 1.4, 1.2), $this->level($out['file'], 1.4, 1.2), 1.5, 'the music under the new line, as it was');
        $this->assertEqualsWithDelta($this->level($video, 4.0, 1.5), $this->level($out['file'], 4.0, 1.5), 1.5, 'and between lines');

        // When it fails, the filters take over: the original between lines, nothing of a mono voice under them.
        $this->fakeSeparator('exit 1');
        $out = $this->dubSilently($video, 'music', 'filtered');
        $this->assertSame('filters', $out['report']['separation']['method']);
        $this->assertEqualsWithDelta($this->level($video, 4.0, 1.5), $this->level($out['file'], 4.0, 1.5), 1.5, 'between lines, the original');
        $this->assertLessThan(-60, $this->level($out['file'], 1.4, 1.2), 'under the new line no old voice is heard');

        // With the speech isolation: what it heard as voices further back plays on under the new line.
        $this->fakeSeparator('head -c "$(stat -c %s "$in")" /dev/zero > "$rest"; cp "$in" "$voices"; cp "$in" "$further"', isolator: true);
        $out = $this->dubSilently($video, 'music', 'further');
        $this->assertTrue($out['report']['separation']['voices_further_back']);
        $this->assertEqualsWithDelta($this->level($video, 1.4, 1.2), $this->level($out['file'], 1.4, 1.2), 1.5, 'the voices further back, under the new line');
        $this->assertEqualsWithDelta($this->level($video, 4.0, 1.5), $this->level($out['file'], 4.0, 1.5), 1.5, 'and the original between lines');
        // Isolation that failed (no file): the dub goes on as without it.
        $this->fakeSeparator('head -c "$(stat -c %s "$in")" /dev/zero > "$rest"; cp "$in" "$voices"', isolator: true);
        $out = $this->dubSilently($video, 'music', 'no-further');
        $this->assertFalse($out['report']['separation']['voices_further_back']);
        $this->assertLessThan(-60, $this->level($out['file'], 1.4, 1.2));

        // Too little memory free on the server just now: not started, the filters instead.
        $this->fakeSeparator('cp "$in" "$rest"; cp "$in" "$voices"');
        config(['listen.dubbing.separator.min_free_mb' => 100_000_000]);
        $this->assertSame('filters', $this->dubSilently($video, 'music', 'busy')['report']['separation']['method']);
        config(['listen.dubbing.separator.min_free_mb' => 0]);

        // Switched off by the admin: not used.
        \App\Models\SiteSetting::set('video_dubbing_separation', '0', 'video_dubbing');
        $this->fakeSeparator('cp "$in" "$rest"; cp "$in" "$voices"');
        $this->assertSame('filters', $this->dubSilently($video, 'music', 'off')['report']['separation']['method']);
    }

    public function test_the_installer_picks_builds_this_server_can_run(): void
    {
        $this->assertTrue(\App\Services\Dubbing\VoiceSeparator::fitsPlatform('onnxruntime-1.31.0-cp313-cp313-manylinux_2_28_x86_64.whl', 'x86_64', [2, 31]));
        $this->assertFalse(\App\Services\Dubbing\VoiceSeparator::fitsPlatform('onnxruntime-1.31.0-cp313-cp313-manylinux_2_28_x86_64.whl', 'x86_64', [2, 27]), 'a newer C library than the server has');
        $this->assertTrue(\App\Services\Dubbing\VoiceSeparator::fitsPlatform('numpy-2.0.2-cp39-cp39-manylinux_2_17_x86_64.manylinux2014_x86_64.whl', 'x86_64', [2, 28]));
        $this->assertFalse(\App\Services\Dubbing\VoiceSeparator::fitsPlatform('numpy-2.0.2-cp39-cp39-manylinux_2_17_aarch64.manylinux2014_aarch64.whl', 'x86_64', [2, 28]), 'another processor');
        $this->assertFalse(\App\Services\Dubbing\VoiceSeparator::fitsPlatform('numpy-2.0.2-cp39-cp39-musllinux_1_1_x86_64.whl', 'x86_64', [2, 28]));
    }

    /**
     * A separator set up with a stand-in for Python that runs $body with $in,
     * $rest, $voices and (with $isolator, the speech isolation set up too)
     * $further set from its arguments.
     */
    private function fakeSeparator(string $body, bool $isolator = false): void
    {
        $dir = $this->dir.'/separator';
        @mkdir($dir.'/lib', 0755, true);
        file_put_contents($dir.'/model.onnx', 'model');
        file_put_contents($dir.'/ready.json', '{}');
        @unlink($dir.'/deep-filter');
        if ($isolator) {
            file_put_contents($dir.'/deep-filter', "#!/bin/sh\n");
            chmod($dir.'/deep-filter', 0755);
        }
        $python = $this->dir.'/python';
        file_put_contents($python, <<<'SH'
            #!/bin/sh
            paths=""
            skip=0
            for a in "$@"; do
              if [ $skip = 1 ]; then skip=0; continue; fi
              case "$a" in
                -s) ;;
                --lib|--model|--threads|--isolator|--ffmpeg|--mask-strength) skip=1 ;;
                *) paths="$paths $a" ;;
              esac
            done
            set -- $paths
            in=$2; rest=$3; voices=$4; further=$5

            SH.$body."\n");
        chmod($python, 0755);
        config(['listen.dubbing.separator.python' => $python, 'listen.dubbing.separator.dir' => $dir]);
    }

    public function test_the_model_answer_is_made_safe(): void
    {
        $heard = VideoDubber::readHeard(['background' => 'Loud', 'speakers' => [['id' => 'A', 'gender' => 'FEMALE']], 'segments' => [
            ['start' => 2, 'end' => 1, 'speaker' => 'A', 'emotion' => 'Excited', 'delivery' => str_repeat('x', 400), 'original' => 'b', 'dubbed' => 'ب'],
            ['start' => 0, 'speaker' => 'C', 'emotion' => 'made-up', 'dubbed' => 'أ'],
            ['start' => 'soon', 'dubbed' => 'ج'],
            ['start' => 3, 'dubbed' => ' '],
        ]]);

        $this->assertSame('ambient', $heard['background'], 'unsure: keep the sound');
        // Nothing to say in it (a pause, "…", a sound alone): not a line.
        $this->assertSame([], VideoDubber::readHeard(['segments' => [['start' => 1, 'dubbed' => '…'], ['start' => 2, 'dubbed' => '<short pause>'], ['start' => 3, 'dubbed' => ['a list']]]])['segments']);
        $this->assertTrue(VideoDubber::spoken('هلا <laugh>'));
        $this->assertFalse(VideoDubber::spoken('<laugh> [sighs] …'));
        $this->assertSame([['id' => 'A', 'gender' => 'female', 'voice' => ''], ['id' => 'C', 'gender' => 'male', 'voice' => '']], $heard['speakers']);
        $this->assertSame(['أ', 'ب'], array_column($heard['segments'], 'dubbed'));
        $this->assertNull($heard['segments'][0]['emotion']);
        $this->assertSame('excited', $heard['segments'][1]['emotion']);
        $this->assertSame(2.0, $heard['segments'][1]['end'], 'never ends before it starts');
        $this->assertSame(160, mb_strlen($heard['segments'][1]['delivery']));
    }

    public function test_each_speaker_gets_a_voice_and_the_member_can_set_the_mood_for_all(): void
    {
        $segments = [['speaker' => 'A', 'start' => 0, 'end' => 2], ['speaker' => 'B', 'start' => 2, 'end' => 9], ['speaker' => 'C', 'start' => 9, 'end' => 10]];
        $voices = VideoDubber::assignVoices([['id' => 'A', 'gender' => 'male'], ['id' => 'B', 'gender' => 'female'], ['id' => 'C', 'gender' => 'female']],
            $segments, 'Charon', ['male' => ['Puck'], 'female' => ['Kore']]);
        // B talks the most: the member's voice. A takes the next man's voice; C the next woman's.
        $this->assertSame(['B' => 'Charon', 'A' => 'Puck', 'C' => 'Kore'], $voices);
        // One of the lead's gender who says almost nothing (most often the lead split in two) keeps the lead's voice.
        $this->assertSame(['A' => 'Charon', 'B' => 'Charon'], VideoDubber::assignVoices([['id' => 'A', 'gender' => 'male'], ['id' => 'B', 'gender' => 'male']],
            [['speaker' => 'A', 'start' => 0, 'end' => 30], ['speaker' => 'B', 'start' => 30, 'end' => 31]], 'Charon', ['male' => ['Puck']]));

        $line = ['emotion' => 'sad', 'delivery' => 'quiet, close to tears'];
        // Every line of a speaker: the same voice description; only the feeling changes.
        $this->assertSame(['voice_name' => 'Puck', 'dialect' => 'ar-EG', 'emotion' => 'sad', 'style_prompt' => 'One steady voice for this person in every line: warm older man, slow and calm. Feeling of this line: sad.'],
            VideoDubber::voiceConfig($line, 'Puck', ['dialect' => 'ar-EG'], 'warm older man, slow and calm.'));
        $this->assertSame(['voice_name' => 'Puck', 'dialect' => 'ar-EG', 'emotion' => 'sad', 'style_prompt' => 'One steady voice for this person in every line. Feeling of this line: sad.'],
            VideoDubber::voiceConfig($line, 'Puck', ['dialect' => 'ar-EG']));
        $this->assertSame(['voice_name' => 'Puck', 'dialect' => 'ar-EG', 'performance_style' => 'promo', 'emotion' => 'sad'],
            VideoDubber::voiceConfig($line, 'Puck', ['dialect' => 'ar-EG', 'style' => 'promo']));
        $this->assertSame(['voice_name' => 'Puck', 'dialect' => 'ar-EG', 'emotion' => 'joyful'],
            VideoDubber::voiceConfig($line, 'Puck', ['dialect' => 'ar-EG', 'emotion' => 'joyful']));
    }

    /**
     * Dubs $video with one silent line (the model's answer faked by the caller), so what is heard is what is left of the original.
     *
     * @return array{report: array<string, mixed>, file: string}
     */
    private function dubSilently(string $video, string $background, string $name, array $options = []): array
    {
        $ffmpeg = AudioFormats::ffmpegPath();
        $keys = \Mockery::mock(GeminiKeyManager::class);
        $keys->shouldReceive('keysInOrder')->andReturn([['key' => 'k1', 'name' => 'GEMINI_TTS_API_KEY', 'index' => 1]]);
        $keys->shouldReceive('recordUsage');
        $tts = \Mockery::mock(GeminiTtsProvider::class);
        $tts->shouldReceive('synthesize')->andReturnUsing(function () use ($ffmpeg) {
            $path = 'audio/silent-'.bin2hex(random_bytes(3)).'.mp3';
            @mkdir(dirname(Storage::disk('public')->path($path)), 0755, true);
            (new Process([$ffmpeg, '-v', 'error', '-y', '-f', 'lavfi', '-i', 'anullsrc=r=48000:cl=mono', '-t', '2', '-c:a', 'libmp3lame', Storage::disk('public')->path($path)]))->mustRun();

            return ['audio_path' => $path, 'duration_seconds' => 2];
        });
        $report = (new VideoDubber($keys, $tts))->dub($video, $options + ['language' => 'ar', 'dialect' => 'ar-SA', 'voice' => 'Puck', 'preview' => false, 'out_dir' => $this->dir, 'name' => $name]);

        return ['report' => $report, 'file' => $report['output']];
    }

    /** The sound's level, in dB, over $length seconds from $from (after $filter, when given). */
    private function level(string $file, float $from, float $length, string $filter = ''): float
    {
        $process = new Process([AudioFormats::ffmpegPath(), '-hide_banner', '-ss', (string) $from, '-t', (string) $length, '-i', $file,
            '-af', $filter.'astats=measure_overall=RMS_level:measure_perchannel=none', '-f', 'null', '-']);
        $process->mustRun();
        preg_match_all('/RMS level dB: (-?[\d.]+|-inf)/', $process->getErrorOutput(), $m);
        $value = end($m[1]);

        return $value === '-inf' || $value === false ? -120.0 : (float) $value;
    }
}
