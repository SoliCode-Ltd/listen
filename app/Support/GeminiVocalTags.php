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
 * Members may also write other common styles ([laughs], [whispering],
 * [excited]) or the Arabic tag names the Arabic editor inserts
 * (<ضحكة>), so known sounds are rewritten to the Gemini tag and mood
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
     * Other common spellings, mapped to a Gemini tag.
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

    /**
     * Arabic name for each tag. The Arabic editor shows and inserts these,
     * and they are read back as the English tag the model needs.
     *
     * @var array<string, string>
     */
    public const ARABIC_NAMES = [
        'laugh' => 'ضحكة', 'chuckle' => 'ضحكة خفيفة', 'giggle' => 'قهقهة', 'cackle' => 'قهقهة عالية', 'snicker' => 'ضحكة ساخرة',
        'sigh' => 'تنهيدة', 'gasp' => 'شهقة', 'breath' => 'نفس', 'heavy breath' => 'نفس ثقيل', 'exhales' => 'زفير',
        'pant' => 'لهاث', 'phew' => 'تنفس ارتياح',
        'short pause' => 'وقفة قصيرة', 'long pause' => 'وقفة طويلة',
        'whispers' => 'همس', 'shout' => 'صياح', 'scream' => 'صرخة', 'shriek' => 'صرخة حادة', 'cheer' => 'هتاف',
        'cough' => 'سعال', 'sneeze' => 'عطسة', 'throat-clearing' => 'تنحنح', 'yawn' => 'تثاؤب', 'snort' => 'نخرة',
        'tsk' => 'طقطقة لسان', 'pff' => 'تأفف',
        'cry' => 'بكاء', 'sob' => 'نحيب', 'whimper' => 'أنين', 'groan' => 'تأوه', 'moan' => 'آهة',
        'growl' => 'زمجرة', 'grunt' => 'همهمة', 'grr' => 'زمجرة خفيفة', 'hiss' => 'فحيح', 'argh' => 'تذمر',
    ];

    /**
     * Delivery tags, written in square brackets ([excited], [متحمس]). Each
     * one sets how the voice performs from that point until the next one.
     * The model has no inline tags for these, so the script is read in parts
     * and each part gets its own direction.
     *
     * @var array<string, array<int, string>>
     */
    public const DELIVERY_GROUPS = [
        'mood' => ['happy', 'excited', 'warm', 'calm', 'serious', 'sad', 'angry', 'nervous', 'scared', 'surprised', 'curious', 'sarcastic', 'playful', 'confident', 'tired'],
        'pace' => ['slowly', 'quickly', 'softly', 'loudly', 'whispering'],
        'style' => ['storytelling', 'newsreader', 'advertising', 'conversational', 'dramatic', 'motivational', 'documentary'],
    ];

    /** @var array<string, string> */
    public const DELIVERY_ARABIC = [
        'happy' => 'سعيد', 'excited' => 'متحمس', 'warm' => 'دافئ', 'calm' => 'هادئ', 'serious' => 'جاد', 'sad' => 'حزين',
        'angry' => 'غاضب', 'nervous' => 'متوتر', 'scared' => 'خائف', 'surprised' => 'متفاجئ', 'curious' => 'فضولي',
        'sarcastic' => 'ساخر', 'playful' => 'مرح', 'confident' => 'واثق', 'tired' => 'متعب',
        'slowly' => 'ببطء', 'quickly' => 'بسرعة', 'softly' => 'بنعومة', 'loudly' => 'بصوت عالٍ', 'whispering' => 'هامس',
        'storytelling' => 'راوي قصص', 'newsreader' => 'مذيع أخبار', 'advertising' => 'إعلاني', 'conversational' => 'حواري',
        'dramatic' => 'درامي', 'motivational' => 'تحفيزي', 'documentary' => 'وثائقي',
    ];

    /** What each delivery tag asks of the voice. @var array<string, string> */
    private const DELIVERY_DIRECTIONS = [
        'happy' => 'happy and bright', 'excited' => 'excited and energetic', 'warm' => 'warm and friendly',
        'calm' => 'calm and relaxed', 'serious' => 'serious and measured', 'sad' => 'sad and subdued',
        'angry' => 'angry and intense', 'nervous' => 'nervous and hesitant', 'scared' => 'scared and tense',
        'surprised' => 'surprised', 'curious' => 'curious and questioning', 'sarcastic' => 'sarcastic and dry',
        'playful' => 'playful and light', 'confident' => 'confident and assured', 'tired' => 'tired and low-energy',
        'slowly' => 'slowly, with a relaxed pace', 'quickly' => 'quickly, with a brisk pace', 'softly' => 'softly and gently',
        'loudly' => 'loudly and projected', 'whispering' => 'in a whisper',
        'storytelling' => 'like a storyteller, vivid and engaging', 'newsreader' => 'like a news anchor, clear and authoritative',
        'advertising' => 'like an advert voiceover, upbeat and persuasive', 'conversational' => 'conversationally, natural and casual',
        'dramatic' => 'dramatically, with tension', 'motivational' => 'like a motivational speaker, uplifting and strong',
        'documentary' => 'like a documentary narrator, deep and steady',
    ];

    /**
     * Other Arabic ways members write the sounds (verbs, short forms).
     *
     * @var array<string, string>
     */
    private const ARABIC_ALIASES = [
        'ضحك' => 'laugh', 'يضحك' => 'laugh', 'تضحك' => 'laugh', 'ضحكات' => 'laugh',
        'ضحكة عالية' => 'cackle', 'ضحكة صغيرة' => 'chuckle', 'ابتسامة' => 'chuckle',
        'تنهد' => 'sigh', 'يتنهد' => 'sigh', 'تتنهد' => 'sigh', 'تنهيد' => 'sigh',
        'شهيق' => 'gasp', 'يشهق' => 'gasp', 'تشهق' => 'gasp',
        'تنفس' => 'breath', 'يتنفس' => 'breath', 'تتنفس' => 'breath', 'نفس عميق' => 'heavy breath',
        'زفرة' => 'exhales', 'يزفر' => 'exhales',
        'يلهث' => 'pant', 'تلهث' => 'pant',
        'وقفة' => 'short pause', 'سكتة' => 'short pause', 'توقف' => 'short pause', 'سكوت' => 'short pause',
        'صمت' => 'long pause', 'سكتة طويلة' => 'long pause', 'توقف طويل' => 'long pause',
        'همسة' => 'whispers', 'يهمس' => 'whispers', 'تهمس' => 'whispers', 'بهمس' => 'whispers',
        'صراخ' => 'scream', 'يصرخ' => 'scream', 'تصرخ' => 'scream',
        'زعيق' => 'shout', 'يزعق' => 'shout', 'تزعق' => 'shout', 'يصيح' => 'shout', 'تصيح' => 'shout',
        'سعلة' => 'cough', 'كحة' => 'cough', 'يكح' => 'cough', 'تكح' => 'cough', 'يسعل' => 'cough',
        'عطس' => 'sneeze', 'يعطس' => 'sneeze', 'تعطس' => 'sneeze',
        'يتثاءب' => 'yawn', 'تتثاءب' => 'yawn',
        'بكى' => 'cry', 'يبكي' => 'cry', 'تبكي' => 'cry', 'عياط' => 'cry', 'يعيط' => 'cry', 'تعيط' => 'cry',
        'ينتحب' => 'sob', 'أنين' => 'whimper', 'يئن' => 'whimper', 'آه' => 'groan', 'يتأوه' => 'groan',
        'هتاف' => 'cheer', 'يهتف' => 'cheer', 'تصفيق' => 'cheer',
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
        $key = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $cue)));
        if (in_array($key, self::all(), true)) {
            return $key;
        }
        if (isset(self::ALIASES[$key])) {
            return self::ALIASES[$key];
        }

        return self::arabicIndex()[self::plainArabic($key)] ?? null;
    }

    /**
     * Every written form of the tags: English names and Arabic names.
     *
     * @return array<int, string>
     */
    public static function allSpellings(): array
    {
        return array_merge(self::all(), array_values(self::ARABIC_NAMES), array_keys(self::ARABIC_ALIASES), array_keys(self::ALIASES));
    }

    /**
     * What the editor shows and inserts for a tag in the given language.
     */
    public static function label(string $tag, ?string $locale = null): string
    {
        $locale = strtolower((string) ($locale ?? app()->getLocale()));

        return str_starts_with($locale, 'ar') ? (self::ARABIC_NAMES[$tag] ?? $tag) : $tag;
    }

    /**
     * @return array<string, string>
     */
    private static function arabicIndex(): array
    {
        static $index = null;
        if ($index === null) {
            $index = [];
            foreach (self::ARABIC_ALIASES as $name => $tag) {
                $index[self::plainArabic($name)] = $tag;
            }
            foreach (self::ARABIC_NAMES as $tag => $name) {
                $index[self::plainArabic($name)] = $tag;
            }
        }

        return $index;
    }

    /**
     * Arabic without diacritics and with the letter forms people type either way.
     */
    private static function plainArabic(string $text): string
    {
        $text = (string) preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $text);

        return strtr($text, ['أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ى' => 'ي', 'ة' => 'ه']);
    }

    /**
     * Every delivery tag, in order.
     *
     * @return array<int, string>
     */
    public static function deliveries(): array
    {
        return array_merge(...array_values(self::DELIVERY_GROUPS));
    }

    /**
     * The delivery tag for a written cue (English or Arabic), or null.
     */
    public static function delivery(string $cue): ?string
    {
        $key = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $cue)));
        if (isset(self::DELIVERY_DIRECTIONS[$key])) {
            return $key;
        }
        static $arabic = null;
        if ($arabic === null) {
            $arabic = [];
            foreach (self::DELIVERY_ARABIC as $tag => $name) {
                $arabic[self::plainArabic($name)] = $tag;
            }
        }

        if (in_array(self::plainArabic($key), ['همس', 'هامسا', 'بهمس', 'whisper', 'whispers'], true)) {
            return 'whispering';
        }

        return $arabic[self::plainArabic($key)] ?? null;
    }

    /**
     * What the editor shows and inserts for a delivery tag.
     */
    public static function deliveryLabel(string $tag, ?string $locale = null): string
    {
        $locale = strtolower((string) ($locale ?? app()->getLocale()));

        return str_starts_with($locale, 'ar') ? (self::DELIVERY_ARABIC[$tag] ?? $tag) : $tag;
    }

    /**
     * The script in parts, split at each delivery tag. Each part carries the
     * directions for it: the delivery in force there and any mood written
     * inside it. Sounds become <tag> as in prepare().
     *
     * @return array<int, array{text: string, cues: array<int, string>}>
     */
    /**
     * Bring the ways members write tags to the two forms parts() reads:
     *  - <همس> words </همس>: the words are whispered (a delivery span);
     *    any delivery or mood can be used this way;
     *  - a closing tag after a sound (<نفس> … </نفس>) is dropped;
     *  - a direction in angle brackets that is not a sound (<تقليد صوت سيدة>)
     *    works like one in square brackets.
     */
    public static function normalizeMarkup(string $text): string
    {
        $text = str_replace("\r\n", "\n", $text);
        $name = '[^<>\/\n]{1,'.self::MAX_CUE_LENGTH.'}';
        $text = (string) preg_replace_callback(
            '/<\s*('.$name.')\s*>(.*?)<\s*\/\s*\1\s*>/su',
            function (array $m): string {
                $inner = trim($m[1]);
                $sound = self::canonical($inner);
                if ($sound !== null && $sound !== 'whispers') {
                    return '<'.$inner.'>'.$m[2];
                }
                if ($sound === 'whispers' || self::delivery($inner) !== null || self::looksLikeCue($inner)) {
                    return '['.($sound === 'whispers' ? 'whispering' : $inner).']'.$m[2].' [/] ';
                }

                return $m[0];
            },
            $text,
        );
        // Closing tags left over: never spoken.
        $text = (string) preg_replace_callback('/<\s*\/\s*('.$name.')\s*>/u', function (array $m): string {
            $inner = trim($m[1]);

            return self::canonical($inner) !== null || self::delivery($inner) !== null || self::looksLikeCue($inner) ? ' ' : $m[0];
        }, $text);
        // Angle-bracket directions that are not sounds act from that point on.
        $text = (string) preg_replace_callback('/<([^<>\s\/](?:[^<>\n]{0,'.(self::MAX_CUE_LENGTH - 2).'}[^<>\s])?)>/u', function (array $m): string {
            return self::canonical($m[1]) === null && self::looksLikeCue($m[1]) ? '['.$m[1].']' : $m[0];
        }, $text);

        return $text;
    }

    public static function parts(string $text): array
    {
        $text = self::normalizeMarkup($text);
        $pieces = preg_split('/(\[[^\[\]\n]{1,'.self::MAX_CUE_LENGTH.'}\])/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];
        $parts = [];
        $current = null;
        $buffer = '';
        $flush = function () use (&$parts, &$buffer, &$current): void {
            $prepared = self::prepare($buffer);
            if (trim(self::strip($prepared['text'])) !== '') {
                $cues = $current !== null ? array_merge([$current], $prepared['cues']) : $prepared['cues'];
                $parts[] = ['text' => $prepared['text'], 'cues' => array_values(array_unique($cues))];
            }
            $buffer = '';
        };

        foreach ($pieces as $piece) {
            if (preg_match('/^\[([^\[\]\n]+)\]$/u', $piece, $m)) {
                $inner = $m[1];
                if (trim($inner) === '/') {
                    // End of a <tag> … </tag> span: back to the script's own delivery.
                    $flush();
                    $current = null;

                    continue;
                }
                $tag = self::delivery($inner);
                if ($tag !== null || (self::canonical($inner) === null && self::looksLikeCue($inner))) {
                    $flush();
                    $current = $tag !== null ? self::DELIVERY_DIRECTIONS[$tag] : mb_strtolower(trim($inner));

                    continue;
                }
            }
            $buffer .= $piece;
        }
        $flush();

        return $parts;
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
                $delivery = self::delivery($inner);
                $cues[] = $delivery !== null ? self::DELIVERY_DIRECTIONS[$delivery] : mb_strtolower(trim($inner));

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
        $text = (string) preg_replace_callback(
            '/\[([^\[\]\n]{1,'.self::MAX_CUE_LENGTH.'})\]/u',
            fn (array $m) => ($tag = self::delivery($m[1])) !== null ? '['.$tag.']' : $m[0],
            $text,
        );

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
     * What each sound should be, for the perform instruction.
     *
     * @var array<string, string>
     */
    private const SOUND_DIRECTIONS = [
        'laugh' => 'real, audible laughter (ha ha ha)',
        'chuckle' => 'a short, soft laugh',
        'giggle' => 'a light, playful giggle',
        'cackle' => 'a loud, wild cackle of laughter',
        'snicker' => 'a sly, half-suppressed laugh',
        'sigh' => 'a long, audible sigh',
        'gasp' => 'a sharp, audible gasp of surprise',
        'breath' => 'an audible breath in',
        'heavy breath' => 'heavy, audible breathing',
        'exhales' => 'a long, audible breath out',
        'pant' => 'fast, out-of-breath panting',
        'phew' => 'a relieved "phew"',
        'shout' => 'a loud shout',
        'scream' => 'a real, loud scream',
        'shriek' => 'a sharp, high shriek',
        'cheer' => 'a happy cheer',
        'cough' => 'a real cough',
        'sneeze' => 'a real sneeze',
        'throat-clearing' => 'clearing the throat',
        'yawn' => 'a real, audible yawn',
        'snort' => 'a short snort',
        'tsk' => 'a disapproving "tsk" click',
        'pff' => 'a dismissive "pff" puff of air',
        'cry' => 'real crying',
        'sob' => 'real sobbing',
        'whimper' => 'a soft whimper',
        'groan' => 'a long groan',
        'moan' => 'a low moan',
        'growl' => 'an angry growl',
        'grunt' => 'a short grunt',
        'grr' => 'an angry "grr"',
        'hiss' => 'a sharp hiss',
        'argh' => 'a frustrated "argh"',
    ];

    /**
     * Ready one request so its sound tags are performed, not skipped: each
     * sound gets its own beat in the text, and the style says what every
     * sound in it is. Listening tests on Arabic scripts: a scream was heard
     * 0 times in 4 without this, 3 in 4 with it.
     *
     * @return array{text: string, style: string}
     */
    public static function forPerformance(string $text, string $style): array
    {
        $found = [];
        if (preg_match_all('/<([^<>\n]{1,'.self::MAX_CUE_LENGTH.'})>/u', $text, $m)) {
            foreach ($m[1] as $tag) {
                if (isset(self::SOUND_DIRECTIONS[$tag])) {
                    $found[$tag] = true;
                }
            }
        }
        if ($found === []) {
            return ['text' => $text, 'style' => $style];
        }

        // A sound inside a running sentence is often swallowed: end the words before it.
        $names = implode('|', array_map(fn ($t) => preg_quote($t, '/'), array_keys($found)));
        $text = (string) preg_replace('/(?<=[^\s.!?؟،,…:;>])[ \t]*<('.$names.')>/u', '. <$1>', $text);

        $lines = [];
        foreach (array_keys($found) as $tag) {
            $lines[] = '<'.$tag.'> means: '.self::SOUND_DIRECTIONS[$tag].'.';
        }
        $line = 'The transcript contains sound tags in angle brackets. Never read a tag aloud. '
            .'At each tag, stop speaking and actually perform that sound with your voice, clearly and fully, then continue. '
            .implode(' ', $lines);
        $style = trim($style);

        return ['text' => $text, 'style' => $style === '' ? $line : $style."\n".$line];
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
                if (trim($inner) !== '/' && self::canonical($inner) === null && self::delivery($inner) === null && ! self::looksLikeCue($inner)) {
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
     * True when a rewrite kept every placeholder from protect(), once each and in order.
     */
    public static function placeholdersIntact(string $text, int $count): bool
    {
        preg_match_all('/\[\s*\[\s*(\d+)\s*\]\s*\]/u', $text, $m);

        return array_map('intval', $m[1]) === ($count > 0 ? range(0, $count - 1) : []);
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
            && preg_match('/^[\p{L}][\p{L}\p{M}\s\-,&\'+،]*$/u', $inner) === 1
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
