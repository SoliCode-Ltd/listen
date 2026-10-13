<?php

namespace App\Console\Commands;

use App\Models\SiteSetting;
use App\Models\User;
use App\Support\FreeAllowance;
use Illuminate\Console\Command;

/**
 * Switches off the free plan's monthly characters for everyone on it.
 * The same switch is in Admin -> Site Settings -> Free Demo.
 */
class EndFreeMonthlyCharsCommand extends Command
{
    protected $signature = 'listen:end-free-monthly-chars
        {--apply : Make the change (without it, only count who it affects)}';

    protected $description = 'Stop the free plan\'s monthly characters for everyone on the free plan.';

    public function handle(): int
    {
        $onFreePlan = User::query()
            ->whereHas('planSubscriptions', fn ($q) => $q->where('status', 'active')
                ->where(fn ($p) => $p->whereNull('current_period_end')->orWhere('current_period_end', '>=', now()))
                ->whereHas('plan', fn ($plan) => $plan->where('price_cents', '<=', 0)))
            ->count();

        $this->line("Members on the free plan: {$onFreePlan}.");
        $this->line('The free plan gives monthly characters: '.(FreeAllowance::freePlanGivesMonthlyChars() ? 'yes' : 'no'));

        if (! $this->option('apply')) {
            $this->warn('Nothing changed. Run again with --apply to switch them off.');

            return self::SUCCESS;
        }

        SiteSetting::set(FreeAllowance::NEW_MEMBERS_KEY, '0', 'general');
        SiteSetting::flushGroupCache('general');

        $this->info('Done: nobody on the free plan gets monthly characters now.');

        return self::SUCCESS;
    }
}
