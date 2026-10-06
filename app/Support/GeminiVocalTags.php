<?php

namespace App\Support;

/**
 * Inline vocal tags for Gemini 3.8 speech.
 *
 * Gemini 3.8 reads the transcript word for word and takes momentary
 * sounds as English tags in angle brackets, such as <laugh> or
 * <short pause>, placed where the sound happens. Moods and delivery
 * belong in the style instructions instead.
 *
 * Writers often use the ElevenLabs style ([laughs], [whispering],
 * [excited]), so known sounds are rewritten to the Gemini tag and mood
 * tags are moved out of the spoken text into the style.
 */
class GeminiVocalTags
{
    /**
     * Tags Gemini 3.8 supports, grouped for the editor.
     *
     * @var array<string, array<int, string>>
     */
    public const GROUPS = [
        'laughter' => ['laugh', 'chuckle', 'giggle', 'cackle', 'snicker'],
        'breath' => ['sigh', 'gasp', 'breath', 'heavy breath', 'exhales', 'pant', 'phew'],
        'pauses' => ['short pause', 'long pause'],
        'voice' => ['whispers', 'shout', 'scream', 'shriek', 'cheer'],
        'body' => ['cough', 'sneeze', 'throat-clearing', 'yawn', 'snort', 'tsk', 'pff'],
        'emotion' => ['cry', 'sob', 'whimper', 'groan', 'moan', 'growl', 'grunt', 'grr', 'hiss', 'argh'],
    ];

    /**
     * Other spellings, mostly from ElevenLabs scripts, mapped to a Gemini tag.
     *
     * @var array<string, string>
     */
    private const ALIASES = [
        'laughs' => 'laugh', 'laughing' => 'laugh', 'laughter' => 'laugh',
        'chuckles' => 'chuckle', 'chuckling' => 'chuckle',
        'giggles' => 'giggle', 'giggling' => 'giggle',
        'cackles' => 'cackle', 'snickers' => 'snicker',
        'sighs' => 'sigh', 'sighing' => 'sigh',
        'gasps' => 'gasp', 'gasping' => 'gasp',
        'breathes' => 'breath', 'breathing' => 'breath', 'inhales' => 'breath', 'inhale' => 'breath',
        'heavy breathing' => 'heavy breath',
        'exhale' => 'exhales', 'exhaling' => 'exhales',
        'pants' => 'pant', 'panting' => 'pant',
        'pause' => 'short pause', 'beat' => 'short pause',
        'whisper' => 'whispers', 'whispering' => 'whispers',
        'shouts' => 'shout', 'shouting' => 'shout', 'yells' => 'shout', 'yelling' => 'shout',
        'screams' => 'scream', 'screaming' => 'scream',
        'coughs' => 'cough', 'coughing' => 'cough',
        'sneezes' => 'sneeze', 'sneezing' => 'sneeze',
        'clears throat' => 'throat-clearing', 'clearing throat' => 'throat-clearing', 'throat clearing' => 'throat-clearing',
        'yawns' => 'yawn', 'yawning' => 'yawn',
        'snorts' => 'snort',
        'cries' => 'cry', 'crying' => 'cry',
        'sobs' => 'sob', 'sobbing' => 'sob',
        'whimpers' => 'whimper', 'groans' => 'groan', 'growls' => 'growl', 'grunts' => 'grunt',
    ];

    /** Longest text a mood tag may hold, so ordinary bracketed text is left alone. */
    private const MAX_CUE_LENGTH = 40;

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return array_merge(...array_values(self::GROUPS));
    }

    /**
     * The Gemini tag for a written cue, or null when it is not a sound.
     */
    public static function canonical(string $cue): ?string
    {
        $key = strtolower(trim((string) preg_replace('/\s+/u', ' ', $cue)));
        if (in_array($key, self::all(), true)) {
            return $key;
        }

        return self::ALIASES[$key] ?? null;
    }

    /**
     * Ready a transcript for Gemini 3.8: sounds become <tag>, moods are
     * removed from the spoken text and returned as style cues.
     *
     * @return array{text: string, cues: array<int, string>}
     */
    public static function prepare(string $text): array
    {
        $cues = [];
        $text = (string) preg_replace_callback(
            '/\[([^\[\]\n]{1,'.self::MAX_CUE_LENGTH.'})\]|<([^<>\s](?:[^<>\n]{0,'.(self::MAX_CUE_LENGTH - 2).'}[^<>\s])?)>|\(([^()\n]{1,'.self::MAX_CUE_LENGTH.'})\)/u',
            function (array $m) use (&$cues): string {
                $inner = trim($m[3] ?? '') !== '' ? $m[3] : (trim($m[2] ?? '') !== '' ? $m[2] : $m[1]);
                $isParenthesis = isset($m[3]) && $m[3] !== '';

                $tag = self::canonical($inner);
                if ($tag !== null) {
                    return '<'.$tag.'>';
                }
                // "(WHO)" or "(see page 4)" is part of the text; only bracketed moods are directions.
                if ($isParenthesis || ! self::looksLikeCue($inner)) {
                    return $m[0];
                }
                $cues[] = strtolower(trim($inner));

                return ' ';
            },
            $text,
        );

        return [
            'text' => self::tidy($text),
            'cues' => array_values(array_unique($cues)),
        ];
    }

    /**
     * Gemini 2.5 reads the script as a prompt, so sounds stay as
     * bracketed directions it understands, written as [laugh].
     */
    public static function forLegacyModel(string $text): string
    {
        return (string) preg_replace_callback(
            '/<([^<>\s](?:[^<>\n]{0,'.(self::MAX_CUE_LENGTH - 2).'}[^<>\s])?)>/u',
            fn (array $m) => ($tag = self::canonical($m[1])) !== null ? '['.$tag.']' : $m[0],
            $text,
        );
    }

    /**
     * Remove Gemini tags for engines that would read them aloud.
     */
    public static function strip(string $text): string
    {
        return (string) preg_replace_callback(
            '/<([^<>\s](?:[^<>\n]{0,'.(self::MAX_CUE_LENGTH - 2).'}[^<>\s])?)>/u',
            fn (array $m) => self::canonical($m[1]) !== null ? ' ' : $m[0],
            $text,
        );
    }

    /**
     * Add the moods taken from the script to the style instructions.
     *
     * @param  array<int, string>  $cues
     */
    public static function withCues(string $style, array $cues): string
    {
        $style = trim($style);
        if ($cues === []) {
            return $style;
        }

        $line = 'Delivery cues from the script: '.implode(', ', $cues).'.';

        return $style === '' ? $line : $style."\n".$line;
    }

    /**
     * Swap tags for placeholders a translator leaves alone, and back.
     *
     * @return array{text: string, tags: array<int, string>}
     */
    public static function protect(string $text): array
    {
        $tags = [];
        $text = (string) preg_replace_callback(
            '/\[([^\[\]\n]{1,'.self::MAX_CUE_LENGTH.'})\]|<([^<>\s](?:[^<>\n]{0,'.(self::MAX_CUE_LENGTH - 2).'}[^<>\s])?)>/u',
            function (array $m) use (&$tags): string {
                $inner = isset($m[2]) && $m[2] !== '' ? $m[2] : $m[1];
                if (self::canonical($inner) === null && ! self::looksLikeCue($inner)) {
                    return $m[0];
                }
                $tags[] = $m[0];

                return ' [['.(count($tags) - 1).']] ';
            },
            $text,
        );

        return ['text' => $text, 'tags' => $tags];
    }

    /**
     * @param  array<int, string>  $tags
     */
    public static function restore(string $text, array $tags): string
    {
        if ($tags === []) {
            return $text;
        }

        $text = (string) preg_replace_callback(
            '/\[\s*\[\s*(\d+)\s*\]\s*\]/u',
            fn (array $m) => $tags[(int) $m[1]] ?? '',
            $text,
        );

        return self::tidy($text);
    }

    private static function looksLikeCue(string $inner): bool
    {
        $inner = trim($inner);

        // Words only: "[1]", "[sic.]" and "[link](...)" stay as written.
        return $inner !== ''
            && preg_match('/^[\p{L}][\p{L}\p{M}\s\-,&\']*$/u', $inner) === 1
            && preg_match('/^(?:sic)$/iu', $inner) !== 1;
    }

    private static function tidy(string $text): string
    {
        $lines = [];
        foreach (preg_split('/\R/u', $text) ?: [$text] as $line) {
            $line = (string) preg_replace('/[ \t]{2,}/u', ' ', $line);
            $line = (string) preg_replace('/\s+([,.!?؟،؛…])/u', '$1', $line);
            $lines[] = trim($line);
        }

        return trim(implode("\n", $lines));
    }
}
