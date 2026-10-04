<?php

namespace App\Support;

use App\Models\SiteSetting;
use Illuminate\Http\Request;
use Throwable;

/**
 * Which visual design the public site renders: the original "main" design or the
 * opt-in "new" design. Admins pick the design for everyone in Site Settings, and can
 * preview the other one for themselves with ?design=new / ?design=main (?design=reset
 * clears the preview).
 *
 * The new design is a stylesheet layered on top of the main one. When the main design
 * is active nothing extra is rendered, so pages stay exactly as they were.
 */
final class SiteDesign
{
    public const MAIN = 'main';

    public const NEW = 'new';

    public const SETTING_KEY = 'site_design';

    public const PREVIEW_SESSION_KEY = 'site_design_preview';

    public const PREVIEW_QUERY_KEY = 'design';

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::MAIN => 'Main theme (current design)',
            self::NEW => 'New theme',
        ];
    }

    /**
     * The design chosen in Site Settings for all visitors.
     */
    public static function configured(): string
    {
        try {
            $value = (string) SiteSetting::get(self::SETTING_KEY, self::MAIN);
        } catch (Throwable) {
            return self::MAIN;
        }

        return self::normalize($value) ?? self::MAIN;
    }

    /**
     * The design for the current request: an admin's preview if one is active,
     * otherwise the configured design.
     */
    public static function current(?Request $request = null): string
    {
        return self::preview($request) ?? self::configured();
    }

    public static function isNew(?Request $request = null): bool
    {
        return self::current($request) === self::NEW;
    }

    /**
     * The admin preview for this request, if any. A ?design= query from an admin
     * updates the preview stored in their session.
     */
    public static function preview(?Request $request = null): ?string
    {
        $request ??= request();

        if (! $request->hasSession()) {
            return null;
        }

        try {
            $user = $request->user();
        } catch (Throwable) {
            return null;
        }

        if (! $user || ($user->is_admin ?? false) !== true) {
            return null;
        }

        $session = $request->session();
        $query = $request->query(self::PREVIEW_QUERY_KEY);

        if (is_string($query)) {
            $query = strtolower(trim($query));

            if ($query === 'reset') {
                $session->forget(self::PREVIEW_SESSION_KEY);
            } elseif (($design = self::normalize($query)) !== null) {
                $session->put(self::PREVIEW_SESSION_KEY, $design);
            }
        }

        return self::normalize((string) $session->get(self::PREVIEW_SESSION_KEY, ''));
    }

    /**
     * True when an admin is looking at a design other than the one visitors see.
     */
    public static function isPreviewing(?Request $request = null): bool
    {
        $preview = self::preview($request);

        return $preview !== null && $preview !== self::configured();
    }

    private static function normalize(string $value): ?string
    {
        $value = strtolower(trim($value));

        return in_array($value, [self::MAIN, self::NEW], true) ? $value : null;
    }
}
