<?php

namespace App\Console\Commands;

use App\Models\AudioGeneration;
use App\Support\AudioDuration;
use Illuminate\Console\Command;

/**
 * Older generations stored a length guessed from the text. This reads each
 * saved audio file and stores its real length. Usage counters are not changed.
 */
class MeasureAudioLengthsCommand extends Command
{
    protected $signature = 'listen:measure-audio-lengths
        {--apply : Save the measured lengths (without it, only report what would change)}';

    protected $description = 'Store the real length of every generated audio file.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $checked = 0;
        $changed = 0;
        $missing = 0;

        AudioGeneration::query()
            ->where('status', 'done')
            ->whereNotNull('audio_path')
            ->orderBy('id')
            ->chunkById(200, function ($generations) use ($apply, &$checked, &$changed, &$missing): void {
                foreach ($generations as $generation) {
                    $checked++;
                    $seconds = AudioDuration::wholeSeconds(AudioDuration::ofStoredFile($generation->audio_path));
                    if ($seconds === null) {
                        $missing++;

                        continue;
                    }
                    if ((int) $generation->duration_seconds === $seconds) {
                        continue;
                    }
                    $changed++;
                    if ($apply) {
                        // Keep updated_at: this is a correction, not new activity.
                        AudioGeneration::query()->whereKey($generation->getKey())->update(['duration_seconds' => $seconds]);
                    }
                }
            });

        $this->line("Generations checked: {$checked}.");
        $this->line("Length differs from the file: {$changed}.");
        $this->line("Audio file missing or unreadable (left as is): {$missing}.");

        if (! $apply) {
            $this->warn('Nothing changed. Run again with --apply to save the measured lengths.');
        } else {
            $this->info("Saved the measured length for {$changed} generations.");
        }

        return self::SUCCESS;
    }
}
