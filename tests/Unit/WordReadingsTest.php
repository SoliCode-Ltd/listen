<?php

namespace Tests\Unit;

use App\Support\Tashkeel\TashkeelScore;
use App\Support\Tashkeel\WordReadings;
use PHPUnit\Framework\TestCase;

/**
 * The automatic step before a voice reads: the words a text model says could
 * be read wrongly are used only when they are safe (same letters, in the text,
 * not already marked, not the member's own), the text is marked on those words
 * alone, and the voice is told what each means.
 */
class WordReadingsTest extends TestCase
{
    private const TEXT = 'رفع الجنود علم بلادهم في جدة، وكتب الخبر.';

    public function test_a_reading_is_used_only_with_the_same_letters_in_the_text(): void
    {
        $kept = WordReadings::accept(self::TEXT, [
            ['word' => 'علم', 'marked' => 'عَلَم', 'said' => 'ʿalam', 'means' => 'a flag'],
            // A letter changed, a word that is not in the text, no marks at all, no meaning: all left out.
            ['word' => 'جدة', 'marked' => 'جِدَّه', 'said' => 'Jidda', 'means' => 'the city of Jeddah'],
            ['word' => 'القلعة', 'marked' => 'القَلْعَة', 'said' => 'al-qalʿa', 'means' => 'the castle'],
            ['word' => 'بلادهم', 'marked' => 'بلادهم', 'said' => 'bilādihim', 'means' => 'their country'],
            ['word' => 'الخبر', 'marked' => 'الخَبَر', 'said' => 'al-khabar', 'means' => ''],
            // Part of a longer word («وكتب») is not the word.
            ['word' => 'كتب', 'marked' => 'كَتَبَ', 'said' => 'kataba', 'means' => 'he wrote'],
            'not an entry',
        ]);

        $this->assertSame([['word' => 'علم', 'marked' => 'عَلَم', 'said' => 'ʿalam', 'means' => 'a flag', 'kind' => 'word']], $kept);
    }

    public function test_a_word_the_member_marked_or_saved_is_left_alone(): void
    {
        $entries = [
            ['word' => 'علم', 'marked' => 'عِلْم', 'said' => 'ʿilm', 'means' => 'knowledge'],
            ['word' => 'جدة', 'marked' => 'جِدَّة', 'said' => 'Jidda', 'means' => 'the city of Jeddah'],
        ];

        // The member wrote «عَلَم» with its marks: the model's «علم» is no longer in the text.
        $this->assertSame(['جدة'], array_column(WordReadings::accept('رفع الجنود عَلَم بلادهم في جدة', $entries), 'word'));
        // The member saved their own way to say جدة.
        $this->assertSame(['علم'], array_column(WordReadings::accept(self::TEXT, $entries, ['جَدّة']), 'word'));
    }

    public function test_an_ending_the_writer_put_stays_and_the_word_can_still_be_read(): void
    {
        $text = 'أهداها زوجها عقداً من اللؤلؤ.';

        $kept = WordReadings::accept($text, [['word' => 'عقداً', 'marked' => 'عِقْدا', 'said' => 'ʿiqdan', 'means' => 'a necklace']]);

        $this->assertSame('عِقْداً', $kept[0]['marked']);
        // The model's own tanween, on the letter before the alef, is not doubled.
        $this->assertSame('عِقْداً', WordReadings::accept($text, [['word' => 'عقداً', 'marked' => 'عِقْدًا', 'said' => 'ʿiqdan', 'means' => 'a necklace']])[0]['marked']);
        $this->assertSame('أهداها زوجها عِقْداً من اللؤلؤ.', WordReadings::apply($text, $kept)['text']);
    }

    public function test_a_stretched_reading_loses_its_tatweel_and_refusals_are_counted(): void
    {
        $refused = [];

        $kept = WordReadings::accept('حجزت جلسة في جدة', [
            ['word' => 'جلسة', 'marked' => 'جَـلْـسَـة', 'said' => 'jalsa', 'means' => 'a session'],
            ['word' => 'جدة', 'marked' => 'جِدَّه', 'said' => 'Jidda', 'means' => 'the city of Jeddah'],
            ['word' => 'الرياض', 'marked' => 'الرِّيَاض', 'said' => 'ar-Riyāḍ', 'means' => 'the city of Riyadh'],
        ], [], $refused);

        $this->assertSame(['جَلْسَة'], array_column($kept, 'marked'));
        $this->assertSame(['letters changed' => 1, 'not in the text as written' => 1], $refused);
    }

    public function test_in_a_dialect_text_only_names_are_touched(): void
    {
        $text = 'جبنالك طقم من جدة بكل تفاصيله';
        $answer = json_encode([
            ['word' => 'جبنالك', 'marked' => 'جِبْنَالَك', 'said' => 'jibnālak', 'means' => 'we brought you', 'kind' => 'word'],
            ['word' => 'جدة', 'marked' => 'جِدَّة', 'said' => 'Jidda', 'means' => 'the city of Jeddah', 'kind' => 'Name'],
            // No kind given: taken for a word.
            ['word' => 'بكل', 'marked' => 'بِكِلّ', 'said' => 'b-kill', 'means' => 'with all'],
        ], JSON_UNESCAPED_UNICODE);

        $this->assertSame(['جدة'], array_column(WordReadings::find($text, 'Levantine Arabic', fn (): string => $answer), 'word'));
        // The same answer for a Fusha text keeps all three.
        $this->assertCount(3, WordReadings::find($text, null, fn (): string => $answer));
        // A caller that knows the dialect is not asked to decide it; one that does not, is.
        $this->assertStringNotContainsString('First decide', WordReadings::prompt($text, 'Levantine Arabic'));
        $this->assertStringContainsString('First decide what the text is written in', WordReadings::prompt($text));
        $this->assertStringContainsString('list ONLY names', WordReadings::prompt($text));
    }

    public function test_the_answers_say_what_the_text_is_written_in_and_a_dialect_keeps_names_only(): void
    {
        $text = 'جبنالك طقم من جدة بكل تفاصيله';
        $answer = fn (string $in): string => json_encode(['written_in' => $in, 'words' => [
            ['word' => 'جبنالك', 'marked' => 'جِبْنَالَك', 'said' => 'jibnālak', 'means' => 'we brought you', 'kind' => 'word'],
            ['word' => 'جدة', 'marked' => 'جِدَّة', 'said' => 'Jidda', 'means' => 'the city of Jeddah', 'kind' => 'name'],
        ]], JSON_UNESCAPED_UNICODE);

        // Most answers call it a dialect: the name only, though nobody told the step the variety.
        $answers = [$answer('levantine'), $answer('Fusha'), $answer('gulf')];
        $this->assertSame('dialect', WordReadings::writtenIn($answers));
        $refused = [];
        $this->assertSame(['جدة'], array_column(WordReadings::agreed($text, $answers, [], $refused), 'word'));
        $this->assertSame(3, $refused['a word in a dialect text']);
        // Most call it Fusha: both words. One each way is a dialect, the careful side.
        $this->assertSame('fusha', WordReadings::writtenIn([$answer('fusha'), $answer('Modern Standard Arabic (Fusha)'), $answer('egyptian')]));
        $this->assertCount(2, WordReadings::agreed($text, [$answer('fusha'), $answer('fusha'), $answer('egyptian')]));
        $this->assertSame('dialect', WordReadings::writtenIn([$answer('fusha'), $answer('egyptian')]));
        // Answers that do not say (a bare list) leave it to the caller.
        $this->assertNull(WordReadings::writtenIn(['[]', 'no idea']));
        // The words are read from the object, wrapped or not.
        $this->assertCount(2, WordReadings::parse("```json\n".$answer('fusha')."\n```"));
        $this->assertSame([], WordReadings::parse('{"written_in": "fusha", "words": []}'));
    }

    public function test_no_more_than_the_limit_and_each_word_once(): void
    {
        $text = '';
        $entries = [];
        foreach (['علم', 'عقد', 'كتب', 'ملك', 'درس', 'شعر', 'قدم', 'رجل', 'ظهر', 'جزر'] as $word) {
            $text .= $word.' ';
            $entries[] = ['word' => $word, 'marked' => mb_substr($word, 0, 1)."\u{064E}".mb_substr($word, 1), 'said' => 'x', 'means' => 'y'];
            $entries[] = ['word' => $word, 'marked' => mb_substr($word, 0, 1)."\u{064F}".mb_substr($word, 1), 'said' => 'x2', 'means' => 'y2'];
        }

        $kept = WordReadings::accept($text, $entries);

        $this->assertCount(WordReadings::MAX, $kept);
        $this->assertSame('x', $kept[0]['said']);
        $this->assertSame('عقد', $kept[1]['word']);
    }

    public function test_a_word_is_used_only_when_most_answers_agree_on_its_reading(): void
    {
        $entry = fn (string $word, string $marked, string $said): array => ['word' => $word, 'marked' => $marked, 'said' => $said, 'means' => 'm'];
        $answers = [
            // رفع in two answers of three (once with its ending, once without): kept. جدة in one: not.
            json_encode([$entry('علم', 'عَلَم', 'ʿalam'), $entry('رفع', 'رَفَعَ', 'rafaʿa'), $entry('جدة', 'جِدَّة', 'Jidda')], JSON_UNESCAPED_UNICODE),
            json_encode([$entry('رفع', 'رَفَع', 'rafaʿa 2'), $entry('علم', 'عِلْم', 'ʿilm')], JSON_UNESCAPED_UNICODE),
            // علم: "ʿalam" twice against "ʿilm" once. الخبر: three answers, three readings.
            json_encode([$entry('علم', 'عَلَمَ', 'ʿalam 3'), $entry('الخبر', 'الخَبَر', 'khabar')], JSON_UNESCAPED_UNICODE),
        ];
        $refused = [];

        $kept = WordReadings::agreed(self::TEXT, $answers, [], $refused);

        // In the order of the text, each as the first answer that gave it wrote it.
        $this->assertSame([['رفع', 'رَفَعَ', 'rafaʿa'], ['علم', 'عَلَم', 'ʿalam']], array_map(fn (array $r): array => [$r['word'], $r['marked'], $r['said']], $kept));
        $this->assertSame(2, $refused['not agreed between the answers']);
        // One answer alone decides; none gives nothing; two that differ on a word leave it.
        $this->assertSame(['رفع', 'علم', 'جدة'], array_column(WordReadings::agreed(self::TEXT, [$answers[0]]), 'word'));
        $this->assertSame([], WordReadings::agreed(self::TEXT, []));
        $this->assertSame(['رفع'], array_column(WordReadings::agreed(self::TEXT, [$answers[0], $answers[1]]), 'word'));
    }

    public function test_the_model_is_asked_several_times_and_an_answer_that_failed_is_left_out(): void
    {
        $asked = [];
        $ask = function (string $prompt, int $k) use (&$asked): ?string {
            $asked[] = $k;

            return [
                '[{"word": "علم", "marked": "عَلَم", "said": "ʿalam", "means": "a flag"}, {"word": "جدة", "marked": "جِدَّة", "said": "Jidda", "means": "Jeddah"}]',
                null,
                '[{"word": "علم", "marked": "عَلَم", "said": "ʿalam", "means": "a flag"}]',
            ][$k];
        };

        // Two answers came back: both give علم, one gives جدة.
        $this->assertSame(['علم'], array_column(WordReadings::find(self::TEXT, null, $ask), 'word'));
        $this->assertSame([0, 1, 2], $asked);
        $this->assertSame(['علم', 'جدة'], array_column(WordReadings::find(self::TEXT, null, $ask, [], 1), 'word'));
    }

    public function test_the_limit_falls_on_the_last_words_of_the_text_whatever_order_the_answer_has(): void
    {
        $words = ['علم', 'عقد', 'كتب', 'ملك', 'درس', 'شعر', 'قدم', 'رجل', 'ظهر', 'جزر'];
        $entries = array_map(fn (string $w): array => ['word' => $w, 'marked' => mb_substr($w, 0, 1)."\u{064E}".mb_substr($w, 1), 'said' => 'x', 'means' => 'y'], array_reverse($words));
        $refused = [];

        $kept = WordReadings::agreed(implode(' ', $words), [json_encode($entries, JSON_UNESCAPED_UNICODE)], [], $refused);

        $this->assertSame(array_slice($words, 0, WordReadings::MAX), array_column($kept, 'word'));
        $this->assertSame(2, $refused['over the limit']);
    }

    public function test_only_those_words_are_marked_and_the_voice_is_told_what_they_mean(): void
    {
        $readings = [
            ['word' => 'علم', 'marked' => 'عَلَم', 'said' => 'ʿalam', 'means' => 'a flag'],
            ['word' => 'جدة', 'marked' => 'جِدَّة', 'said' => 'Jidda', 'means' => 'the city of Jeddah'],
        ];

        $made = WordReadings::apply(self::TEXT, $readings);

        $this->assertSame('رفع الجنود عَلَم بلادهم في جِدَّة، وكتب الخبر.', $made['text']);
        // Not a letter changed.
        $this->assertSame(self::TEXT, TashkeelScore::strip($made['text']));
        $this->assertSame('In this text the word written «علم» means "a flag" and is said "ʿalam"; the word written «جدة» means "the city of Jeddah" and is said "Jidda". Say those words exactly that way.', $made['direction']);
        $this->assertSame('In this text the word written «علم» means "a flag" and is said "ʿalam". Say that word exactly that way.', WordReadings::direction([$readings[0]]));
        $this->assertSame('', WordReadings::direction([]));
    }

    public function test_the_models_answer_is_read_even_when_wrapped_and_nothing_is_used_when_it_fails(): void
    {
        $answer = "```json\n[{\"word\": \"علم\", \"marked\": \"عَلَم\", \"said\": \"ʿalam\", \"means\": \"a flag\"}]\n```";

        $this->assertSame('عَلَم', WordReadings::find(self::TEXT, null, fn (string $prompt): string => $answer)[0]['marked']);
        $this->assertSame([], WordReadings::find(self::TEXT, null, fn (): ?string => null));
        $this->assertSame([], WordReadings::find(self::TEXT, null, fn (): string => 'I could not find any.'));
        $this->assertSame([], WordReadings::parse('{"word": "علم"}'));
        // No Arabic: the model is not asked at all.
        $this->assertSame([], WordReadings::find('Hello there', null, fn () => $this->fail('asked')));
    }

    public function test_the_prompt_carries_the_text_and_the_variety(): void
    {
        $this->assertStringContainsString('«'.self::TEXT.'»', WordReadings::prompt(self::TEXT));
        $this->assertStringContainsString('Modern Standard Arabic', WordReadings::prompt(self::TEXT));
        $this->assertStringContainsString('written in Egyptian Arabic', WordReadings::prompt(self::TEXT, 'Egyptian Arabic'));
        $this->assertStringContainsString('"written_in"', WordReadings::prompt(self::TEXT));
    }

    public function test_two_markings_of_a_word_are_the_same_reading_or_not(): void
    {
        // The ending and a sukun make no difference; the article's own marks neither.
        $this->assertSame('same', TashkeelScore::reading('عَلَم', 'عَلَمَ'));
        $this->assertSame('same', TashkeelScore::reading('العِلْم', 'الْعِلمِ'));
        $this->assertSame('same', TashkeelScore::reading('جِدَّة', 'جِدَّةَ'));
        $this->assertSame('different', TashkeelScore::reading('عَلَم', 'عِلْم'));
        $this->assertSame('different', TashkeelScore::reading('جِدَّة', 'جَدَّة'));
        $this->assertSame('different', TashkeelScore::reading('دَرَسَ', 'دَرَّسَ'));
        $this->assertSame('different', TashkeelScore::reading('جدة', 'جده'));
        // A vowel the reference gives and the candidate leaves open.
        $this->assertSame('unclear', TashkeelScore::reading('كُتُب', 'كُتب'));
        // A sukun where the reference has a vowel is another word (Ḥamad, ḥamd); a sukun or nothing where it has none is not.
        $this->assertSame('different', TashkeelScore::reading('حَمَد', 'حَمْد'));
        $this->assertSame('same', TashkeelScore::reading('دَرْس', 'دَرس'));
        // Letters the reference leaves without a mark are not judged: مَجَّانِي and مَجَّانِيّ are one word.
        $this->assertSame('same', TashkeelScore::reading('مَجَّانِي', 'مَجَّانِيّ'));
    }
}
