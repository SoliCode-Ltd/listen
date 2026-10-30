<?php

namespace App\Mcp;

use App\Support\VoiceFlag;
use Illuminate\Support\Str;

/**
 * How the MCP tools describe a finished recording to the assistant, in
 * words it can pass on to the person.
 */
final class SpeechSummary
{
    /** @param  array<string, mixed>  $speech  a history item with its link (SpeechLinks::withLink) */
    public static function text(array $speech): string
    {
        $dialect = $speech['dialect'] !== null ? ' ('.VoiceFlag::label((string) $speech['dialect'], false).')' : '';

        return implode("\n", [
            'Done: '.(int) $speech['duration_seconds'].' seconds of speech'.($speech['voice_name'] !== null ? ' with '.$speech['voice_name'] : '').$dialect.'.',
            'Play or download it ('.SpeechLinks::lasts($speech['audio_url_expires_at'] ?? null).'): '.$speech['audio_url'],
            'History item '.$speech['history_item_id'].'. Credits used: '.number_format((int) $speech['credits_used']).'.'
                .(isset($speech['credits_remaining']) ? ' Credits left: '.number_format((int) $speech['credits_remaining']).'.' : ''),
        ]);
    }

    /** @param  array<string, mixed>  $speech */
    public static function title(array $speech): string
    {
        return (string) ($speech['title'] ?? Str::limit(preg_replace('/\s+/u', ' ', (string) $speech['text']) ?? '', 60));
    }
}
