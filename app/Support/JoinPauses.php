<?php

namespace App\Support;

/**
 * The pause between two pieces of a long text, from where the text of the
 * first one ends. Before, every join had a fixed 0.3 s of silence on top of
 * the quiet each piece came with (about 0.25 s before its first word and
 * 0.2 s after its last): close to 0.8 s at every join, a full stop or not.
 * Now each piece's own quiet is cut at the join and this is the whole pause.
 *
 * The values are the reader's own: the pauses inside the premium voices'
 * takes (the 38 kept in public/audio), measured as silence under -50 dB, fall
 * around 0.1 s between words, 0.2 s at a comma and 0.45 s between sentences.
 * A paragraph or a line gets a longer breath.
 */
final class JoinPauses
{
    /** After a line or paragraph break. */
    public const PARAGRAPH = 0.75;

    /** After the end of a sentence: . ! ? ؟ … ۔ */
    public const SENTENCE = 0.45;

    /** After a comma, semicolon or colon: , ، ; ؛ : */
    public const CLAUSE = 0.2;

    /** Where a piece had to stop inside a sentence (no punctuation). */
    public const MID_SENTENCE = 0.1;

    /** Marks that end a sentence, Arabic and other scripts' full stops included. */
    private const SENTENCE_ENDS = ['.', '!', '?', '؟', '…', '۔', '؞', '।', '॥', '。', '！', '？', '‼', '⁇', '⁈', '⁉'];

    /** Marks after which a reader takes a short breath. */
    private const CLAUSE_ENDS = [',', '،', ';', '؛', ':', '⹁', '，', '、', '；', '：'];

    /**
     * Seconds of pause after a piece whose text is $text, followed in the
     * script by $gap ("\n" for a line break).
     */
    public static function after(string $text, string $gap = ''): float
    {
        if (str_contains($gap, "\n")) {
            return self::PARAGRAPH;
        }

        $mark = self::lastMark($text);
        if (in_array($mark, self::SENTENCE_ENDS, true)) {
            return self::SENTENCE;
        }

        return in_array($mark, self::CLAUSE_ENDS, true) ? self::CLAUSE : self::MID_SENTENCE;
    }

    /**
     * The text's last character once sound tags (<laugh>, [sigh]), closing
     * quotes and brackets, direction marks and spaces are set aside.
     */
    private static function lastMark(string $text): string
    {
        $text = GeminiVocalTags::strip($text);
        $text = (string) preg_replace('/(?:\s*\[[^\[\]\n]{1,40}\])+\s*$/u', '', $text);
        $text = (string) preg_replace('/[\s"\'»”’)\]}\x{200E}\x{200F}\x{061C}\x{0640}]+$/u', '', $text);

        return mb_substr($text, -1);
    }
}
