<?php

namespace App\Console\Commands;

use App\Services\BillingSyncService;
use Illuminate\Console\Command;

class SyncBillingSubscriptionsCommand extends Command
{
    protected $signature = 'listen:billing-sync';

    protected $description = 'Sync subscription status from Stripe without webhooks.';

    public function handle(BillingSyncService $billingSyncService): int
    {
        $count = $billingSyncService->syncActiveSubscribers();

        $this->info("Synced {$count} subscriber records.");

        return self::SUCCESS;
    }
}
