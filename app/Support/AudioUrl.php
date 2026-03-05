<?php

namespace App\Support;

class AudioUrl
{
    /**
     * Always route through the /media/ endpoint so that CORS headers
     * are set by PHP (required for the Chrome extension to fetch audio).
     */
    public static function fromPath(?string $audioPath): ?string
    {
        $audioPath = ltrim(trim((string) $audioPath), '/');
        if ($audioPath === '') {
            return null;
        }

        // Use relative URLs so audio keeps working across local domains,
        // tunnels, and reverse proxies even when APP_URL is different.
        return route('media.file', ['path' => $audioPath], false);
    }
}
