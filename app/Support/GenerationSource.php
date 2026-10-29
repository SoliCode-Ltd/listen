<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Where a generation was asked for, as its text item records it
 * (text_items.source_type): the studio, the browser extension, the
 * developer API, or an AI app through the MCP server. The admin's
 * generations list, its monthly usage and the error log read it.
 */
final class GenerationSource
{
    /** The studio saves its texts as 'message'. */
    public const STUDIO = 'message';

    public const EXTENSION = 'extension';

    public const API = 'api';

    public const MCP = 'mcp';

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            self::STUDIO => 'Studio',
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
            default => 'gray',
        };
    }
}
