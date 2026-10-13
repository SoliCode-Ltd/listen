<?php

namespace App\Http\Middleware;

use App\Services\BillingSyncService;
use App\Services\DemoHistoryImporter;
use App\Services\PlanAccessService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

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
            $this->billingSyncService->syncIfStale($user);

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
