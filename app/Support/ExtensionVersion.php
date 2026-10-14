<?php

namespace App\Support;

use App\Models\SiteSetting;

/**
 * The extension version people get: the one in its manifest, unless an admin
 * typed a newer one in Extension settings (an older admin value never wins).
 */
final class ExtensionVersion
{
    public const MANIFEST = 'extensions/chrome-listen-reader/manifest.json';

    public static function source(): string
    {
        $manifest = json_decode((string) @file_get_contents(public_path(self::MANIFEST)), true);

        return is_array($manifest) && is_string($manifest['version'] ?? null) ? $manifest['version'] : '1.0.0';
    }

    public static function current(): string
    {
        $source = self::source();
        $admin = trim((string) SiteSetting::get('extension_chrome_version', ''));

        return $admin !== '' && preg_match('/^\d+(\.\d+){0,3}$/', $admin) && version_compare($admin, $source, '>')
            ? $admin
            : $source;
    }
}
