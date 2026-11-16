<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\BillingSyncService;
use App\Services\DemoHistoryImporter;
use App\Services\PlanAccessService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

use function Illuminate\Support\defer;

class SyncSubscriptionStatus
{
    public function __construct(
        private readonly BillingSyncService $billingSyncService,
        private readonly PlanAccessService $planAccessService,
        private readonly DemoHistoryImporter $demoHistoryImporter,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user) {
            $due = BillingSyncService::syncDue($user);
            if ($due === 'now') {
                // A paid period just ended: the plan this page shows depends on the renewal.
                $this->billingSyncService->syncIfStale($user, true);
            } elseif ($due === 'soon' && Cache::add('billing-sync-after-reply:'.$user->id, true, 60)) {
                // The routine check runs after the page is sent: Stripe can take
                // seconds. Once per member, however many requests the page makes.
                $userId = (int) $user->id;
                defer(function () use ($userId): void {
                    try {
                        $member = User::query()->find($userId);
                        if ($member) {
                            $this->billingSyncService->syncIfStale($member);
                        }
                    } catch (\Throwable $e) {
                        Log::warning('Billing sync after the reply failed.', ['user_id' => $userId, 'error' => $e->getMessage()]);
                    }
                }, always: true);
            }

            try {
                $activePlan = $this->planAccessService->resolveAccessPlanForUserId((int) $user->id);
                $this->demoHistoryImporter->syncStorageForPlan((int) $user->id, $activePlan, 200);
            } catch (\Throwable $e) {
                Log::warning('Failed syncing demo paid-history tags by plan state.', [
                    'user_id' => $user->id,
                    'exception_class' => get_class($e),
                    'exception_message' => $e->getMessage(),
                ]);
            }
        }

        return $next($request);
    }
}
