<?php

namespace App\Console\Commands;

use App\Models\Plan;
use App\Services\StripeProductSyncService;
use Illuminate\Console\Command;

class SyncStripePlansCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'stripe:sync-plans 
                            {--plan= : Sync a specific plan by slug}
                            {--all : Sync all active paid plans}
                            {--force : Force sync even if already synced}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync plans to Stripe (create products and prices)';

    /**
     * Execute the console command.
     */
    public function handle(StripeProductSyncService $syncService): int
    {
        // Check Stripe configuration
        if (!$syncService->isConfigured()) {
            $this->error('Stripe is not configured. Please set STRIPE_SECRET in your .env file.');
            return Command::FAILURE;
        }

        $this->info('🔄 Stripe Plan Sync');
        $this->newLine();

        // Sync a specific plan
        if ($planSlug = $this->option('plan')) {
            $plan = Plan::where('slug', $planSlug)->first();
            
            if (!$plan) {
                $this->error("Plan '{$planSlug}' not found.");
                return Command::FAILURE;
            }

            return $this->syncSinglePlan($plan, $syncService);
        }

        // Sync all plans
        if ($this->option('all')) {
            return $this->syncAllPlans($syncService);
        }

        // Interactive mode - list plans and let user choose
        return $this->interactiveSync($syncService);
    }

    private function syncSinglePlan(Plan $plan, StripeProductSyncService $syncService): int
    {
        $this->info("Syncing plan: {$plan->name} ({$plan->slug})");
        
        if ($plan->price_cents <= 0) {
            $this->warn("Skipping free plan '{$plan->name}'");
            return Command::SUCCESS;
        }

        $result = $syncService->syncPlan($plan);

        if ($result['success']) {
            $this->newLine();
            $this->info('✅ ' . $result['message']);
            $this->table(
                ['Field', 'Value'],
                [
                    ['Product ID', $result['product_id'] ?? '-'],
                    ['Monthly Price ID', $result['price_monthly'] ?? '-'],
                    ['Yearly Price ID', $result['price_yearly'] ?? '-'],
                ]
            );
            return Command::SUCCESS;
        }

        $this->error('❌ ' . $result['message']);
        return Command::FAILURE;
    }

    private function syncAllPlans(StripeProductSyncService $syncService): int
    {
        $result = $syncService->syncAllPlans();

        $this->newLine();
        $this->info("✅ Synced: {$result['synced']}");
        
        if ($result['failed'] > 0) {
            $this->warn("❌ Failed: {$result['failed']}");
            foreach ($result['errors'] as $slug => $error) {
                $this->error("  - {$slug}: {$error}");
            }
        }

        // Show current state
        $this->newLine();
        $this->info('📋 Plan Stripe Status:');
        $plans = Plan::query()->subscriptionPlans()->where('is_active', true)->get();
        
        $tableData = $plans->map(fn ($p) => [
            $p->slug,
            $p->price_cents <= 0 ? 'Free' : '$' . number_format($p->price_cents / 100, 2),
            $p->stripe_product_id ? '✅' : '❌',
            $p->stripe_price_monthly ?: '-',
            $p->stripe_price_yearly ?: '-',
        ])->toArray();

        $this->table(
            ['Plan', 'Price', 'Synced', 'Monthly Price ID', 'Yearly Price ID'],
            $tableData
        );

        return $result['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function interactiveSync(StripeProductSyncService $syncService): int
    {
        $plans = Plan::query()->subscriptionPlans()->where('is_active', true)
            ->where('price_cents', '>', 0)
            ->get();

        if ($plans->isEmpty()) {
            $this->warn('No paid plans found to sync.');
            return Command::SUCCESS;
        }

        $this->info('📋 Available Plans:');
        $tableData = $plans->map(fn ($p) => [
            $p->slug,
            $p->name,
            '$' . number_format($p->price_cents / 100, 2),
            $p->hasStripeConfig() ? '✅ Synced' : '❌ Not synced',
        ])->toArray();
        
        $this->table(['Slug', 'Name', 'Price', 'Status'], $tableData);
        $this->newLine();

        $choice = $this->choice(
            'What would you like to do?',
            ['Sync all plans', 'Cancel'],
            0
        );

        if ($choice === 'Cancel') {
            $this->info('Cancelled.');
            return Command::SUCCESS;
        }

        return $this->syncAllPlans($syncService);
    }
}
