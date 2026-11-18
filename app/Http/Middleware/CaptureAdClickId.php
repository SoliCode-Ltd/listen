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
     * The ChatGPT Ads click (?oppref=), kept by the site too: the ad network's pixel keeps
     * it in its own cookie, but not when the visitor's browser blocks the pixel.
     */
    public const OPPREF_COOKIE = 'listen_oppref';

    /** The ChatGPT Ads pixel's own cookies (first-party, set in the browser, not encrypted). */
    public const PIXEL_OPPREF_COOKIE = '__oppref';

    public const PIXEL_BROWSER_COOKIE = '__obref';

    /** ChatGPT Ads counts a click's conversions for 30 days. */
    public const OPPREF_MINUTES = 60 * 24 * 30;

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

        $oppref = self::cleanOppref($request->query('oppref'));
        if ($oppref !== null && self::mayKeep($request)) {
            Cookie::queue(self::OPPREF_COOKIE, $oppref, self::OPPREF_MINUTES);
        }

        return $next($request);
    }

    public static function fromRequest(?Request $request): ?string
    {
        $value = trim((string) $request?->cookie(self::COOKIE, ''));

        return $value !== '' && preg_match('/^[A-Za-z0-9_\-]{1,255}$/', $value) === 1 && self::mayKeep($request) ? $value : null;
    }

    /**
     * The ChatGPT Ads click the visitor came from: the pixel's cookie, or the site's own copy
     * when the browser blocked the pixel. Null where the consent rules apply (mayTrack).
     */
    public static function chatGptClick(?Request $request): ?string
    {
        if (! $request || ! self::mayKeep($request)) {
            return null;
        }

        return self::cleanOppref($request->cookie(self::PIXEL_OPPREF_COOKIE)) ?? self::cleanOppref($request->cookie(self::OPPREF_COOKIE));
    }

    /** An opaque value, passed on unchanged: only its size and characters are checked. */
    public static function cleanOppref(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value !== '' && strlen($value) <= 512 && preg_match('/^[\x21-\x7E]+$/', $value) === 1 ? $value : null;
    }

    /** Whether what this visitor does may be sent to an ad network without asking them first (mayKeep). */
    public static function mayTrack(?Request $request): bool
    {
        return $request !== null && self::mayKeep($request);
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
