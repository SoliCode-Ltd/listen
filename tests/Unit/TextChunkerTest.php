<?php

namespace Tests\Unit;

use App\Support\TextChunker;
use PHPUnit\Framework\TestCase;

/**
 * Long texts are read in pieces at the same time, so the pieces should be
 * about the same size, break where a reader would pause, and lose nothing.
 */
class TextChunkerTest extends TestCase
{
    public function test_a_text_within_the_limit_is_one_piece_as_written(): void
    {
        $text = "First line.\n\nSecond paragraph, with a comma.";

        $this->assertSame([$text], TextChunker::split("  {$text}\n", 900, 'mb_strlen'));
    }

    public function test_pieces_are_about_the_same_size_and_no_more_than_before(): void
    {
        $text = $this->sentences(25, 'This is sentence number %d of the script.');
        $this->assertGreaterThan(900, mb_strlen($text));
        $this->assertLessThan(1800, mb_strlen($text));

        $chunks = TextChunker::split($text, 900, 'mb_strlen');

        // Filling the first piece to the limit left a short second one (900 and about 170).
        $this->assertCount(2, $chunks);
        $sizes = array_map('mb_strlen', $chunks);
        $this->assertLessThanOrEqual(50, abs($sizes[0] - $sizes[1]));
        $this->assertSame($this->words($text), $this->words(implode(' ', $chunks)));
        foreach ($chunks as $chunk) {
            $this->assertMatchesRegularExpression('/\.$/', $chunk, 'Each piece ends at a sentence end.');
        }
    }

    public function test_pieces_never_exceed_the_limit_and_keep_every_word(): void
    {
        $arabic = $this->sentences(140, 'هذه هي الجملة رقم %d من النص، وهي جملة عادية للاختبار.');
        $english = $this->sentences(140, 'Sentence %d is here, and it says something ordinary!');

        foreach ([[$arabic, 'mb_strlen', 900, 0], [$arabic, 'mb_strlen', 900, 4], [$arabic, 'strlen', 4800, 4], [$english, 'mb_strlen', 300, 3], [$english, 'strlen', 777, 0]] as [$text, $measure, $max, $together]) {
            $chunks = TextChunker::split($text, $max, $measure, $together);
            foreach ($chunks as $chunk) {
                $this->assertLessThanOrEqual($max, $measure($chunk));
                $this->assertNotSame('', trim($chunk));
            }
            $this->assertSame($this->words($text), $this->words(implode(' ', $chunks)));
            $this->assertSame($this->greedyCount($text, $max, $measure), count($chunks), 'Never more requests than filling each piece to the limit.');
        }
    }

    public function test_read_four_at_a_time_earlier_rounds_stay_full_and_the_last_shares_the_rest(): void
    {
        $text = $this->sentences(110, 'This is sentence number %d of the script.');

        $chunks = TextChunker::split($text, 900, 'mb_strlen', 4);

        $this->assertCount(6, $chunks);
        $sizes = array_map('mb_strlen', $chunks);
        foreach (array_slice($sizes, 0, 4) as $size) {
            $this->assertGreaterThan(850, $size, 'The first round of four is as full as before.');
        }
        $this->assertLessThanOrEqual(50, abs($sizes[4] - $sizes[5]), 'The second round shares what is left.');
        $this->assertSame($this->words($text), $this->words(implode(' ', $chunks)));

        // All at once, the longest piece is barely over an even share.
        $sizes = array_map('mb_strlen', TextChunker::split($text, 900, 'mb_strlen'));
        $this->assertCount(6, $sizes);
        $this->assertLessThanOrEqual(intdiv(mb_strlen($text), 6) + 50, max($sizes));
    }

    public function test_a_long_sentence_breaks_at_a_comma_not_inside_a_clause(): void
    {
        $clauses = [];
        for ($i = 1; $i <= 30; $i++) {
            $clauses[] = "ذهبنا إلى السوق في اليوم رقم {$i} واشترينا الخبز";
        }
        $sentence = implode('، ', $clauses).'.';
        $this->assertGreaterThan(900, mb_strlen($sentence));

        $chunks = TextChunker::split($sentence, 900, 'mb_strlen');

        $this->assertGreaterThan(1, count($chunks));
        foreach (array_slice($chunks, 0, -1) as $chunk) {
            $this->assertStringEndsWith('واشترينا الخبز،', $chunk);
        }
        $this->assertSame($this->words($sentence), $this->words(implode(' ', $chunks)));
    }

    public function test_paragraph_breaks_stay_inside_a_piece(): void
    {
        $paragraphs = [];
        for ($i = 1; $i <= 12; $i++) {
            $paragraphs[] = "Paragraph {$i} opens here. It has a second sentence that runs on a little longer than the first one did.";
        }
        $text = implode("\n\n", $paragraphs);

        $chunks = TextChunker::split($text, 600, 'mb_strlen');

        $this->assertGreaterThan(1, count($chunks));
        $this->assertStringContainsString("did.\nParagraph 2 opens here.", $chunks[0]);
        $this->assertStringNotContainsString('did. Paragraph', implode('|', $chunks));
    }

    public function test_lines_without_punctuation_are_pauses_too(): void
    {
        $lines = [];
        for ($i = 1; $i <= 40; $i++) {
            $lines[] = "Line {$i} of a list with no full stop";
        }
        $text = implode("\n", $lines);

        $chunks = TextChunker::split($text, 400, 'mb_strlen');

        foreach ($chunks as $chunk) {
            $this->assertMatchesRegularExpression('/full stop$/', $chunk, 'A piece ends at a line end, not mid-line.');
        }
        $this->assertSame($this->words($text), $this->words(implode(' ', $chunks)));
    }

    public function test_a_word_longer_than_the_limit_is_cut(): void
    {
        $word = str_repeat('ب', 1000);

        $chunks = TextChunker::split("قبل {$word} بعد", 300, 'strlen');

        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(300, strlen($chunk));
            $this->assertTrue(mb_check_encoding($chunk, 'UTF-8'), 'Never cuts a letter in half.');
        }
        $this->assertSame('قبل'.$word.'بعد', str_replace(' ', '', implode('', $chunks)));
    }

    private function sentences(int $count, string $pattern): string
    {
        $out = [];
        for ($i = 1; $i <= $count; $i++) {
            $out[] = sprintf($pattern, $i);
        }

        return implode(' ', $out);
    }

    /**
     * @return array<int, string>
     */
    private function words(string $text): array
    {
        return preg_split('/\s+/u', trim($text)) ?: [];
    }

    /**
     * How many pieces filling each one to the limit takes, sentence by sentence.
     */
    private function greedyCount(string $text, int $max, callable $measure): int
    {
        $count = 0;
        $current = '';
        foreach (preg_split('/(?<=[.!?])\s+/u', $text) ?: [] as $sentence) {
            $candidate = $current === '' ? $sentence : $current.' '.$sentence;
            if ($measure($candidate) <= $max) {
                $current = $candidate;
                continue;
            }
            $count++;
            $current = $sentence;
        }

        return $count + ($current !== '' ? 1 : 0);
    }
}
