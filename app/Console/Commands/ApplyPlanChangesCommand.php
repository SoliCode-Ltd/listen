<?php

namespace App\Console\Commands;

use App\Models\PlanChange;
use App\Models\User;
use App\Services\Billing\PlanChanges;
use App\Services\BillingSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Lower plans whose day has come: one paid by InstaPay or Vodafone Cash takes over
 * when the plan before it ends, and a card plan booked to follow a plan paid by hand
 * takes over once Stripe has started it (PlanChanges).
 */
class ApplyPlanChangesCommand extends Command
{
    protected $signature = 'listen:plan-changes';

    protected $description = 'Start lower plans whose day has come (paid by hand, or by card after a plan paid by hand)';

    public function handle(PlanChanges $planChanges, BillingSyncService $sync): int
    {
        $applied = $planChanges->applyDue();

        $started = 0;
        PlanChange::query()->scheduled()
            ->where('source', PlanChange::SOURCE_CARD_START)
            ->where('starts_at', '<=', now())
            ->pluck('user_id')
            ->unique()
            ->each(function (int $userId) use ($planChanges, $sync, &$started): void {
                $user = User::query()->find($userId);
                if (! $user) {
                    return;
                }
                try {
                    if ($planChanges->startDueCardPlans($user)) {
                        $started++;
                        $sync->syncIfStale($user, true);
                    }
                } catch (\Throwable $exception) {
                    Log::warning('A card plan booked to follow a plan paid by hand could not be started yet.', [
                        'user_id' => $userId,
                        'error' => $exception->getMessage(),
                    ]);
                }
            });

        $this->info("Plans paid by hand started: {$applied}. Card plans started: {$started}.");

        return self::SUCCESS;
    }
}
