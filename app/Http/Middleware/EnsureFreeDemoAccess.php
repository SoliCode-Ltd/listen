<?php

namespace App\Http\Middleware;

use App\Services\PlanAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureFreeDemoAccess
{
    public function __construct(
        private readonly PlanAccessService $planAccessService,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        $activePlan = $this->planAccessService->resolveActivePlanForUserId((int) $user->id);
        $planSlug = strtolower(trim((string) ($activePlan?->slug ?? 'free')));

        if ($planSlug !== 'free') {
            if ($request->expectsJson()) {
                return response()->json([
                    'ok' => false,
                    'message' => __('ui.backend.demo_paid_plan_only'),
                    'redirect_url' => route('dashboard'),
                ], 403);
            }

            return redirect()->route('dashboard');
        }

        return $next($request);
    }
}
