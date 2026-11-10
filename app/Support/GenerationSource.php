<?php

namespace App\Support;

use App\Support\InApp\InApp;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Where a generation was asked for, as its text item records it
 * (text_items.source_type): the studio on the website, the studio inside
 * the Android app, the browser extension, the developer API, or an AI app
 * through the MCP server. The admin's
 * generations list, its monthly usage and the error log read it.
 */
final class GenerationSource
{
    /** The studio saves its texts as 'message'. */
    public const STUDIO = 'message';

    /** The same studio, opened inside the Android app (App\Support\InApp\InApp). */
    public const APP = 'app';

    public const EXTENSION = 'extension';

    public const API = 'api';

    public const MCP = 'mcp';

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            self::STUDIO => 'Studio',
            self::APP => 'Mobile app',
            self::EXTENSION => 'Browser extension',
            self::API => 'Developer API',
            self::MCP => 'AI app (MCP)',
        ];
    }

    public static function label(?string $source): string
    {
        return self::labels()[(string) $source] ?? (filled($source) ? Str::headline((string) $source) : 'Not recorded');
    }

    /** The badge colour in the admin. */
    public static function color(?string $source): string
    {
        return match ($source) {
            self::EXTENSION => 'info',
            self::API => 'warning',
            self::MCP => 'success',
            self::APP => 'primary',
            default => 'gray',
        };
    }

    /** The studio's own source for a request: the app's when it comes from inside the Android app. */
    public static function studio(?Request $request = null): string
    {
        return InApp::fromApp($request) ? self::APP : self::STUDIO;
    }

    /** Made in the studio, on the website or in the app. */
    public static function isStudio(?string $source): bool
    {
        return in_array($source, [self::STUDIO, self::APP], true);
    }
}
