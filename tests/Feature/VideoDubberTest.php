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

    public function test_the_original_comes_back_rather_than_a_long_silence(): void
    {
        $plan = VideoDubber::plan([
            ['start' => 0.5, 'end' => 3.0, 'voiced_seconds' => 2.0],   // a little short: the old voice stays out until the original ends
            ['start' => 4.0, 'end' => 16.0, 'voiced_seconds' => 1.0],  // 11 s short (a verse left as it was): the original comes back after the line
        ], 20.0);

        $this->assertSame([3.0, 5.087], array_column($plan, 'until'));
        $this->assertSame([0.0, 10.91], array_column($plan, 'original_left'));
    }

    public function test_left_out_speech_is_heard_again_and_a_take_cut_short_is_taken_again(): void
    {
        $ffmpeg = AudioFormats::ffmpegPath();
        // Someone speaks from 0.5 to 3 s and from 4 to 7.5 s; the model writes out only the first part.
        $video = $this->dir.'/two-parts.mp4';
        (new Process([$ffmpeg, '-v', 'error', '-y', '-f', 'lavfi', '-i', 'testsrc2=size=320x240:rate=15',
            '-f', 'lavfi', '-i', "aevalsrc='0.5*sin(2*PI*500*t)*(between(t,0.5,3)+between(t,4,7.5))':s=16000:d=8",
            '-t', '8', '-c:v', 'libx264', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-shortest', $video]))->mustRun();
        $answer = fn (array $segments) => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode(['background' => 'none',
            'speakers' => [['id' => 'A', 'gender' => 'male', 'voice' => 'calm']], 'segments' => $segments])]]]]], 'usageMetadata' => []]);
        $sent = [];
        Http::fake(function ($request) use (&$sent, $answer) {
            $sent[] = $request->data();

            return count($sent) === 1
                ? $answer([['start' => 0.5, 'end' => 3.0, 'speaker' => 'A', 'original' => 'Hi there.', 'dubbed' => 'هلا والله.']])
                // The part from 4 s heard again, cut out from 3.5 s: its times are from there.
                : $answer([['start' => 0.5, 'end' => 4.0, 'speaker' => 'B', 'original' => 'How are you today, my dear friend?', 'dubbed' => 'كيف حالك اليوم يا صديقي العزيز؟']]);
        });
        $keys = \Mockery::mock(GeminiKeyManager::class);
        $keys->shouldReceive('keysInOrder')->andReturn([['key' => 'k1', 'name' => 'GEMINI_TTS_API_KEY', 'index' => 1]]);
        $keys->shouldReceive('recordUsage');
        // The first take of the second line stops after half a second: it is taken again.
        $takes = [2.3, 0.5, 3.2];
        $tts = \Mockery::mock(GeminiTtsProvider::class);
        $tts->shouldReceive('synthesize')->times(3)->andReturnUsing(function () use ($ffmpeg, &$takes) {
            $path = 'audio/take-'.count($takes).'.mp3';
            @mkdir(dirname(Storage::disk('public')->path($path)), 0755, true);
            (new Process([$ffmpeg, '-v', 'error', '-y', '-f', 'lavfi', '-i', 'sine=frequency=400:duration='.array_shift($takes), '-c:a', 'libmp3lame', Storage::disk('public')->path($path)]))->mustRun();

            return ['audio_path' => $path, 'duration_seconds' => 2];
        });

        $r = (new VideoDubber($keys, $tts))->dub($video, ['language' => 'ar', 'dialect' => 'ar-SA', 'voice' => 'Puck', 'preview' => false, 'out_dir' => $this->dir, 'name' => 'again']);

        $this->assertCount(2, $sent);
        $this->assertStringContainsString('cut out from 3.5 to 8.0 seconds', $sent[1]['contents'][0]['parts'][1]['text']);
        $this->assertSame(2, $r['segments']);
        $this->assertCount(1, $r['heard_again']);
        $this->assertEqualsWithDelta(4.0, $r['heard_again'][0]['from'], 0.1);
        $this->assertEqualsWithDelta(7.5, $r['heard_again'][0]['to'], 0.1);
        $this->assertSame(['line_replaced' => false, 'lines_heard' => 1, 'used' => true, 'lines_added' => 1],
            array_intersect_key($r['heard_again'][0], array_flip(['line_replaced', 'lines_heard', 'used', 'lines_added'])));
        $this->assertSame([false, true], array_column($r['lines'], 'heard_again'));
        $this->assertEqualsWithDelta(4.0, $r['lines'][1]['start'], 0.15);
        $this->assertSame('A', $r['lines'][1]['speaker'], 'read as the line before it');
        $this->assertSame(1, $r['voicing']['retaken']);
        $this->assertEqualsWithDelta(3.2, $r['lines'][1]['voiced_seconds'], 0.15, 'the closer take is kept');
        $this->assertSame([], Storage::disk('public')->allFiles('audio'), 'every take is deleted');
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
