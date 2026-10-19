<?php

namespace Tests\Feature;

use App\Support\AudioEffects;
use App\Support\AudioFormats;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * What a sound environment does to the voice, measured on real audio made by
 * ffmpeg: the last word keeps its level, the walkie-talkie keeps its end
 * squelch, and every environment lands at the site's one loudness.
 */
class AudioEffectsQualityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (AudioFormats::ffmpegPath() === null) {
            $this->markTestSkipped('ffmpeg is not installed here.');
        }
        Storage::fake('public');
        $this->tone('audio/cache/voice.mp3', 3);
    }

    public function test_the_last_word_keeps_its_level_without_a_reverb_tail(): void
    {
        foreach (['studio', 'phone', 'radio'] as $effect) {
            $path = AudioEffects::apply('audio/cache/voice.mp3', $effect);
            $this->assertNotNull($path, $effect);
            $length = $this->seconds($path);

            $steady = $this->rmsDb($path, 1.0, 0.25);
            $last = $this->rmsDb($path, $length - 0.3, 0.25);

            // A 0.5 s fade used to take the last syllable down by several dB.
            $this->assertGreaterThan($steady - 1.5, $last, $effect.': the end of the voice is not faded away');
        }
    }

    public function test_the_walkie_talkie_ends_with_its_squelch(): void
    {
        $path = AudioEffects::apply('audio/cache/voice.mp3', 'walkie');
        $this->assertNotNull($path);
        $length = $this->seconds($path);

        $this->assertGreaterThan(-40.0, $this->rmsDb($path, $length - 0.2, 0.15), 'the closing squelch is heard, not faded out');
    }

    public function test_every_environment_lands_at_one_loudness(): void
    {
        foreach (['studio', 'hall', 'walkie', 'cafe'] as $effect) {
            if (! AudioEffects::isValid($effect)) {
                continue;
            }
            $path = AudioEffects::apply('audio/cache/voice.mp3', $effect);
            $this->assertNotNull($path, $effect);

            $lufs = $this->integratedLoudness($path);
            $this->assertGreaterThan(-18.0, $lufs, $effect);
            $this->assertLessThan(-14.0, $lufs, $effect);
        }
    }

    private function tone(string $path, int $seconds): void
    {
        $disk = Storage::disk('public');
        @mkdir(dirname($disk->path($path)), 0775, true);
        (new Process([AudioFormats::ffmpegPath(), '-hide_banner', '-loglevel', 'error', '-y', '-f', 'lavfi',
            '-i', 'sine=frequency=220:duration='.$seconds, '-ar', '24000', '-codec:a', 'libmp3lame', $disk->path($path)]))->mustRun();
    }

    private function seconds(string $path): float
    {
        $ffprobe = dirname((string) AudioFormats::ffmpegPath()).'/ffprobe';
        $process = new Process([$ffprobe, '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', Storage::disk('public')->path($path)]);

        return (float) trim($process->mustRun()->getOutput());
    }

    private function rmsDb(string $path, float $start, float $duration): float
    {
        $process = new Process([AudioFormats::ffmpegPath(), '-hide_banner', '-ss', (string) max(0.0, $start), '-t', (string) $duration,
            '-i', Storage::disk('public')->path($path), '-af', 'astats=measure_overall=RMS_level:measure_perchannel=none', '-f', 'null', '-']);
        $process->mustRun();
        preg_match('/RMS level dB:\s*(-?[\d.]+|-inf)/', $process->getErrorOutput(), $m);

        return isset($m[1]) && $m[1] !== '-inf' ? (float) $m[1] : -120.0;
    }

    private function integratedLoudness(string $path): float
    {
        $process = new Process([AudioFormats::ffmpegPath(), '-hide_banner', '-i', Storage::disk('public')->path($path),
            '-af', 'ebur128=framelog=quiet', '-f', 'null', '-']);
        $process->mustRun();
        $log = $process->getErrorOutput();
        preg_match('/I:\s+(-?[\d.]+) LUFS/', (string) substr($log, (int) strrpos($log, 'Summary:')), $m);

        return (float) ($m[1] ?? 0.0);
    }
}
