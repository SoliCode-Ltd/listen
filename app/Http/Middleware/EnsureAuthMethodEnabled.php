<?php

namespace App\Http\Middleware;

use App\Services\Auth\AuthSettingsService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAuthMethodEnabled
{
    public function __construct(
        private readonly AuthSettingsService $authSettings,
    ) {
    }

    public function handle(Request $request, Closure $next, string $method): Response
    {
        if ($this->authSettings->methodEnabled($method)) {
            return $next($request);
        }

        $message = $this->messageFor($method);

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => false,
                'message' => $message,
            ], 403);
        }

        $route = $request->routeIs('register*') ? 'register' : 'login';

        return redirect()
            ->route($route)
            ->withErrors(['auth' => $message]);
    }

    private function messageFor(string $method): string
    {
        return match (strtolower(trim($method))) {
            'email' => __('ui.backend.auth_email_disabled'),
            'google' => __('ui.backend.auth_google_disabled'),
            'phone' => __('ui.backend.auth_phone_disabled'),
            'firebase_google' => __('ui.backend.auth_firebase_google_disabled'),
            default => __('ui.backend.auth_method_disabled'),
        };
    }
}

