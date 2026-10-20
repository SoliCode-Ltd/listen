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
        $r = $dubber->dub($video, ['language' => 'ar', 'dialect' => 'ar-SA', 'voice' => 'Puck', 'voices' => ['male' => ['Puck', 'Orus'], 'female' => ['Kore', 'Leda']],
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
        // In order of time; the first line (3.4 s in a 2.8 s room) is sped up.
        $this->assertSame('هلا والله، حياك.', $r['lines'][0]['dubbed']);
        $this->assertGreaterThan(1.0, $r['lines'][0]['tempo']);
        $this->assertSame(1.0, $r['lines'][1]['tempo']);
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

    public function test_lines_are_placed_at_their_time_and_sped_up_only_as_far_as_allowed(): void
    {
        $plan = VideoDubber::plan([
            ['start' => 0.5, 'end' => 2, 'voiced_seconds' => 1.5],
            ['start' => 2.0, 'end' => 4, 'voiced_seconds' => 3.0],   // 2 s of room: x1.3 at most, 0.31 s over
            ['start' => 4.0, 'end' => 9, 'voiced_seconds' => 2.0],
        ], 10.0);

        $this->assertSame([500, 2000, 4000], array_column($plan, 'delay_ms'));
        $this->assertSame([1.0, 1.3, 1.0], array_column($plan, 'tempo'));
        $this->assertEqualsWithDelta(0.31, $plan[1]['overrun'], 0.01);
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
        $this->assertEqualsWithDelta($this->level($video, 1.4, 1.2), $this->level($out['file'], 1.4, 1.2), 1.5, 'the music under the new line');
        $this->assertEqualsWithDelta($this->level($video, 4.0, 1.5), $this->level($out['file'], 4.0, 1.5), 1.5, 'and between lines');

        // When it fails, the filters take over: the original between lines, nothing of a mono voice under them.
        $this->fakeSeparator('exit 1');
        $out = $this->dubSilently($video, 'music', 'filtered');
        $this->assertSame('filters', $out['report']['separation']['method']);
        $this->assertEqualsWithDelta($this->level($video, 4.0, 1.5), $this->level($out['file'], 4.0, 1.5), 1.5, 'between lines, the original');
        $this->assertLessThan(-60, $this->level($out['file'], 1.4, 1.2), 'under the new line no old voice is heard');

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

    /** A separator set up with a stand-in for Python that runs $body with $in, $rest and $voices set (the last three arguments). */
    private function fakeSeparator(string $body): void
    {
        $dir = $this->dir.'/separator';
        @mkdir($dir.'/lib', 0755, true);
        file_put_contents($dir.'/model.onnx', 'model');
        file_put_contents($dir.'/ready.json', '{}');
        $python = $this->dir.'/python';
        file_put_contents($python, "#!/bin/sh\nn=\$#\nvoices=\$(eval echo \"\\\${\$n}\")\nrest=\$(eval echo \"\\\${\$((n - 1))}\")\nin=\$(eval echo \"\\\${\$((n - 2))}\")\n{$body}\n");
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
