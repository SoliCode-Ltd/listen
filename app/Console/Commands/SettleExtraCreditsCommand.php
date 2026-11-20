<?php

namespace App\Console\Commands;

use App\Models\UsageCounter;
use App\Services\Billing\ExtraCredits;
use Illuminate\Console\Command;

/**
 * Extra credits left when a member's period ends move on to the next one if
 * the plan went on, or end with it (ExtraCredits::settle()). A member's own
 * visit does this at once; this catches everyone who has not come back, and
 * keeps the plan's own credits beside them up to date.
 */
class SettleExtraCreditsCommand extends Command
{
    protected $signature = 'listen:extra-credits-settle';

    protected $description = 'Move extra credits on to the next period, or end them with the plan.';

    public function handle(ExtraCredits $extraCredits): int
    {
        if (! ExtraCredits::ready()) {
            $this->info('Extra credits are not set up yet (run the migrations).');

            return self::SUCCESS;
        }

        $members = UsageCounter::query()
            ->where('extra_chars', '>', 0)
            ->whereNull('extra_settled_at')
            ->distinct()
            ->pluck('user_id');

        $settled = 0;
        foreach ($members as $userId) {
            try {
                $settled += $extraCredits->settle((int) $userId) ? 1 : 0;
                $extraCredits->refreshBase((int) $userId);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $this->info("Checked {$members->count()} members with extra credits; settled {$settled}.");

        return self::SUCCESS;
    }
}
