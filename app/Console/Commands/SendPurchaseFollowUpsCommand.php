<?php

namespace App\Console\Commands;

use App\Services\Billing\PurchaseFollowUps;
use Illuminate\Console\Command;

/**
 * Members who nearly bought a plan (a declined first payment, or a checkout left without
 * paying): notes who bought since, then sends the follow-up emails that are due
 * (PurchaseFollowUps::run, Newsletter → Purchase follow-ups).
 */
class SendPurchaseFollowUpsCommand extends Command
{
    protected $signature = 'listen:purchase-follow-ups';

    protected $description = 'Send the follow-up emails due after a declined first payment or a checkout left without paying';

    public function handle(PurchaseFollowUps $followUps): int
    {
        $done = $followUps->run();
        if (array_sum($done) > 0) {
            $this->info("Paid since: {$done['paid']}. Emails sent: {$done['sent']}, not sent: {$done['skipped']}, failed: {$done['failed']}.");
        }

        return self::SUCCESS;
    }
}
