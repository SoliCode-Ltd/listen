<?php

namespace App\Console\Commands;

use App\Models\AudioGeneration;
use App\Services\BlockedGenerationLogger;
use App\Services\ContentSafety\TextContentCheck;
use App\Support\HiddenAudio;
use Illuminate\Console\Command;

/**
 * Reads again the texts whose content check could not be done when their voice was
 * made (the text model did not answer, so the voice was made rather than held up:
 * App\Services\ContentSafety\TextContentCheck).
 *
 * A text that is fine is marked so. A text that should have been refused has its voice
 * hidden from the member at once (App\Support\HiddenAudio: the file leaves the public
 * disk and stays for the admin) and is added to the admin's list of refused texts,
 * where it counts like any other refusal. Nothing is charged back or forth here.
 *
 * Runs every five minutes (routes/console.php), a few texts a run, and stops for this
 * run as soon as the reader does not answer again.
 */
class RecheckUncheckedTextsCommand extends Command
{
    protected $signature = 'listen:recheck-unchecked-texts {--limit=30 : Most texts to read in one run}';

    protected $description = 'Read again the texts whose content check could not be done, and hide a voice that should not have been made.';

    /** A text still unread after this long is left as it is. */
    public const DAYS = 3;

    public function handle(TextContentCheck $check, BlockedGenerationLogger $refusals): int
    {
        if (! HiddenAudio::ready() || ! TextContentCheck::enabled()) {
            return self::SUCCESS;
        }

        $waiting = AudioGeneration::query()
            ->where('content_check', TextContentCheck::UNCHECKED)
            ->where('created_at', '>=', now()->subDays(self::DAYS))
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        $allowed = 0;
        $hidden = 0;
        foreach ($waiting as $generation) {
            $ruling = $check->check($generation->input_text, $generation->style_prompt);
            if ($ruling['verdict'] === TextContentCheck::UNCHECKED) {
                $this->line('The reader did not answer; the rest waits for the next run.');
                break;
            }

            $generation->forceFill(['content_check' => $ruling['verdict']])->saveQuietly();
            if ($ruling['verdict'] === TextContentCheck::ALLOWED) {
                $allowed++;

                continue;
            }

            $made = $generation->status === 'done';
            if ($made) {
                HiddenAudio::hide($generation, null, 'Hidden automatically: the content check refused its text on a later reading.');
                $hidden++;
            }
            $refusals->logFromContentCheck($generation, $ruling, $made);
        }

        $this->line("Read again: {$allowed} allowed, {$hidden} hidden.");

        return self::SUCCESS;
    }
}
