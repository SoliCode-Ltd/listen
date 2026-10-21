<?php

namespace App\Console\Commands;

use App\Services\Dubbing\VoiceSeparator;
use Illuminate\Console\Command;

/**
 * Dub my video: sets up, checks or takes off the step that takes the old
 * voices out of a video's sound (App\Services\Dubbing\VoiceSeparator). The
 * settings page (Video Dubbing → Settings) has the same Install button.
 */
class DubSeparatorCommand extends Command
{
    protected $signature = 'dubs:separator {action=status : status, install or remove}';

    protected $description = 'Set up, check or remove the voice separation that dubbing uses.';

    public function handle(VoiceSeparator $separator): int
    {
        return match ($this->argument('action')) {
            'install' => $this->install($separator),
            'remove' => $this->remove($separator),
            default => $this->status(),
        };
    }

    private function status(): int
    {
        $this->line('Python 3: '.(VoiceSeparator::python() ?? 'not found'));
        $this->line('Installed: '.(VoiceSeparator::installed() ? 'yes' : 'no'));
        $this->line('Switched on in the settings: '.(VoiceSeparator::enabled() ? 'yes' : 'no'));
        if ($details = VoiceSeparator::details()) {
            $this->line('Set up: '.json_encode($details, JSON_UNESCAPED_SLASHES));
        }

        return self::SUCCESS;
    }

    private function install(VoiceSeparator $separator): int
    {
        try {
            $separator->install(fn (string $message) => $this->line($message));
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function remove(VoiceSeparator $separator): int
    {
        $separator->remove();
        $this->info('Removed: dubs use the filters again.');

        return self::SUCCESS;
    }
}
