<?php

namespace App\Console\Commands;

use App\Models\Plan;
use App\Services\StripeProductSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ExpireDiscountsCommand extends Command
{
    protected $signature = 'plans:expire-discounts';

    protected $description = 'Clear expired discounts from plans and clean up Stripe coupons';

    public function handle(StripeProductSyncService $syncService): int
    {
        $expiredPlans = Plan::query()
            ->whereNotNull('discount_percentage')
            ->where('discount_percentage', '>', 0)
            ->whereNotNull('discount_ends_at')
            ->where('discount_ends_at', '<=', now())
            ->get();

        if ($expiredPlans->isEmpty()) {
            $this->info('No expired discounts found.');
            return self::SUCCESS;
        }

        foreach ($expiredPlans as $plan) {
            $this->info("Expiring discount on plan: {$plan->name} ({$plan->slug})");

            $plan->discount_percentage = null;
            $plan->discount_ends_at = null;
            $plan->save();

            // Re-sync with Stripe to remove coupon (only the live site changes Stripe;
            // elsewhere the discount ends here only).
            if ($syncService->isConfigured() && $plan->price_cents > 0) {
                try {
                    $result = $syncService->syncPlan($plan);
                    if ($result['success']) {
                        $this->info("  Stripe coupon cleaned up for: {$plan->slug}");
                    } elseif (! empty($result['skipped'])) {
                        $this->info("  Stripe not changed for {$plan->slug}: {$result['message']}");
                    } else {
                        $this->warn("  Failed to sync Stripe for {$plan->slug}: {$result['message']}");
                        Log::warning('Discount expiry Stripe sync failed', [
                            'plan_slug' => $plan->slug,
                            'error' => $result['message'],
                        ]);
                    }
                } catch (\Exception $e) {
                    $this->warn("  Failed to sync Stripe for {$plan->slug}: {$e->getMessage()}");
                    Log::warning('Discount expiry Stripe sync failed', [
                        'plan_slug' => $plan->slug,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $this->info("Expired {$expiredPlans->count()} discount(s).");
        return self::SUCCESS;
    }
}
