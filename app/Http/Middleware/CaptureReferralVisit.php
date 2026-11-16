<?php

namespace App\Http\Middleware;

use App\Services\Referral\ReferralService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * When a GET request carries `?ref=CODE` on the demo page, persist the code
 * in a signed cookie so that if the visitor later registers, we can bind the
 * referral relationship.
 *
 * Also logs a visit row (idempotent) for analytics. No-op when the code is
 * missing, unknown, or the visitor is authenticated as the referrer.
 */
class CaptureReferralVisit
{
    public const COOKIE_NAME = 'listen_ref_code';
    public const COOKIE_TTL_MIN = 60 * 24 * 45; // 45 days

    public function __construct(private readonly ReferralService $referralService) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET')) {
            $code = trim((string) $request->query('ref', ''));
            if ($code !== '' && strlen($code) <= 32) {
                try {
                    $visit = $this->referralService->trackVisit($request, $code);
                    if ($visit) {
                        Cookie::queue(
                            self::COOKIE_NAME,
                            strtoupper($code),
                            self::COOKIE_TTL_MIN,
                            '/',
                            null,
                            $request->secure(),
                            true,
                            false,
                            'lax'
                        );
                    }
                } catch (\Throwable $e) {
                    Log::warning('Referral visit tracking failed.', [
                        'exception' => $e->getMessage(),
                    ]);
                }
            }
        }

        return $next($request);
    }
}
