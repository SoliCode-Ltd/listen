<?php

namespace App\Http\Middleware;

use App\Support\EmailVerification;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The free demo is spent only by an account whose email is confirmed
 * (App\Support\EmailVerification): an account made with an email and a
 * password is asked to open its link, or to send itself a new one from the
 * demo page, before anything is checked, made or charged. Google and phone
 * sign-ins are confirmed already, and paying members and pack holders never
 * wait for it.
 */
class EnsureDemoEmailVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! EmailVerification::requiredForFreeUse($user)) {
            return $next($request);
        }

        $message = __('ui.verify_email.demo_required', ['email' => (string) $user->email]);

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => false,
                'message' => $message,
                'email_verification_required' => true,
            ], 403);
        }

        return redirect()->route('demo.page')->with('verify_email_notice', ['type' => 'error', 'message' => $message]);
    }
}
