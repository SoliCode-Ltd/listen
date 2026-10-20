<?php

namespace App\Console\Commands;

use App\Services\Dubbing\VideoDubber;
use App\Support\AudioFormats;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * Measures what dubbing a video costs (the "Dub my video" prototype), on
 * STAGING: makes test videos from the site's own English long read (a 720p
 * picture with that narration), dubs each into each dialect asked for, and
 * prints the model's tokens, the characters voiced, the server's processing
 * time and the files' sizes, per video and per minute of video. The dubbed
 * videos stay on the public disk (dub-measure/<run>/) to watch.
 *
 * It uses the speech quota staging shares with live (12 videos of 15, 30 and
 * 60 seconds in four dialects voice about 7 minutes), so it runs by hand
 * only, when the owner has said yes. It refuses to run on the live site.
 *
 *   php artisan dub:measure
 *   php artisan dub:measure --lengths=30 --dialects=ar-SA --audio-price=1 --in-price=0.5 --out-price=3
 */
class DubMeasureCommand extends Command
{
    protected $signature = 'dub:measure
        {--lengths=15,30,60 : Test video lengths, in seconds}
        {--dialects=ar-SA,ar-EG,ar-LB,ar-XA : Dialects to dub into}
        {--voice=Puck : The voice that reads the dubbed lines}
        {--style=conversational : Performance style}
        {--keep-original=0 : The original sound under the new voice (0 replaces it, 0.15 keeps it quietly)}
        {--in-price=0 : Text model price per million text input tokens, USD (to turn tokens into money)}
        {--audio-price=0 : Text model price per million audio input tokens, USD}
        {--out-price=0 : Text model price per million output and thinking tokens, USD}
        {--tts-price= : Speech price per million characters, USD (default: services.gemini_tts.cost_per_million_chars)}';

    protected $description = 'Dub test videos into Arabic dialects and measure what each costs (staging only).';

    public function handle(VideoDubber $dubber): int
    {
        if (str_contains(strtolower((string) config('app.url')), 'listenai.studio')) {
            $this->error('This is the live site: dub:measure runs on staging only.');

            return self::FAILURE;
        }
        $ffmpeg = AudioFormats::ffmpegPath();
        $source = public_path('audio/long-form/long-en-deep-sea.mp3');
        if (! $ffmpeg || ! is_file($source)) {
            $this->error($ffmpeg ? 'The English long read is missing: '.$source : 'ffmpeg is not available.');

            return self::FAILURE;
        }

        $run = now()->utc()->format('Ymd-His');
        $dir = Storage::disk('public')->path('dub-measure/'.$run);
        @mkdir($dir.'/source', 0755, true);
        $lengths = array_values(array_filter(array_map('intval', explode(',', (string) $this->option('lengths')))));
        $dialects = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('dialects')))));

        $videos = [];
        foreach ($lengths as $seconds) {
            $file = $dir.'/source/test-'.$seconds.'s.mp4';
            // A moving 720p picture (so the preview's encoding costs what a real video's would) with our narration.
            $make = new Process([$ffmpeg, '-v', 'error', '-y', '-f', 'lavfi', '-i', 'testsrc2=size=1280x720:rate=30', '-ss', '0', '-t', (string) $seconds, '-i', $source,
                '-t', (string) $seconds, '-map', '0:v', '-map', '1:a', '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '23', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-b:a', '128k', $file]);
            $make->setTimeout(300)->run();
            if (! $make->isSuccessful()) {
                $this->error('Could not make the test video: '.$make->getErrorOutput());

                return self::FAILURE;
            }
            $videos[$seconds] = $file;
        }

        $ttsPrice = $this->option('tts-price') !== null ? (float) $this->option('tts-price') : (float) config('services.gemini_tts.cost_per_million_chars', 0);
        $prices = ['in' => (float) $this->option('in-price'), 'audio' => (float) $this->option('audio-price'), 'out' => (float) $this->option('out-price'), 'tts' => $ttsPrice];
        $this->line(sprintf('Run %s: %d video(s) x %d dialect(s), voice %s, style %s.', $run, count($videos), count($dialects), $this->option('voice'), $this->option('style')));

        $reports = [];
        foreach ($videos as $seconds => $video) {
            foreach ($dialects as $dialect) {
                $name = 'test-'.$seconds.'s-'.$dialect;
                try {
                    $r = $dubber->dub($video, [
                        'language' => str_starts_with($dialect, 'ar') ? 'ar' : 'en', 'dialect' => $dialect, 'voice' => (string) $this->option('voice'),
                        'style' => (string) $this->option('style'), 'keep_original' => (float) $this->option('keep-original'),
                        'preview' => true, 'out_dir' => $dir, 'name' => $name,
                    ]);
                    $r['cost'] = self::cost($r, $prices);
                    $reports[] = $r;
                    $this->line(sprintf('  %-22s %5.1fs video  %2d lines  %5d chars  tokens in %5d (audio %5d) out %4d think %4d  | write-out %5.1fs  voice %5.1fs  mux %4.1fs  preview %5.1fs (cpu %5.1fs)  | total %5.1fs  | $%.4f',
                        $name, $r['video_seconds'], $r['segments'], $r['voicing']['characters'], $r['transcribe']['input_tokens'], $r['transcribe']['audio_tokens'],
                        $r['transcribe']['output_tokens'], $r['transcribe']['thinking_tokens'], $r['transcribe']['seconds'], $r['voicing']['seconds'],
                        $r['mux']['seconds'], $r['preview_encode']['seconds'], $r['preview_encode']['cpu_seconds'], $r['total_seconds'], $r['cost']['total']));
                    $this->line('      '.Storage::disk('public')->url('dub-measure/'.$run.'/'.$name.'.mp4').'   (preview: …/'.$name.'-preview.mp4)');
                } catch (\Throwable $e) {
                    $this->warn('  '.$name.': '.mb_substr($e->getMessage(), 0, 300));
                    $reports[] = ['video' => basename($video), 'dialect' => $dialect, 'error' => $e->getMessage()];
                }
            }
        }

        $done = array_values(array_filter($reports, fn ($r) => ! isset($r['error'])));
        if ($done !== []) {
            $minutes = array_sum(array_column($done, 'video_seconds')) / 60;
            $sum = fn (string $path) => array_sum(array_map(fn ($r) => (float) data_get($r, $path, 0), $done));
            $this->newLine();
            $this->line(sprintf('Per minute of video (%d dubbed, %.1f minutes in all):', count($done), $minutes));
            $this->line(sprintf('  model tokens: %d in (%d of them audio), %d out, %d thinking', $sum('transcribe.input_tokens') / $minutes, $sum('transcribe.audio_tokens') / $minutes,
                $sum('transcribe.output_tokens') / $minutes, $sum('transcribe.thinking_tokens') / $minutes));
            $this->line(sprintf('  characters voiced: %d (%.1f s of speech)', $sum('voicing.characters') / $minutes, $sum('voicing.voiced_seconds') / $minutes));
            $this->line(sprintf('  waiting time: %.1f s (write-out %.1f, voice %.1f, picture %.1f)', $sum('total_seconds') / $minutes, $sum('transcribe.seconds') / $minutes,
                $sum('voicing.seconds') / $minutes, ($sum('mux.seconds') + $sum('preview_encode.seconds')) / $minutes));
            $this->line(sprintf('  server CPU: %.1f s for the clean copy, %.1f s for the marked preview', $sum('mux.cpu_seconds') / $minutes, $sum('preview_encode.cpu_seconds') / $minutes));
            $this->line(sprintf('  file sizes: %.1f MB clean, %.1f MB preview', $sum('output_bytes') / $minutes / 1048576, $sum('preview_bytes') / $minutes / 1048576));
            $this->line(sprintf('  lines sped up to fit: %d of %d (fastest x%.2f)', $sum('fit.sped_up'), $sum('segments'), max(array_map(fn ($r) => $r['fit']['max_tempo'], $done))));
            $this->line(sprintf('  money: $%.4f (model $%.4f, voice $%.4f)%s', $sum('cost.total') / $minutes, $sum('cost.model') / $minutes, $sum('cost.voice') / $minutes,
                array_sum($prices) > 0 ? '' : '  (no prices given: pass --in-price, --audio-price, --out-price, --tts-price)'));
        }
        file_put_contents($dir.'/report.json', json_encode(['run' => $run, 'prices' => $prices, 'reports' => $reports], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->line('Report: '.Storage::disk('public')->url('dub-measure/'.$run.'/report.json'));

        return $done === [] ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $r
     * @param  array{in: float, audio: float, out: float, tts: float}  $prices
     * @return array{model: float, voice: float, total: float}
     */
    public static function cost(array $r, array $prices): array
    {
        $t = $r['transcribe'];
        $model = (($t['input_tokens'] - $t['audio_tokens']) * $prices['in'] + $t['audio_tokens'] * $prices['audio']
            + ($t['output_tokens'] + $t['thinking_tokens']) * $prices['out']) / 1e6;
        $voice = $r['voicing']['characters'] * $prices['tts'] / 1e6;

        return ['model' => round($model, 6), 'voice' => round($voice, 6), 'total' => round($model + $voice, 6)];
    }
}
