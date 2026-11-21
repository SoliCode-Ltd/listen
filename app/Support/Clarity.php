<?php

namespace App\Support;

use App\Http\Middleware\CaptureAdClickId;
use App\Models\SiteSetting;
use App\Support\InApp\InApp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Visit recordings and heatmaps (Microsoft Clarity, SEO Settings → Visit recordings), on the
 * live site only (LiveServer: staging runs a copy of live and must not mix its visits in):
 * - the public pages, for visitors not signed in: the home page, pricing, the voice samples,
 *   the voice agents page, the feature, dialect and accent pages, the developer docs, the
 *   legal pages, contact, sign-in and sign-up;
 * - the free demo, for the members trying it (only members without a paid plan reach it,
 *   EnsureFreeDemoAccess), the team excepted;
 * - never the studio, the account or billing pages, nor inside the Listen app (it declares no
 *   trackers to its stores);
 * - never for visitors from where the consent rules apply or whose country is not known, the
 *   same rule as the ad clicks (CaptureAdClickId::mayTrack). Cloudflare gives most public pages
 *   to everyone alike, so the page asks the server (VisitCheckController) before it loads the
 *   recorder; nor when the visitor turned recordings off (the cookie policy) or their browser
 *   asks sites not to track them.
 * The recorder hides what is typed into form fields, e-mail addresses and numbers itself; the
 * member's name in the header is hidden too (data-clarity-mask). Its terms ask the privacy
 * notice to name Microsoft, Microsoft Advertising and Microsoft's privacy statement, which the
 * privacy and cookie policies do.
 */
final class Clarity
{
    public const ENABLED = 'clarity_enabled';

    public const PROJECT = 'clarity_project_id';

    public const GROUP = 'seo';

    /** The browser's own switch, set on the cookie policy page (resources/views/legal/cookies.blade.php). */
    public const OFF_KEY = 'listen_no_recording';

    /** Pages for visitors not signed in (route names as Request::routeIs takes them). */
    public const PUBLIC_PAGES = [...PublicPageCache::PAGES, 'pricing', 'contact', 'login', 'register'];

    /** Pages for signed-in members: the free demo. */
    public const MEMBER_PAGES = ['demo.page'];

    /** Both settings, kept together so a page reads them with one cache look-up (SiteSetting::set forgets it). */
    public const CACHE = 'clarity.settings';

    /** The Clarity project's ID (the short code at the end of its install code), when it looks like one. */
    public static function projectId(): ?string
    {
        $id = trim((string) (self::settings()[self::PROJECT] ?? ''));

        return preg_match('/^[a-z0-9]{6,20}$/i', $id) === 1 ? $id : null;
    }

    /** Switched on, with a project, on the live site. */
    public static function enabled(): bool
    {
        return LiveServer::allowsCustomerActions()
            && (string) (self::settings()[self::ENABLED] ?? '0') === '1'
            && self::projectId() !== null;
    }

    /**
     * The project to load on this page, or null. The page's own checks come first and cost nothing, so a
     * page that never records (the studio, account and billing pages) reads no setting at all.
     */
    public static function forPage(Request $request): ?string
    {
        if ($request->route() === null) {
            return null;
        }

        $user = $request->user();
        $allowed = $user === null
            ? $request->routeIs(...self::PUBLIC_PAGES)
            : ! $user->is_admin && $request->routeIs(...self::MEMBER_PAGES);

        if (! $allowed || InApp::active($request) || ! self::enabled()) {
            return null;
        }

        return self::projectId();
    }

    /** Whether this page may load the recorder (the visitor's country is asked for by the page). */
    public static function offeredOn(Request $request): bool
    {
        return self::forPage($request) !== null;
    }

    /** @return array<string, string> */
    private static function settings(): array
    {
        return Cache::remember(self::CACHE, 3600, fn (): array => SiteSetting::query()
            ->whereIn('key', [self::ENABLED, self::PROJECT])
            ->pluck('value', 'key')
            ->map(fn ($value): string => (string) $value)
            ->all());
    }

    /** Whether this visitor may be recorded: their country is known and not where the consent rules apply. */
    public static function mayRecord(Request $request): bool
    {
        return self::enabled() && CaptureAdClickId::mayTrack($request);
    }
}
