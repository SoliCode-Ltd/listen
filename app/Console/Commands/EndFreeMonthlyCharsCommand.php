<?php

namespace App\Console\Commands;

use App\Models\SiteSetting;
use App\Models\User;
use App\Support\FreeAllowance;
use Illuminate\Console\Command;

/**
 * Moves every member to the new free plan: no monthly characters, only the
 * demo, with plans offered in the dashboard. Counts by default; --apply does it.
 * Paid members are not affected while they pay; admin accounts are skipped.
 */
class EndFreeMonthlyCharsCommand extends Command
{
    protected $signature = 'listen:end-free-monthly-chars
        {--apply : Make the change (without it, only count who it affects)}';

    protected $description = 'Stop the free plan\'s monthly characters for existing members and new sign-ups.';

    public function handle(): int
    {
        $affected = User::query()->where('is_admin', false)->where('free_monthly_chars', true);
        $count = (clone $affected)->count();
        $onFreePlan = (clone $affected)
            ->whereHas('planSubscriptions', fn ($q) => $q->where('status', 'active')
                ->where(fn ($p) => $p->whereNull('current_period_end')->orWhere('current_period_end', '>=', now()))
                ->whereHas('plan', fn ($plan) => $plan->where('price_cents', '<=', 0)))
            ->count();

        $this->line("Members who still get free monthly characters: {$count} ({$onFreePlan} of them on the free plan now).");
        $this->line('New sign-ups get them: '.(FreeAllowance::newMembersGetMonthlyChars() ? 'yes' : 'no'));

        if (! $this->option('apply')) {
            $this->warn('Nothing changed. Run again with --apply to stop free monthly characters for all of them and for new sign-ups.');

            return self::SUCCESS;
        }

        SiteSetting::set(FreeAllowance::NEW_MEMBERS_KEY, '0', 'general');
        SiteSetting::flushGroupCache('general');
        $updated = $affected->update(['free_monthly_chars' => false]);

        $this->info("Done: {$updated} members no longer get free monthly characters, and new sign-ups won't either.");

        return self::SUCCESS;
    }
}
