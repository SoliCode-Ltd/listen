<?php

namespace App\Http\Middleware;

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
        if ($gclid !== '' && strlen($gclid) <= 255 && preg_match('/^[A-Za-z0-9_\-]+$/', $gclid) === 1) {
            Cookie::queue(self::COOKIE, $gclid, self::MINUTES);
        }

        return $next($request);
    }

    public static function fromRequest(?Request $request): ?string
    {
        $value = trim((string) $request?->cookie(self::COOKIE, ''));

        return $value !== '' && preg_match('/^[A-Za-z0-9_\-]{1,255}$/', $value) === 1 ? $value : null;
    }
}
