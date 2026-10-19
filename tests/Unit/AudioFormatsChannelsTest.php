<?php

namespace Tests\Unit;

use App\Support\AudioFormats;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Download formats keep the recording's own channels: a voice in a sound
 * environment is stereo, and folding it to mono drops the room's width and
 * about 3 dB of level.
 */
class AudioFormatsChannelsTest extends TestCase
{
    public function test_a_stereo_recording_stays_stereo_and_a_mono_one_mono(): void
    {
        $ffmpeg = AudioFormats::ffmpegPath();
        if ($ffmpeg === null) {
            $this->markTestSkipped('ffmpeg is not installed here.');
        }
        $dir = sys_get_temp_dir().'/listen-formats-'.bin2hex(random_bytes(4));
        mkdir($dir);

        try {
            foreach ([2, 1] as $channels) {
                $source = "{$dir}/voice-{$channels}.mp3";
                (new Process([$ffmpeg, '-hide_banner', '-loglevel', 'error', '-y', '-f', 'lavfi', '-i', 'sine=frequency=220:duration=1',
                    '-ac', (string) $channels, '-codec:a', 'libmp3lame', $source]))->mustRun();

                foreach (['flac', 'm4a', 'ogg', 'wav'] as $format) {
                    $bytes = AudioFormats::convert($source, $format);
                    $this->assertNotNull($bytes, $format);
                    file_put_contents("{$dir}/out.{$format}", $bytes);
                    $probe = new Process([dirname($ffmpeg).'/ffprobe', '-v', 'error', '-show_entries', 'stream=channels', '-of', 'csv=p=0', "{$dir}/out.{$format}"]);
                    $this->assertSame((string) $channels, trim($probe->mustRun()->getOutput()), "{$format} from {$channels} channel(s)");
                }
            }
        } finally {
            array_map('unlink', glob("{$dir}/*") ?: []);
            rmdir($dir);
        }
    }
}
