<?php

namespace App\Console\Commands;

use App\Services\VoiceDesignService;
use App\Support\VoiceDesign;
use Illuminate\Console\Command;

/**
 * The example characters on the Voice Design card: each one designed from its
 * description (lang/en/voice_design.php → examples) and recorded saying its
 * line, into public/audio/voice-design/examples/{key}.mp3, which is committed
 * with the code so every server plays them. The voices are deleted again at
 * once; only the clips are kept. Made by the "Voice Design examples" workflow
 * with the staging keys (speech quota staging shares with live: only when the
 * owner asks). Clips already made are left alone unless --force.
 *
 *   php artisan voice-design:examples --key=... [--only=evil_ogre --only=cairo_grandma] [--force]
 */
class MakeVoiceDesignExamplesCommand extends Command
{
    protected $signature = 'voice-design:examples
        {--key= : A speech key to use instead of the site\'s}
        {--only=* : Example keys to make (default: every example)}
        {--force : Make clips that already exist again}';

    protected $description = 'Record the example characters for the Voice Design card.';

    public function handle(VoiceDesignService $designer): int
    {
        $examples = collect(VoiceDesign::examples('en'));
        $only = array_filter((array) $this->option('only'));
        if ($only !== []) {
            $examples = $examples->whereIn('key', $only);
        }
        $key = filled($this->option('key')) ? ['key' => (string) $this->option('key'), 'name' => 'given'] : null;
        $dir = public_path(VoiceDesign::EXAMPLES_DIR);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $made = 0;
        $failed = 0;
        $summary = [];
        foreach ($examples as $example) {
            $file = $dir.'/'.$example['key'].'.mp3';
            if (is_file($file) && ! $this->option('force')) {
                $this->line("{$example['key']}: already made");

                continue;
            }
            $started = microtime(true);
            $clip = $designer->sample($example['description'], $example['language'], $example['line'], $key);
            $seconds = round(microtime(true) - $started, 1);
            if ($clip === null || $clip['extension'] !== 'mp3') {
                $failed++;
                $this->error("{$example['key']}: not made".($clip !== null ? ' (this server could not encode MP3)' : ''));
                $this->line(json_encode($designer->report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

                continue;
            }
            file_put_contents($file, $clip['bytes']);
            $made++;
            $summary[] = ['example' => $example['key'], 'clip_seconds' => round((float) $clip['seconds'], 1), 'took_seconds' => $seconds, 'requests' => $designer->report];
            $this->info(sprintf('%s: %.1f s clip, made in %.1f s', $example['key'], (float) $clip['seconds'], $seconds));
        }

        // What it took, for the owner's cost figures (never the audio).
        $this->line('REPORT '.json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->info("Made {$made}, failed {$failed}.");

        return $failed > 0 && $made === 0 ? self::FAILURE : self::SUCCESS;
    }
}
