<?php

namespace App\Support;

use Illuminate\Http\Request;

class RequestIdentity
{
    /**
     * Build a stable browser/request signature that survives VPN IP changes
     * and cookie clears, but still varies across different browser setups.
     *
     * Returns null when the available headers lack sufficient entropy to
     * distinguish individual browser instances (e.g. no Client Hints).
     * Without this guard, popular UA+language combos (Firefox on Windows
     * with en-US) would hash to the same value for millions of users,
     * causing false account linking.
     */
    public static function signature(Request $request): ?string
    {
        $userAgent = self::normalizeHeader($request->userAgent(), 400);
        if ($userAgent === '') {
            return null;
        }

        $acceptLanguage = self::normalizeHeader($request->header('Accept-Language', ''), 160);
        $secChUa = self::normalizeHeader($request->header('Sec-CH-UA', ''), 240);
        $secChUaPlatform = self::normalizeHeader($request->header('Sec-CH-UA-Platform', ''), 80);
        $secChUaMobile = self::normalizeHeader($request->header('Sec-CH-UA-Mobile', ''), 20);
        $secChUaArch = self::normalizeHeader($request->header('Sec-CH-UA-Arch', ''), 80);
        $secChUaPlatformVersion = self::normalizeHeader($request->header('Sec-CH-UA-Platform-Version', ''), 80);

        // Require at least one Client Hint header for sufficient entropy.
        // Without them the signature degrades to just UA + Accept-Language,
        // which collides across millions of users with popular browsers.
        $hasClientHints = ($secChUa !== '')
            || ($secChUaPlatform !== '')
            || ($secChUaMobile !== '')
            || ($secChUaArch !== '')
            || ($secChUaPlatformVersion !== '');

        if (! $hasClientHints) {
            return null;
        }

        $raw = implode('|', [
            $userAgent,
            $acceptLanguage,
            $secChUa,
            $secChUaPlatform,
            $secChUaMobile,
            $secChUaArch,
            $secChUaPlatformVersion,
        ]);

        $raw = strtolower(trim($raw));
        if ($raw === '') {
            return null;
        }

        return hash('sha256', $raw);
    }

    private static function normalizeHeader(mixed $value, int $maxLen): string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return '';
        }

        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return mb_substr($text, 0, max(1, $maxLen));
    }
}
