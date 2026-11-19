<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

/**
 * The plain address (https://listenai.studio/) only sends the visitor on to /ar or /en.
 * For a visitor the site does not know yet, that answer is worked out here, before the app
 * starts (public/index.php), in a few milliseconds instead of a full page's time. The
 * language is the one the app would choose:
 * - the one the visitor last read the site in (the listen_locale cookie the pages set; the
 *   app goes by it first too, App\Http\Middleware\RememberedLocale);
 * - else the site's main language (SEO Settings), as the app sets it.
 *
 * Anything else goes on to the app as before: a visitor with a session (their language is
 * in it), "remember me" or the Listen app's cookie or name, another host (www. is moved to
 * the main address by the app), another address or method, or a site whose settings file
 * (written by the app, rememberSettings()) is not there yet. The query string goes along.
 */
final class HomeRedirect
{
    public const LOCALE_COOKIE = 'listen_locale';

    public const LOCALES = ['ar', 'en'];

    /** Says the answer came from here, to check it from outside. */
    public const HEADER = 'X-Home-Redirect';

    /** The Listen app's name in its user agent, and its cookies (App\Support\InApp\InApp). */
    private const APP_UA = 'ListenApp';

    private const APP_COOKIES = ['listen_app', 'listen_app_lang'];

    public static function settingsFile(string $basePath): string
    {
        return rtrim($basePath, '/').'/storage/framework/home-redirect.json';
    }

    /** From public/index.php: answers and returns true, or returns false for the app to answer. */
    public static function handle(string $basePath): bool
    {
        $settings = self::settings($basePath);
        $location = $settings === null ? null : self::location($_SERVER, $_COOKIE, $settings);
        if ($location === null) {
            return false;
        }

        http_response_code(302);
        foreach (self::headers($location, $_SERVER) as $name => $value) {
            header($name.': '.$value);
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
            echo '<!DOCTYPE html><meta charset="UTF-8"><meta http-equiv="refresh" content="0;url='.htmlspecialchars($location, ENT_QUOTES).'"><title>Redirecting</title>';
        }

        return true;
    }

    /**
     * Where to send this visitor, or null for the app to decide.
     *
     * @param  array<string, mixed>  $server
     * @param  array<string, mixed>  $cookies
     * @param  array{url: string, session_cookie: string, language: string}  $settings
     */
    public static function location(array $server, array $cookies, array $settings): ?string
    {
        $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
        $uri = (string) ($server['REQUEST_URI'] ?? '');
        if (! in_array($method, ['GET', 'HEAD'], true) || parse_url($uri, PHP_URL_PATH) !== '/') {
            return null;
        }

        $url = rtrim((string) $settings['url'], '/');
        $siteHost = strtolower((string) parse_url($url, PHP_URL_HOST));
        $host = strtolower(preg_replace('/:\d+$/', '', (string) ($server['HTTP_HOST'] ?? '')) ?? '');
        if ($siteHost === '' || $host !== $siteHost) {
            return null;
        }

        foreach (array_keys($cookies) as $name) {
            $name = (string) $name;
            if ($name === $settings['session_cookie'] || str_starts_with($name, 'remember_') || in_array($name, self::APP_COOKIES, true)) {
                return null;
            }
        }
        if (str_contains((string) ($server['HTTP_USER_AGENT'] ?? ''), self::APP_UA)) {
            return null;
        }

        $locale = self::locale($cookies[self::LOCALE_COOKIE] ?? null) ?? self::locale($settings['language']);
        if ($locale === null) {
            return null;
        }

        $query = SymfonyRequest::normalizeQueryString((string) ($server['QUERY_STRING'] ?? ''));

        return $url.'/'.$locale.($query !== '' ? '?'.$query : '');
    }

    /**
     * As the app's redirect: not kept by any cache (the answer depends on the visitor),
     * with the site's usual security headers.
     *
     * @param  array<string, mixed>  $server
     * @return array<string, string>
     */
    public static function headers(string $location, array $server): array
    {
        $headers = [
            'Location' => $location,
            'Cache-Control' => 'no-cache, private',
            'Vary' => 'Accept-Language, Cookie',
            'X-Frame-Options' => 'SAMEORIGIN',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            self::HEADER => 'light',
        ];
        $https = strtolower((string) ($server['HTTPS'] ?? '')) === 'on' || strtolower((string) ($server['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        if ($https) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000';
        }

        return $headers;
    }

    /**
     * What the check needs from the app, kept in a small file the app writes when it changes
     * (AppServiceProvider): the site's address, the session cookie's name and the main language.
     *
     * @param  array{url: string, session_cookie: string, language: string}  $settings
     */
    public static function rememberSettings(string $basePath, array $settings): void
    {
        $file = self::settingsFile($basePath);
        $json = (string) json_encode($settings, JSON_UNESCAPED_SLASHES);
        if (@file_get_contents($file) === $json) {
            return;
        }
        $temporary = $file.'.'.getmypid().'.tmp';
        if (@file_put_contents($temporary, $json) !== false) {
            @rename($temporary, $file);
        }
    }

    /** @return array{url: string, session_cookie: string, language: string}|null */
    public static function settings(string $basePath): ?array
    {
        $raw = @file_get_contents(self::settingsFile($basePath));
        $settings = is_string($raw) ? json_decode($raw, true) : null;
        if (! is_array($settings) || ! is_string($settings['url'] ?? null) || ! is_string($settings['session_cookie'] ?? null)
            || $settings['session_cookie'] === '' || ! is_string($settings['language'] ?? null)) {
            return null;
        }

        return ['url' => $settings['url'], 'session_cookie' => $settings['session_cookie'], 'language' => $settings['language']];
    }

    public static function locale(mixed $value): ?string
    {
        return is_string($value) && in_array($value, self::LOCALES, true) ? $value : null;
    }
}
