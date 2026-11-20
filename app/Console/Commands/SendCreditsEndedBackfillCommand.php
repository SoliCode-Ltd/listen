<?php

namespace App\Console\Commands;

use App\Services\Newsletter\CreditsEndedEmail;
use App\Services\Newsletter\NewsletterPace;
use Illuminate\Console\Command;

/**
 * Every minute while the owner's send to members who already ran out is going
 * (Newsletter → Credits ended): the next few emails, at the newsletter's pace,
 * and none while the mailbox is held or a newsletter batch is going out, so the
 * mailbox never gets more than it can take.
 */
class SendCreditsEndedBackfillCommand extends Command
{
    protected $signature = 'listen:credits-ended-backfill';

    protected $description = 'Send the credits-ended email to the next few members who already ran out.';

    public function handle(CreditsEndedEmail $email, NewsletterPace $pace): int
    {
        $state = $email->backfill();
        if ($state === null || $state['ids'] === []) {
            return self::SUCCESS;
        }

        if (($hold = $pace->holdReason()) !== null) {
            $this->line('Waiting: '.$hold);

            return self::SUCCESS;
        }
        if ($pace->queued() > 0) {
            $this->line('Waiting: a newsletter batch is going out.');

            return self::SUCCESS;
        }

        $result = $email->sendBackfillBatch($pace->perMinute());
        $this->info("Sent {$result['sent']}, left out {$result['skipped']}, {$result['waiting']} waiting.");

        return self::SUCCESS;
    }
}
