<?php

namespace App\Mcp;

use App\Models\User;
use App\Support\AudioFormats;
use App\Support\VideoDubbing;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * Links to a member's finished speech that anyone holding them can play or
 * download: the MCP server hands them to the AI assistant, which shows them
 * to the person. A link works for as many days as the member's plan keeps
 * their audio history (daysFor), and a new one can be asked for at any time.
 * It is signed, so it cannot be changed to reach another recording.
 */
final class SpeechLinks
{
    /**
     * Days a link works: the member's plan's audio history (Admin → Plans →
     * Recent Voices Retention, "Audio history" on the pricing page), as for
     * their dubbed videos. 0: the plan keeps it always, so the link has no end.
     */
    public static function daysFor(User $user): int
    {
        return VideoDubbing::keepDaysFor((int) $user->id);
    }

    /** @return array{url: string, expires_at: string|null} */
    public static function sign(int $historyItemId, int $days): array
    {
        if ($days <= 0) {
            return ['url' => URL::signedRoute('mcp.audio', ['generation' => $historyItemId]), 'expires_at' => null];
        }

        $expires = Carbon::now()->addDays($days);

        return [
            'url' => URL::temporarySignedRoute('mcp.audio', $expires, ['generation' => $historyItemId]),
            'expires_at' => $expires->toIso8601String(),
        ];
    }

    /** How long a link works, in words the assistant can pass on. */
    public static function lasts(?string $expiresAt): string
    {
        return $expiresAt === null
            ? 'the link has no end date'
            : 'the link works until '.Carbon::parse($expiresAt)->utc()->format('j F Y');
    }

    /**
     * A history item as the developer API shows it (ApiPresenter::historyItem),
     * with a link anyone can open instead of the API's audio address (which
     * needs the key).
     *
     * @param  array<string, mixed>  $item
     * @param  int  $days  daysFor() the member
     * @return array<string, mixed>
     */
    public static function withLink(array $item, int $days): array
    {
        $link = ($item['audio_url'] ?? null) !== null ? self::sign((int) $item['history_item_id'], $days) : null;
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
