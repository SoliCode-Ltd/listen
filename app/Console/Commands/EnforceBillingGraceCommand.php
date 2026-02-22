<?php

namespace App\Console\Commands;

use App\Services\BillingLifecycleService;
use Illuminate\Console\Command;

class EnforceBillingGraceCommand extends Command
{
    protected $signature = 'listen:billing-enforce-grace';

    protected $description = 'Downgrade overdue accounts after billing grace period.';

    public function handle(BillingLifecycleService $billingLifecycleService): int
    {
        $count = $billingLifecycleService->downgradeExpiredGraceSubscriptions();
        $this->info("Downgraded {$count} overdue accounts.");

        return self::SUCCESS;
    }
}
