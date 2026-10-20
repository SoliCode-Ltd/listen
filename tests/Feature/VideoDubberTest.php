<?php

namespace Tests\Feature;

use App\Console\Commands\DubMeasureCommand;
use App\Services\Dubbing\VideoDubber;
use App\Services\Tts\GeminiKeyManager;
use App\Services\Tts\GeminiTtsProvider;
use App\Support\AudioFormats;
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
            'candidates' => [['content' => ['parts' => [['text' => json_encode(['segments' => [
                ['start' => 3.0, 'end' => 5.5, 'original' => 'See you tomorrow.', 'dubbed' => 'نشوفك بكرة.'],
                ['start' => 0.2, 'end' => 2.8, 'original' => 'Hello there, welcome.', 'dubbed' => 'هلا والله، حياك.'],
            ]])]]]]],
            'usageMetadata' => ['promptTokenCount' => 260, 'candidatesTokenCount' => 90, 'thoughtsTokenCount' => 40,
                'promptTokensDetails' => [['modality' => 'TEXT', 'tokenCount' => 68], ['modality' => 'AUDIO', 'tokenCount' => 192]]],
        ])]);

        // Each line voiced: a real short clip on the public disk, the second one too long for its room.
        $tts = \Mockery::mock(GeminiTtsProvider::class);
        $n = 0;
        $tts->shouldReceive('synthesize')->twice()->andReturnUsing(function (string $text, string $language, array $config) use ($ffmpeg, &$n) {
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
        $r = $dubber->dub($video, ['language' => 'ar', 'dialect' => 'ar-SA', 'voice' => 'Puck', 'out_dir' => $this->dir, 'name' => 'out']);

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
}
