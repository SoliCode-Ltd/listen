<?php

namespace Tests\Feature;

use App\Support\AudioFormats;
use App\Support\ShareableAudio;
use App\Support\SpeechJoin;
use App\Support\SpeechLoudness;
use App\Support\WavPcm;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Every finished take, and every piece of a long one, at about -16 LUFS
 * with its true peak under -1 dBTP. The samples are generated and measured
 * with ffmpeg here (the tests skip without it); no engine is called.
 */
class TakeLoudnessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (AudioFormats::ffmpegPath() === null) {
            $this->markTestSkipped('ffmpeg is not installed here.');
        }
    }

    public function test_a_quiet_take_is_brought_up_to_minus_16_lufs(): void
    {
        $wav = $this->speechAt(-30.0, 12);

        $measured = $this->loudness($this->package($wav));

        $this->assertEqualsWithDelta(SpeechLoudness::TARGET_LUFS, $measured['I'], 0.5);
        $this->assertLessThanOrEqual(SpeechLoudness::CEILING_DBTP, $measured['TP']);
    }

    public function test_a_loud_take_is_brought_down_to_minus_16_lufs(): void
    {
        // As loud as the loudest takes come, its peaks at full scale.
        $wav = $this->speechAt(-11.0, 12);
        $this->assertGreaterThan(-0.5, $this->loudness($wav)['TP']);

        $measured = $this->loudness($this->package($wav));

        $this->assertEqualsWithDelta(SpeechLoudness::TARGET_LUFS, $measured['I'], 0.5);
        $this->assertLessThanOrEqual(SpeechLoudness::CEILING_DBTP, $measured['TP']);
    }

    public function test_peaks_that_stand_far_out_are_held_under_the_ceiling(): void
    {
        // Quiet speech with a 1 ms click every second: the gain to -16 LUFS would put
        // the clicks far over full scale.
        $wav = $this->speechAt(-28.0, 10, "aevalsrc='0.9*sin(2*PI*1000*t)*lt(mod(t,1),0.001)':s=24000:d=10");
        $before = $this->loudness($wav);
        $this->assertGreaterThan(18, $before['TP'] - $before['I'], 'its peaks stand far above its speech');

        $measured = $this->loudness($this->package($wav));

        $this->assertLessThanOrEqual(SpeechLoudness::CEILING_DBTP, $measured['TP']);
        $this->assertEqualsWithDelta(SpeechLoudness::TARGET_LUFS, $measured['I'], 1.5, 'and the take still comes up to about the target');
    }

    public function test_levelling_keeps_the_length_members_are_charged_for(): void
    {
        $wav = $this->padded($this->speechAt(-30.0, 7), 1.0, 1.0);
        $file = $this->temp($wav);
        $kept = ShareableAudio::voice($file, WavPcm::locate($wav));
        unlink($file);

        $mp3 = $this->package($wav);

        // Only the long quiet at the edges is cut (to 0.2 s and 0.35 s), as before: the
        // gain and the limiter add and take away nothing.
        $this->assertEqualsWithDelta(SpeechLoudness::TARGET_LUFS, $this->loudness($mp3)['I'], 0.5, 'it was levelled');
        $this->assertSame($kept[1] - $kept[0], $this->decodedFrames($mp3));
    }

    public function test_a_piece_much_quieter_than_the_next_is_levelled_before_they_meet(): void
    {
        $joined = SpeechJoin::toFile([$this->speechAt(-32.0, 8), $this->speechAt(-13.0, 8)]);
        $packaged = ShareableAudio::packageFile($joined['path'], $joined['pieces']);
        $mp3 = (string) file_get_contents($packaged['path']);
        $at = $this->pieceLoudness($joined, $mp3);
        ShareableAudio::discard($joined['path'], $packaged['path']);

        $this->assertEqualsWithDelta($at(0), $at(1), 1.0, 'no jump in level at the join');
        $this->assertEqualsWithDelta(SpeechLoudness::TARGET_LUFS, $this->loudness($mp3)['I'], 0.5);
        $this->assertLessThanOrEqual(SpeechLoudness::CEILING_DBTP, $this->loudness($mp3)['TP']);
    }

    public function test_a_part_read_in_another_delivery_keeps_its_own_level(): void
    {
        // Two pieces of the script's own delivery far apart, then a [whispering] part 12 dB under the second.
        $joined = SpeechJoin::toFile([$this->speechAt(-26.0, 6), $this->speechAt(-18.0, 6), $this->speechAt(-30.0, 6)], [], [0, 0, 1]);
        $packaged = ShareableAudio::packageFile($joined['path'], $joined['pieces']);
        $mp3 = (string) file_get_contents($packaged['path']);
        $at = $this->pieceLoudness($joined, $mp3);
        ShareableAudio::discard($joined['path'], $packaged['path']);

        $this->assertEqualsWithDelta($at(0), $at(1), 1.0, 'the pieces of one part are levelled');
        $this->assertEqualsWithDelta(8.0, $at(1) - $at(2), 1.0, 'the whisper stays under them as it was under their middle level');
    }

    public function test_a_short_take_is_measured_on_its_voice_not_the_quiet_around_it(): void
    {
        // 1.5 s of speech between 0.6 s and 0.8 s of quiet: 0.2 s and 0.35 s of it stay.
        $wav = $this->padded($this->speechAt(-30.0, 1.5), 0.6, 0.8);

        $mp3 = $this->package($wav);

        $this->assertEqualsWithDelta(SpeechLoudness::TARGET_LUFS, $this->loudness($mp3, 0.2, 1.5)['I'], 0.7, 'the voice is at the level of any other take');
        $this->assertLessThanOrEqual(SpeechLoudness::CEILING_DBTP, $this->loudness($mp3)['TP']);
    }

    public function test_a_take_under_one_measuring_block_is_raised_only_as_far_as_its_peaks_allow(): void
    {
        // A word of 0.3 s cut from speech at -30 LUFS, with a 5 ms blip at -10.5 dBFS in it:
        // too short for integrated loudness (its blocks are 400 ms), and its blip sets the limit.
        $speech = $this->speechAt(-30.0, 2);
        $pcm = WavPcm::locate($speech);
        $word = array_values(unpack('s*', substr($speech, $pcm['offset'], 2 * 7200)));
        for ($n = 0; $n < 120; $n++) {
            $word[1900 + $n] = (int) round($word[1900 + $n] + 0.3 * 32767 * sin(2 * M_PI * 1000 * $n / 24000));
        }
        $word = pack('v*', ...$word);
        $wav = $this->padded(WavPcm::header(strlen($word), 24000).$word, 0.6, 0.6);
        $before = $this->loudness($wav);

        $measured = $this->loudness($this->package($wav));

        $this->assertGreaterThan($before['I'] + 3, $measured['I'], 'raised');
        $this->assertEqualsWithDelta(-1.5 - 0.45, $measured['TP'], 0.4, 'up to where its loudest peak meets the ceiling, never into the limiter');
    }

    public function test_a_take_of_silence_or_hiss_is_not_raised(): void
    {
        $hiss = $this->wav('anoisesrc=d=5:c=white:r=24000:a=0.0005');

        $measured = $this->loudness($this->package($hiss));

        $this->assertLessThan(-55, $measured['I']);
    }

    /**
     * Pink-noise syllables (160 ms on, 90 ms off), brought to $lufs as ffmpeg
     * measures it: speech, for the meter and the limiter. $extra is mixed in.
     */
    private function speechAt(float $lufs, float $seconds, ?string $extra = null): string
    {
        $graph = "anoisesrc=d={$seconds}:c=pink:r=24000:a=0.5,volume='if(lt(mod(t,0.25),0.16),1,0.01)':eval=frame,highpass=f=100";
        $gain = $lufs - $this->loudness($this->wav($graph))['I'];
        $graph .= ',volume='.round($gain, 2).'dB';

        return $this->wav($extra !== null ? $graph.'[v];'.$extra.'[x];[v][x]amix=inputs=2:normalize=0' : $graph);
    }

    /**
     * A WAV with quiet before and after it.
     */
    private function padded(string $wav, float $before, float $after): string
    {
        $pcm = WavPcm::locate($wav);
        $data = str_repeat("\0", 2 * (int) round($before * 24000)).substr($wav, $pcm['offset'], $pcm['length']).str_repeat("\0", 2 * (int) round($after * 24000));

        return WavPcm::header(strlen($data), 24000).$data;
    }

    /**
     * The loudness of piece $k where it ended up in the MP3 (the lead before
     * the first word is cut to 0.2 s).
     *
     * @param  array{path: string, pieces: array<int, array<int, int>>}  $joined
     */
    private function pieceLoudness(array $joined, string $mp3): \Closure
    {
        $lead = ShareableAudio::voice($joined['path'], WavPcm::locate((string) file_get_contents($joined['path'])))[0];
        $pieces = $joined['pieces'];

        return fn (int $k): float => $this->loudness($mp3, ($pieces[$k][0] - $lead) / 24000, ($pieces[$k][1] - $pieces[$k][0]) / 24000)['I'];
    }

    /**
     * Frames the MP3 decodes to: its true length, without the encoder's padding.
     */
    private function decodedFrames(string $mp3): int
    {
        $file = $this->temp($mp3);
        $process = new Process([AudioFormats::ffmpegPath(), '-hide_banner', '-loglevel', 'error', '-i', $file, '-f', 's16le', '-ac', '1', '-']);
        $frames = intdiv(strlen($process->mustRun()->getOutput()), 2);
        unlink($file);

        return $frames;
    }

    private function wav(string $graph): string
    {
        $file = sys_get_temp_dir().'/listen-loud-'.bin2hex(random_bytes(4)).'.wav';
        (new Process([AudioFormats::ffmpegPath(), '-hide_banner', '-loglevel', 'error', '-y',
            '-filter_complex', $graph, '-ar', '24000', '-ac', '1', '-c:a', 'pcm_s16le', $file]))->mustRun();
        $bytes = (string) file_get_contents($file);
        unlink($file);

        return $bytes;
    }

    private function package(string $wav): string
    {
        $packaged = ShareableAudio::package($wav);
        $this->assertSame('mp3', $packaged['extension']);

        return $packaged['bytes'];
    }

    /**
     * Integrated loudness and true peak, measured by ffmpeg (of a part, in seconds, when given).
     *
     * @return array{I: float, TP: float}
     */
    private function loudness(string $audio, ?float $from = null, ?float $length = null): array
    {
        $file = $this->temp($audio);
        $process = new Process(array_merge(
            [AudioFormats::ffmpegPath(), '-hide_banner', '-nostats'],
            $from !== null ? ['-ss', sprintf('%.3f', max(0, $from)), '-t', sprintf('%.3f', $length)] : [],
            ['-i', $file, '-af', 'ebur128=peak=true', '-f', 'null', '-'],
        ));
        $process->mustRun();
        unlink($file);
        $log = (string) substr($process->getErrorOutput(), (int) strrpos($process->getErrorOutput(), 'Summary:'));
        preg_match('/I:\s+(-?[\d.]+) LUFS/', $log, $i);
        preg_match('/True peak:\s+Peak:\s+(-?[\d.]+|-inf)/', $log, $tp);

        return ['I' => (float) $i[1], 'TP' => ($tp[1] ?? '-inf') === '-inf' ? -INF : (float) $tp[1]];
    }

    private function temp(string $bytes): string
    {
        $file = sys_get_temp_dir().'/listen-loud-'.bin2hex(random_bytes(4));
        file_put_contents($file, $bytes);

        return $file;
    }
}
