<?php

namespace App\Support\Api;

use App\Support\AudioFormats;
use Symfony\Component\Process\Process;

/**
 * The waveform of a short recording on the developer docs: the average
 * loudness (RMS) of each of $count equal slices, from 8 to 100 on a gently
 * eased scale. It is the recording's own shape: its words rise and fall, and
 * its pauses fall to the floor where they are heard (the loudest moment of a
 * slice would stand almost every bar at the top). Null without ffmpeg or a
 * readable file; the page then draws a plain one.
 */
final class SampleWaveform
{
    /** @return list<int>|null */
    public static function bars(string $file, int $count = 64): ?array
    {
        $ffmpeg = AudioFormats::ffmpegPath();
        if ($ffmpeg === null || $ffmpeg === '' || ! is_file($file) || $count < 1) {
            return null;
        }
        try {
            $process = new Process([$ffmpeg, '-v', 'error', '-i', $file, '-ac', '1', '-ar', '8000', '-f', 's16le', 'pipe:1']);
            $process->setTimeout(20);
            $process->run();
        } catch (\Throwable) {
            return null;
        }
        $pcm = $process->isSuccessful() ? $process->getOutput() : '';
        $samples = intdiv(strlen($pcm), 2);
        if ($samples < $count) {
            return null;
        }
        $values = array_values((array) unpack('s*', $pcm));
        $per = intdiv($samples, $count);
        $levels = [];
        for ($i = 0; $i < $count; $i++) {
            $sum = 0.0;
            for ($j = $i * $per, $end = $j + $per; $j < $end; $j++) {
                $sum += $values[$j] * $values[$j];
            }
            $levels[] = sqrt($sum / $per);
        }
        $top = max($levels);
        if ($top <= 0) {
            return null;
        }

        return array_map(static fn (float $level): int => (int) max(8, round(100 * ($level / $top) ** 0.8)), $levels);
    }
}
