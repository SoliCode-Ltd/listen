<?php

namespace App\Console\Commands;

use App\Services\Newsletter\NewsletterPace;
use App\Services\Newsletter\NewsletterSender;
use Illuminate\Console\Command;

class SendNewsletterBatchCommand extends Command
{
    protected $signature = 'newsletter:work {--kick : Start the background sender and return} {--once : Send one slice and exit}';

    protected $description = 'Send queued newsletter messages in the background, staying inside the Titan mailbox limits';

    public function handle(NewsletterSender $sender, NewsletterPace $pace): int
    {
        if ($this->option('kick')) {
            if ($sender->ensureRunning()) {
                $this->info('Newsletter sender is running.');

                return self::SUCCESS;
            }

            $sent = $sender->drain($pace->perMinute());
            $this->info('Sent '.$sent.' newsletter '.($sent === 1 ? 'email' : 'emails').'.');

            return self::SUCCESS;
        }

        if (! $sender->claimRunner()) {
            $this->info('Newsletter sender is already running.');

            return self::SUCCESS;
        }

        try {
            $sender->refreshRunner();

            while ($pace->queued() > 0 && $pace->holdReason() === null) {
                $sender->refreshRunner();
                $sent = $sender->drain($pace->perMinute());
                $this->info('Sent '.$sent.' newsletter '.($sent === 1 ? 'email' : 'emails').'.');

                $reason = $pace->holdReason();
                if ($reason !== null) {
                    $this->warn($reason);
                    break;
                }

                if ($this->option('once') || app()->environment('testing')) {
                    break;
                }

                sleep(60);
            }
        } finally {
            $sender->releaseRunner();
        }

        return self::SUCCESS;
    }
}
