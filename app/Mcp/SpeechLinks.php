<?php

namespace App\Mcp;

use App\Support\AudioFormats;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * Links to a member's finished speech that anyone holding them can play or
 * download, for a week: the MCP server hands them to the AI assistant, which
 * shows them to the person. A link is signed, so it cannot be changed to
 * reach another recording, and a new one can be asked for at any time.
 */
final class SpeechLinks
{
    public const DAYS = 7;

    /** @return array{url: string, expires_at: string} */
    public static function sign(int $historyItemId): array
    {
        $expires = Carbon::now()->addDays(self::DAYS);

        return [
            'url' => URL::temporarySignedRoute('mcp.audio', $expires, ['generation' => $historyItemId]),
            'expires_at' => $expires->toIso8601String(),
        ];
    }

    /**
     * A history item as the developer API shows it (ApiPresenter::historyItem),
     * with a link anyone can open instead of the API's audio address (which
     * needs the key).
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public static function withLink(array $item): array
    {
        $link = ($item['audio_url'] ?? null) !== null ? self::sign((int) $item['history_item_id']) : null;
        $item['audio_url'] = $link['url'] ?? null;
        $item['audio_url_expires_at'] = $link['expires_at'] ?? null;

        return $item;
    }

    /** The file's media type, from its stored name. */
    public static function mimeType(?string $path): string
    {
        return AudioFormats::ALL[self::extension($path)]['mime'];
    }

    /** What the file is called when it is downloaded: listen-123.mp3 */
    public static function fileName(int $historyItemId, ?string $path): string
    {
        return 'listen-'.$historyItemId.'.'.self::extension($path);
    }

    private static function extension(?string $path): string
    {
        $extension = strtolower(pathinfo((string) $path, PATHINFO_EXTENSION));

        return isset(AudioFormats::ALL[$extension]) ? $extension : 'mp3';
    }
}
