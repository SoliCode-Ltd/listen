<?php

namespace App\Console\Commands;

use App\Services\Analytics\BusinessStatsService;
use App\Support\LiveServer;
use Illuminate\Console\Command;

/**
 * Rebuilds the admin dashboard's numbers ahead of time, every 5 minutes
 * (routes/console.php), so no admin waits for them: the dashboard adds up every
 * voice ever made, which takes longer as the site grows. The default period
 * (the last 12 months) is the one rebuilt; the others are worked out when chosen.
 *
 * On the live site only: the numbers include a read from Stripe (at most once
 * every ten minutes, as when an admin opens the page), and staging runs the live
 * keys, so staging works them out only when an admin opens its dashboard.
 */
class WarmBusinessStatsCommand extends Command
{
    protected $signature = 'listen:warm-business-stats';

    protected $description = "Rebuild the admin dashboard's numbers ahead of time (live site only).";

    public function handle(BusinessStatsService $stats): int
    {
        if (! LiveServer::allowsCustomerActions()) {
            $this->line('Not the live site: the dashboard is worked out when an admin opens it (it reads Stripe).');

            return self::SUCCESS;
        }

        $started = microtime(true);
        $stats->refresh(BusinessStatsService::DEFAULT_MONTHS);
        $this->line(sprintf('Dashboard numbers for the last %d months, in %.1f s.', BusinessStatsService::DEFAULT_MONTHS, microtime(true) - $started));

        return self::SUCCESS;
    }
}
