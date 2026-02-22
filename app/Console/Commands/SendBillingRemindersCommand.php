<?php

namespace App\Console\Commands;

use App\Services\BillingLifecycleService;
use Illuminate\Console\Command;

class SendBillingRemindersCommand extends Command
{
    protected $signature = 'listen:billing-reminders';

    protected $description = 'Send pre-renewal billing reminder emails.';

    public function handle(BillingLifecycleService $billingLifecycleService): int
    {
        $sent = $billingLifecycleService->sendPreRenewalReminders();
        $this->info("Sent {$sent} reminder emails.");

        return self::SUCCESS;
    }
}
