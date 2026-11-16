<?php

namespace App\Http\Middleware;

use App\Services\PlanAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ensures the authenticated user has an active paid plan.
 *
 * Used to gate premium features like voice cloning.
 * Redirects free/unauthenticated users to the upgrade page.
 */
class EnsurePaidPlan
{
    public function __construct(
        private readonly PlanAccessService $planAccessService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        $plan = $this->planAccessService->resolveActivePlanForUserId($user->id);

        $isFree = ! $plan
            || strtolower(trim($plan->slug ?? 'free')) === 'free'
            || (int) $plan->price_cents === 0;

        if ($isFree) {
            if ($request->expectsJson()) {
                return response()->json([
                    'error' => __('ui.voice_cloning.upgrade_required'),
                    'upgrade_url' => route('pricing'),
                ], 403);
            }

            return redirect()->to(route('dashboard') . '#voiceCloningSection')
                ->with('upgrade_required', true);
        }

        return $next($request);
    }
}
