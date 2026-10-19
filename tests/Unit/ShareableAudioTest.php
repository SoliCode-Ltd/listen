<?php

namespace Tests\Unit;

use App\Support\AudioFormats;
use App\Support\ShareableAudio;
use Tests\TestCase;

class ShareableAudioTest extends TestCase
{
    public function test_wav_speech_is_packaged_as_mp3(): void
    {
        $packaged = ShareableAudio::package($this->silentWav());

        $this->assertSame('mp3', $packaged['extension']);
        $this->assertTrue(ShareableAudio::isMp3($packaged['bytes']));
        $this->assertFalse(ShareableAudio::isWav($packaged['bytes']));
    }

    public function test_wav_speech_is_packaged_as_mp3_when_web_php_has_no_path(): void
    {
        if (AudioFormats::ffmpegPath() === null) {
            $this->markTestSkipped('ffmpeg is not installed here.');
        }
        $path = getenv('PATH');
        putenv('PATH=');

        try {
            $packaged = ShareableAudio::package($this->silentWav());
        } finally {
            putenv('PATH='.$path);
        }

        $this->assertSame('mp3', $packaged['extension']);
        $this->assertTrue(ShareableAudio::isMp3($packaged['bytes']));
    }

    public function test_a_take_that_runs_on_in_hiss_is_cut_after_the_last_word(): void
    {
        $this->requireFfmpeg();
        // 1.5 s of voice, then 180 s of hiss at about -62 dB (a real take once did this).
        $wav = $this->wav('sine=frequency=220:duration=1.5,apad=pad_dur=180', 'volume=1', 'anoisesrc=color=white:amplitude=0.0008:duration=181.5');

        $packaged = ShareableAudio::package($wav);

        $this->assertSame('mp3', $packaged['extension']);
        $seconds = $this->mp3Seconds($packaged['bytes']);
        $this->assertGreaterThan(1.5, $seconds, 'the voice is kept whole');
        $this->assertLessThan(2.3, $seconds, 'the hiss after it is gone (members pay by the second)');
    }

    public function test_a_normal_take_keeps_its_natural_edges(): void
    {
        $this->requireFfmpeg();
        // 0.28 s before the voice and 0.24 s after it, as the engine's takes usually have.
        $wav = $this->wav('aevalsrc=0:d=0.28[a];sine=frequency=220:duration=2[b];aevalsrc=0:d=0.24[c];[a][b][c]concat=n=3:v=0:a=1');

        $seconds = $this->mp3Seconds(ShareableAudio::package($wav)['bytes']);

        $this->assertGreaterThan(2.35, $seconds);
        $this->assertLessThan(2.6, $seconds);
    }

    public function test_an_all_quiet_take_is_kept_whole(): void
    {
        $packaged = ShareableAudio::package($this->silentWav());

        $this->assertSame('mp3', $packaged['extension']);
        $this->assertGreaterThan(0.15, $this->mp3Seconds($packaged['bytes']));
    }

    public function test_a_trim_that_runs_out_of_time_is_tried_again_as_the_take_came(): void
    {
        $this->requireFfmpeg();
        $wav = $this->wav('aevalsrc=0:d=0.5[a];sine=frequency=220:duration=1[b];aevalsrc=0:d=0.5[c];[a][b][c]concat=n=3:v=0:a=1');
        $real = (string) AudioFormats::ffmpegPath();
        // An ffmpeg that hangs on every trimmed run, with a 1 s time limit.
        $dir = sys_get_temp_dir().'/listen-slow-ffmpeg-'.bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir.'/ffmpeg', "#!/bin/sh\ncase \"\$*\" in *atrim*) exec sleep 8;; esac\nexec ".escapeshellarg($real)." \"\$@\"\n");
        chmod($dir.'/ffmpeg', 0755);
        config(['services.gemini_tts.ffmpeg_path' => $dir.'/ffmpeg', 'listen.audio.encode_timeout_seconds' => 1]);

        try {
            $started = microtime(true);
            $packaged = ShareableAudio::package($wav);
            $took = microtime(true) - $started;
        } finally {
            config(['services.gemini_tts.ffmpeg_path' => $real]);
            unlink($dir.'/ffmpeg');
            rmdir($dir);
        }

        $this->assertSame('mp3', $packaged['extension'], 'a timeout no longer leaves the take as WAV');
        $this->assertLessThan(7, $took, 'the stuck runs were stopped at their time limit');
        $this->assertEqualsWithDelta(2.0, $this->mp3Seconds($packaged['bytes']), 0.15, 'encoded whole, as it came');
    }

    public function test_the_time_limit_grows_with_the_take(): void
    {
        config(['listen.audio.encode_timeout_seconds' => 30]);

        $this->assertSame(31.0, ShareableAudio::timeout(10));
        $this->assertSame(390.0, ShareableAudio::timeout(3600), 'an hour may take 6 minutes before it is encoded as it came');
    }

    public function test_without_ffmpeg_a_wav_is_kept_as_it_came(): void
    {
        config(['services.gemini_tts.ffmpeg_path' => 'none']);
        $wav = $this->silentWav();

        $packaged = ShareableAudio::package($wav);

        $this->assertSame('wav', $packaged['extension']);
        $this->assertSame($wav, $packaged['bytes']);
    }

    public function test_an_mp3_is_left_unchanged(): void
    {
        $mp3 = "ID3\x03\x00\x00\x00\x00\x00\x00";

        $packaged = ShareableAudio::package($mp3);

        $this->assertSame('mp3', $packaged['extension']);
        $this->assertSame($mp3, $packaged['bytes']);
    }

    private function requireFfmpeg(): void
    {
        if (AudioFormats::ffmpegPath() === null) {
            $this->markTestSkipped('ffmpeg is not installed here.');
        }
    }

    /**
     * A 24 kHz mono 16-bit WAV made by ffmpeg from a filter graph (with an
     * optional noise source mixed in), as the engine sends its takes.
     */
    private function wav(string $graph, string $voiceFilter = 'anull', ?string $noise = null): string
    {
        $file = sys_get_temp_dir().'/listen-test-'.bin2hex(random_bytes(4)).'.wav';
        $complex = $noise !== null
            ? $graph.','.$voiceFilter.'[v];'.$noise.'[n];[v][n]amix=inputs=2:duration=longest:normalize=0'
            : $graph;
        (new \Symfony\Component\Process\Process([AudioFormats::ffmpegPath(), '-hide_banner', '-loglevel', 'error', '-y',
            '-filter_complex', $complex, '-ar', '24000', '-ac', '1', '-c:a', 'pcm_s16le', $file]))->mustRun();
        $bytes = (string) file_get_contents($file);
        unlink($file);

        return $bytes;
    }

    private function mp3Seconds(string $mp3): float
    {
        $file = sys_get_temp_dir().'/listen-test-'.bin2hex(random_bytes(4)).'.mp3';
        file_put_contents($file, $mp3);
        $probe = new \Symfony\Component\Process\Process([dirname((string) AudioFormats::ffmpegPath()).'/ffprobe', '-v', 'error',
            '-show_entries', 'format=duration', '-of', 'csv=p=0', $file]);
        $seconds = (float) trim($probe->mustRun()->getOutput());
        unlink($file);

        return $seconds;
    }

    private function silentWav(): string
    {
        $rate = 24000;
        $data = str_repeat("\x00\x00", (int) ($rate * 0.2));

        return 'RIFF'.pack('V', 36 + strlen($data)).'WAVEfmt '
            .pack('VvvVVvv', 16, 1, 1, $rate, $rate * 2, 2, 16)
            .'data'.pack('V', strlen($data)).$data;
    }
}
