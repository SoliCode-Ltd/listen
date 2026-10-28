<?php

namespace App\Http\Middleware;

use App\Services\LocalizedPricingService;
use App\Support\Countries;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

class CaptureAdClickId
{
    public const COOKIE = 'listen_gclid';

    /**
     * Google only accepts offline conversions within 90 days of the click.
     */
    private const MINUTES = 60 * 24 * 90;

    public function handle(Request $request, Closure $next): Response
    {
        $gclid = trim((string) $request->query('gclid', ''));
        if ($gclid !== '' && strlen($gclid) <= 255 && preg_match('/^[A-Za-z0-9_\-]+$/', $gclid) === 1 && self::mayKeep($request)) {
            Cookie::queue(self::COOKIE, $gclid, self::MINUTES);
        }

        return $next($request);
    }

    public static function fromRequest(?Request $request): ?string
    {
        $value = trim((string) $request?->cookie(self::COOKIE, ''));

        return $value !== '' && preg_match('/^[A-Za-z0-9_\-]{1,255}$/', $value) === 1 && self::mayKeep($request) ? $value : null;
    }

    /**
     * Whether the visitor's ad click may be kept and uploaded without asking them
     * first: not from where the consent rules apply (Countries::ADS_CONSENT_REQUIRED),
     * nor when their country is not known (a browser's language is not where they are).
     */
    private static function mayKeep(Request $request): bool
    {
        $context = app(LocalizedPricingService::class)->resolveCountryContext($request);
        $known = ! $context['used_fallback'] && $context['source'] !== 'accept_language';

        return $known && ! Countries::needsAdsConsent($context['country_code']);
    }
}
