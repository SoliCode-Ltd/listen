<?php

namespace App\Console\Commands;

use App\Services\Newsletter\NewsletterPace;
use App\Services\Newsletter\NewsletterSender;
use Illuminate\Console\Command;

class SendQueuedNewslettersCommand extends Command
{
    protected $signature = 'newsletter:send-queued {--limit= : How many queued messages to send on this run}';

    protected $description = 'Send the next few queued newsletter messages, staying inside the Titan mailbox limits';

    public function handle(NewsletterSender $sender, NewsletterPace $pace): int
    {
        $limit = $this->option('limit');
        $limit = ($limit === null || $limit === '') ? $pace->perMinute() : (int) $limit;

        $sent = $sender->drain(max(0, $limit));
        $this->info('Sent '.$sent.' newsletter '.($sent === 1 ? 'email' : 'emails').'.');

        $reason = $pace->holdReason();
        if ($reason !== null) {
            $this->warn($reason);
        }

        return self::SUCCESS;
    }
}
