<?php

namespace App\Http\Middleware;

use App\Services\BillingSyncService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SyncSubscriptionStatus
{
    public function __construct(
        private readonly BillingSyncService $billingSyncService,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user) {
            $this->billingSyncService->syncIfStale($user);
        }

        return $next($request);
    }
}
