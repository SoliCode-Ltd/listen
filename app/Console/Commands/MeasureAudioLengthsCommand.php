<?php

namespace App\Console\Commands;

use App\Models\AudioGeneration;
use App\Models\UsageCounter;
use App\Support\AudioDuration;
use Illuminate\Console\Command;

/**
 * Older generations stored a length guessed from the text. This reads each
 * saved audio file, stores its real length, and then adds up each member's
 * monthly audio seconds again from those lengths. Characters are not changed.
 */
class MeasureAudioLengthsCommand extends Command
{
    protected $signature = 'listen:measure-audio-lengths
        {--apply : Save the measured lengths (without it, only report what would change)}';

    protected $description = 'Store the real length of every generated audio file and recount monthly audio seconds.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $checked = 0;
        $changed = 0;
        $missing = 0;

        AudioGeneration::query()
            ->where('status', 'done')
            ->whereNotNull('audio_path')
            ->where('duration_measured', false)
            ->orderBy('id')
            ->chunkById(200, function ($generations) use ($apply, &$checked, &$changed, &$missing): void {
                foreach ($generations as $generation) {
                    $checked++;
                    $seconds = AudioDuration::wholeSeconds(AudioDuration::ofStoredFile($generation->audio_path));
                    if ($seconds === null) {
                        $missing++;

                        continue;
                    }
                    if ((int) $generation->duration_seconds !== $seconds) {
                        $changed++;
                    }
                    if ($apply) {
                        // Keep updated_at: this is a correction, not new activity.
                        AudioGeneration::query()->whereKey($generation->getKey())->toBase()
                            ->update(['duration_seconds' => $seconds, 'duration_measured' => true]);
                    }
                }
            });

        $this->line("Generations checked: {$checked}.");
        $this->line("Length differs from the file: {$changed}.");
        $this->line("Audio file missing or unreadable (left as is): {$missing}.");

        if (! $apply) {
            $this->warn('Nothing changed. Run again with --apply to save the measured lengths.');

            return self::SUCCESS;
        }

        $this->info("Saved the measured length for {$changed} generations.");
        $this->info('Monthly usage recounted for '.$this->recountMonthlySeconds().' member-months.');

        return self::SUCCESS;
    }

    /**
     * Each member's monthly audio seconds, added up again from their finished
     * generations (pack generations are counted in the pack, not here).
     */
    private function recountMonthlySeconds(): int
    {
        $totals = [];
        AudioGeneration::query()
            ->where('status', 'done')
            ->where(fn ($q) => $q->whereNull('billed_to_pack')->orWhere('billed_to_pack', false))
            ->whereNotNull('user_id')
            ->select(['id', 'user_id', 'created_at', 'duration_seconds'])
            ->chunkById(1000, function ($rows) use (&$totals): void {
                foreach ($rows as $row) {
                    $ym = $row->created_at?->format('Y-m');
                    if ($ym !== null) {
                        $totals[$row->user_id][$ym] = ($totals[$row->user_id][$ym] ?? 0) + max(0, (int) $row->duration_seconds);
                    }
                }
            });

        $updated = 0;
        foreach ($totals as $userId => $months) {
            foreach ($months as $ym => $seconds) {
                $updated += UsageCounter::query()
                    ->where('user_id', $userId)
                    ->where('period_ym', $ym)
                    ->toBase() // keep updated_at, the admin's "Last generation"
                    ->update(['total_seconds' => $seconds, 'total_minutes' => UsageCounter::minutesFor($seconds)]);
            }
        }

        return $updated;
    }
}
