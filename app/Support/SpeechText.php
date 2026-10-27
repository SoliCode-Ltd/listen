<?php

namespace App\Support;

use App\Models\SiteSetting;

/**
 * The text as the voice should read it: abbreviations whose dot would read as
 * the end of a sentence (a long pause in the middle of a line) are written out,
 * and doubled punctuation is made single. Only abbreviations that mean one
 * thing where they stand are changed; anything unsure is left as written.
 * Sound and delivery tags (<laugh>, [calm]) are never touched.
 *
 * Switched off in Admin → TTS Settings ("Smooth abbreviations and punctuation").
 */
final class SpeechText
{
    public const SETTING = 'tts_smooth_text';

    private const TAG = '/(<[^<>\n]{1,40}>|\[[^\[\]\n]{1,40}\])/u';

    /**
     * What a voice reads for a member: abbreviations written out, then the member's own
     * pronunciations (App\Support\Pronunciations), last so nothing changes their spelling.
     *
     * @param  list<string>  $speakers  a dialogue's speaker names, kept as written
     * @return array{text: string, keep: list<string>} keep: the member's spellings in the text, to be read exactly
     */
    public static function forVoice(string $text, ?string $language, ?int $userId, array $speakers = []): array
    {
        $pronounced = Pronunciations::apply(self::smooth($text, $language), $userId, $speakers);

        return ['text' => $pronounced['text'], 'keep' => $pronounced['spoken']];
    }

    public static function enabled(): bool
    {
        return (string) SiteSetting::get(self::SETTING, '1') !== '0';
    }

    public static function smooth(string $text, ?string $language = null): string
    {
        if (trim($text) === '' || ! self::enabled()) {
            return $text;
        }
        $parts = preg_split(self::TAG, $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];
        foreach ($parts as $i => $part) {
            if ($i % 2 === 0 && $part !== '') {
                $parts[$i] = self::english(self::arabic(self::punctuation($part)));
            }
        }

        return implode('', $parts);
    }

    /** Doubled marks to one; four dots or more to an ellipsis (an ellipsis is a pause the writer chose). */
    private static function punctuation(string $text): string
    {
        return (string) preg_replace(
            ['/!{2,}/u', '/\?{2,}/u', '/؟{2,}/u', '/،{2,}/u', '/,{2,}/u', '/\.{4,}/u', '/(?<=\S)[ \t]{2,}(?=\S)/u'],
            ['!', '?', '؟', '،', ',', '…', ' '],
            $text,
        );
    }

    private static function arabic(string $text): string
    {
        $d = '[0-9٠-٩]';
        $replace = [
            // «د. أحمد»: a doctor before a name (never after a number: «٥ د.» is minutes).
            '/(?<![\p{L}\p{N}])(?<!'.$d.' )([وف]?)د\.\s*(?=\p{Arabic})/u' => '$1دكتور ',
            // The rest are said in full; a full stop after them stays, as it may end the sentence.
            // Times: «الساعة ٩ م» or «٩:٣٠ م» is the evening, «ص» the morning.
            '/((?:الساعة|الساعه)\s*'.$d.'{1,2}(?:[:٫.]'.$d.'{2})?|'.$d.'{1,2}[:]'.$d.'{2})\s*م(?![\p{L}])/u' => '$1 مساءً',
            '/((?:الساعة|الساعه)\s*'.$d.'{1,2}(?:[:٫.]'.$d.'{2})?|'.$d.'{1,2}[:]'.$d.'{2})\s*ص(?![\p{L}])/u' => '$1 صباحاً',
            // Years: «٢٠٢٤م» or «١٤٤٦ هـ».
            '/(?<!'.$d.')('.$d.'{4})\s*م(?![\p{L}])/u' => '$1 ميلادي',
            '/(?<!'.$d.')('.$d.'{3,4})\s*هـ(?![\p{L}])/u' => '$1 هجري',
            // «و» or «ف» may be joined to the front: «وص.ب».
            '/(?<![\p{L}])([وف]?)ق\.\s?م(?![\p{L}])/u' => '$1قبل الميلاد',
            '/(?<![\p{L}])([وف]?)ص\.\s?ب(?![\p{L}])/u' => '$1صندوق بريد',
            '/(?<![\p{L}])(?:إلخ|الخ)(?=\.)/u' => 'إلى آخره',
        ];

        return (string) preg_replace(array_keys($replace), array_values($replace), $text);
    }

    private static function english(string $text): string
    {
        $replace = [
            // A title before a name keeps its word and loses the dot: "Dr. Lee" is read "Doctor Lee".
            // (Not Jr. or Sr.: they come after a name, where the dot may end the sentence.)
            '/\b(Dr|Mr|Mrs|Ms|Prof)\.(?=\s+\p{Lu})/u' => '$1',
            '/\be\.g\.(?=\s|,)/iu' => 'for example',
            '/\bi\.e\.(?=\s|,)/iu' => 'that is',
            '/\bvs\.(?=\s)/iu' => 'versus',
            // "etc." in the middle of a sentence; at its end the full stop stays.
            '/\betc\.(?=\s+\p{Ll}|\s*,)/u' => 'et cetera',
            // Times: "9 a.m. today" → "9 AM today"; at the end of a sentence the full stop stays.
            // (Case is spelled out, not /i: with /i newer PCRE matches \p{Ll} to capitals too,
            // and "9 a.m. Then" lost its full stop.)
            '/(\d)\s*[aA]\.[mM]\.(?=\s+\p{Ll}|\s*,)/u' => '$1 AM',
            '/(\d)\s*[pP]\.[mM]\.(?=\s+\p{Ll}|\s*,)/u' => '$1 PM',
            '/(\d)\s*[aA]\.[mM]\.(?=\s*(?:$|\s\p{Lu}))/u' => '$1 AM.',
            '/(\d)\s*[pP]\.[mM]\.(?=\s*(?:$|\s\p{Lu}))/u' => '$1 PM.',
        ];

        return (string) preg_replace(array_keys($replace), array_values($replace), $text);
    }
}
