<?php

namespace Tests\Unit;

use App\Support\JoinPauses;
use Tests\TestCase;

/**
 * The pause at a join between two pieces of a long text follows from where
 * the first piece's text ends.
 */
class JoinPausesTest extends TestCase
{
    public function test_a_sentence_end_gets_a_sentence_pause(): void
    {
        foreach (['It was late.', 'Really?', 'Stop!', 'And then…', 'وصلنا أخيرًا؟', 'انتهى الدرس.', 'یہ ختم ہوا۔',
            'He said: "I will be home."', 'قال: «سأعود غدًا.»', 'That is funny. <laugh>', 'Fine. [sigh]', "It ended.\u{200F}"] as $text) {
            $this->assertSame(JoinPauses::SENTENCE, JoinPauses::after($text, ' '), $text);
        }
    }

    public function test_a_comma_or_semicolon_gets_a_short_breath(): void
    {
        foreach (['When we arrived,', 'ذهبنا إلى السوق،', 'واشترينا الخبز؛', 'first; second;', 'There were three things:'] as $text) {
            $this->assertSame(JoinPauses::CLAUSE, JoinPauses::after($text, ' '), $text);
        }
    }

    public function test_a_cut_inside_a_sentence_gets_barely_a_gap(): void
    {
        $this->assertSame(JoinPauses::MID_SENTENCE, JoinPauses::after('and the road went on and on', ' '));
        $this->assertSame(JoinPauses::MID_SENTENCE, JoinPauses::after('ومشينا في الطريق الطويل', ' '));
    }

    public function test_a_line_or_paragraph_break_gets_the_longest(): void
    {
        $this->assertSame(JoinPauses::PARAGRAPH, JoinPauses::after('The end of a paragraph.', "\n"));
        $this->assertSame(JoinPauses::PARAGRAPH, JoinPauses::after('A line of a list with no full stop', "\n"));
    }

    public function test_the_pauses_are_in_the_order_a_reader_takes_them(): void
    {
        $this->assertGreaterThan(JoinPauses::SENTENCE, JoinPauses::PARAGRAPH);
        $this->assertGreaterThan(JoinPauses::CLAUSE, JoinPauses::SENTENCE);
        $this->assertGreaterThan(JoinPauses::MID_SENTENCE, JoinPauses::CLAUSE);
        $this->assertLessThan(0.3, JoinPauses::CLAUSE, 'a comma is shorter than the old fixed pause');
    }
}
