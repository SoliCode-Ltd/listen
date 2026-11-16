<?php

namespace App\Http\Middleware;

use App\Services\BlockedUserService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsNotBlocked
{
    public function __construct(
        private readonly BlockedUserService $blockedUserService,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $this->blockedUserService->isBlocked($user)) {
            return $next($request);
        }

        $message = $this->blockedUserService->messageFor($user);

        Auth::logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => false,
                'message' => $message,
            ], 403);
        }

        return redirect()
            ->route('login')
            ->with('blocked_notice', $this->blockedUserService->noticePayload($user));
    }
}
