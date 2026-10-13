<?php

namespace App\Support;

/**
 * A public file's URL with its change time attached (?v=...), so browsers and
 * caches fetch it again whenever the file changes instead of keeping an old copy.
 */
final class VersionedAsset
{
    public static function url(string $path): string
    {
        $file = public_path($path);
        $version = is_file($file) ? (string) filemtime($file) : null;

        return asset($path).($version ? '?v='.$version : '');
    }
}
