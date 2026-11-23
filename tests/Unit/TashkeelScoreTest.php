<?php

namespace Tests\Unit;

use App\Support\Tashkeel\TashkeelScore;
use PHPUnit\Framework\TestCase;

/**
 * The tashkeel scorer: a mark inside a word (the word said wrong) is counted
 * apart from the ending alone, and two correct spellings of the same sounds
 * are not counted apart.
 */
class TashkeelScoreTest extends TestCase
{
    public function test_a_perfect_marking_has_no_errors(): void
    {
        $s = TashkeelScore::compare('كَتَبَ الْوَلَدُ الدَّرْسَ.', 'كَتَبَ الْوَلَدُ الدَّرْسَ.');

        $this->assertSame(3, $s['words']);
        $this->assertSame(0, $s['inside_words'] + $s['ending_words'] + $s['wrong_marks'] + $s['extra_marks']);
        $this->assertFalse($s['letters_changed']);
    }

    public function test_a_wrong_mark_inside_the_word_is_the_word_said_wrong(): void
    {
        // «عَلَم» (flag) read as «عِلْم» (knowledge).
        $s = TashkeelScore::compare('رَفَعَ الْجُنْدِيُّ عَلَمَ بِلَادِهِ', 'رَفَعَ الْجُنْدِيُّ عِلْمَ بِلَادِهِ');

        $this->assertSame(1, $s['inside_words']);
        $this->assertSame(0, $s['ending_words']);
        $this->assertSame('inside', $s['misses'][0]['kind']);
        $this->assertSame('عَلَمَ', $s['misses'][0]['word']);
    }

    public function test_only_the_last_letter_differing_is_an_ending_error(): void
    {
        $s = TashkeelScore::compare('كَتَبَ الْوَلَدُ دَرْسًا', 'كَتَبَ الْوَلَدَ دَرْسا');

        $this->assertSame(0, $s['inside_words']);
        $this->assertSame(2, $s['ending_words']);
        // «دَرْسًا»: the tanween's letter is the ending, not the alef that seats it.
        $this->assertSame(['ending', 'ending'], array_column($s['misses'], 'kind'));
    }

    public function test_a_word_with_both_is_counted_once_as_said_wrong(): void
    {
        $s = TashkeelScore::compare('كُتِبَ الدَّرْسُ', 'كَتَبَ الدَّرْسَ');

        $this->assertSame(1, $s['inside_words']);
        $this->assertSame(1, $s['ending_words']);
        $this->assertSame(2, $s['words']);
    }

    public function test_two_spellings_of_the_same_sounds_are_equal(): void
    {
        $pairs = [
            ['كِتَابًا', 'كِتاباً'],                 // tanween before or after its alef; fatha before alef
            ['الشَّمْس', 'الشَمْس'],                  // the sun letter's doubling after the article
            ['فِي الْبَيْتِ', 'في البَيْتِ'],          // a long vowel's letter, the article's own marks
            ['يَقُولُ', 'يَقولُ'],
            ['مُدَرِّسَة', 'مُدَرِّسَة'],             // shadda and vowel in either order
            ['جَمِيـــل', 'جَميل'],                  // tatweel
        ];
        foreach ($pairs as [$a, $b]) {
            $s = TashkeelScore::compare($a, $b);
            $this->assertSame(0, $s['wrong_marks'], $a.' / '.$b);
            $this->assertFalse($s['letters_changed'], $a.' / '.$b);
        }
    }

    public function test_an_unmarked_candidate_misses_every_marked_word(): void
    {
        $s = TashkeelScore::compare('عَلِمَ الْمُدِيرُ بِالْخَبَرِ', 'علم المدير بالخبر');

        $this->assertSame(3, $s['words']);
        $this->assertSame(3, $s['inside_words']);
    }

    public function test_letters_the_reference_leaves_bare_are_not_scored_and_extras_are_counted_apart(): void
    {
        // The reference marks one word only (a partial policy): the rest is not an error either way.
        $s = TashkeelScore::compare('زار مَلِك البلاد المدينة', 'زَارَ مَلِكُ البِلَادِ المَدِينَةَ');

        $this->assertSame(1, $s['words']);
        $this->assertSame(0, $s['inside_words'] + $s['ending_words']);
        $this->assertGreaterThan(0, $s['extra_marks']);
    }

    public function test_a_candidate_that_changes_letters_is_flagged_and_still_scored_where_words_match(): void
    {
        $s = TashkeelScore::compare('ذَهَبَ الْوَلَدُ إِلَى الْمَدْرَسَةِ', 'ذَهَبَ الطِّفْلُ إِلَى الْمَدْرَسَةِ');

        $this->assertTrue($s['letters_changed']);
        // The replaced word is a miss; the other three match.
        $this->assertSame(1, $s['inside_words']);
        $this->assertSame(4, $s['words']);
    }

    public function test_english_words_numbers_and_punctuation_are_ignored(): void
    {
        $s = TashkeelScore::compare('اِشْتَرَكَ 300 عُضْوٍ فِي Listen AI.', 'اِشْتَرَكَ ٣٠٠ عُضْوٍ فِي listen ai!');

        $this->assertSame(0, $s['wrong_marks']);
        $this->assertFalse($s['letters_changed']);
    }
}
