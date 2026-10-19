<?php

namespace Tests\Unit;

use App\Services\ProfanityFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The real word list on real sentences: ordinary Arabic and English text
 * passes, and explicit text is still caught, also when written with digits,
 * spaced out letter by letter or run together.
 */
class ProfanityFilterTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function ordinaryText(): array
    {
        return [
            'a temperature' => ['455 درجة'],
            'a price with a dollar sign' => ['السعر 45$'],
            'a flight number' => ['Flight 717'],
            'a phone number' => ['(717) 555-0100'],
            'the letter X on its own' => ['تابعونا على منصة X'],
            'x-ray' => ['X-ray'],
            'dimensions' => ['20 x 30 cm'],
            'authorizes' => ['هذا التصريح يخول حامله'],
            'a rescinded contract' => ['العقد مفسوخ'],
            'son of good people' => ['يا ابن الحلال ساعدني'],
            'quran 18:96' => ['آتُونِي زُبَرَ الْحَدِيدِ'],
            'latin honours' => ['She graduated magna cum laude'],
            'tit for tat' => ['It was tit for tat'],
            'a rooster' => ['cock-a-doodle-doo'],
            'an abbreviation' => ['Ass. Prof. Sara Ali'],
            'prick a finger' => ['Prick your finger with the needle'],
            'earbuds' => ['اير بودز'],
            'authorized' => ['خول القانون الوزير صلاحية التعيين'],
            'a metal rod' => ['وضع قضيب معدني في الباب'],
            'licking ice cream' => ['لحس الطفل الآيس كريم'],
            'he wronged him' => ['بغى عليه أخوه'],
            'a news report' => ['القبض على شبكة دعارة'],
            'an animal pen' => ['وضعنا الغنم في الزريبة'],
            'a colleague' => ['زامل الطلاب في الجامعة'],
            'the psalms' => ['وآتينا داوود زبور'],
            'marriage in a hadith' => ['لا ينكح المحرم ولا ينكح'],
            'a head injury' => ['راسه مفشوخ من الحادث'],
            'a brand name' => ['اشتريت حذاء نايك جديد'],
            'kisses' => ['Love you xxx'],
            'courage' => ['She has a lot of spunk'],
            'skin' => ['The horny layer of the skin'],
            'a cigarette' => ['Going outside for a fag'],
            'history' => ['William the Bastard became king'],
            'a donkey' => ['The jackass brayed in the field'],
            'a header name' => ['Log the X-Request-Id of failed requests'],
            'initials' => ['J R R Tolkien wrote it'],
        ];
    }

    #[DataProvider('ordinaryText')]
    public function test_ordinary_text_passes(string $text): void
    {
        $this->assertNull(app(ProfanityFilter::class)->firstMatch($text));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function explicitText(): array
    {
        return [
            'arabic word' => ['هذا نص شرموطة غير مقبول'],
            'arabic phrase' => ['كس امك'],
            'arabic letters spaced out' => ['ك س امك'],
            'a two-letter arabic word spaced out' => ['ز ب'],
            'arabic insult with the article' => ['يا ابن الشرموطة'],
            'son of a dog' => ['يا ابن الكلب'],
            'arabic possessive' => ['ايرك'],
            'arabizi' => ['kosomak'],
            'arabizi phrase' => ['koss omak'],
            'english phrase' => ['fuck you'],
            'english letters spaced out' => ['f u c k you'],
            'phrase run together' => ['fuckyou'],
            'digit for a letter' => ['sh1t happens'],
            'dollar for a letter' => ['$hit happens'],
            'digit inside a word' => ['fuck1ng idiot'],
            'zero for o' => ['p0rn videos'],
            'symbols inside a word' => ['what an a$$hole'],
            'repeated letters' => ['fuuuuck'],
            'english insult' => ['son of a bitch'],
            'cum outside the latin phrase' => ['cum'],
            'cum spaced out' => ['c u m'],
        ];
    }

    #[DataProvider('explicitText')]
    public function test_explicit_text_is_still_caught(string $text): void
    {
        $this->assertNotNull(app(ProfanityFilter::class)->firstMatch($text));
    }

    public function test_digits_read_as_letters_only_inside_words_that_have_letters(): void
    {
        config(['profanity.lists' => ['en' => ['ass']], 'profanity.allowed_phrases' => []]);
        $filter = new ProfanityFilter();

        $this->assertNotNull($filter->firstMatch('a55'));
        $this->assertNotNull($filter->firstMatch('a$$'));
        $this->assertNull($filter->firstMatch('455'));
        $this->assertNull($filter->firstMatch('45$'));
        $this->assertNull($filter->firstMatch('$455'));
    }

    public function test_a_term_collapsed_below_three_letters_is_dropped_and_a_letter_alone_never_matches(): void
    {
        config(['profanity.lists' => ['en' => ['xxx', 'ab'], 'ar' => ['كس']], 'profanity.allowed_phrases' => []]);
        $filter = new ProfanityFilter();

        $this->assertNull($filter->firstMatch('x'));
        $this->assertNull($filter->firstMatch('xxx'));
        $this->assertNull($filter->firstMatch('x x x'));
        $this->assertNull($filter->firstMatch('ab'), 'two Latin letters are an abbreviation, not a word');
        // Arabic writes no short vowels: a two-letter Arabic word is a whole word.
        $this->assertSame(['language' => 'ar', 'term' => 'كس'], $filter->firstMatch('كس'));
    }

    public function test_phrases_match_on_word_boundaries_only(): void
    {
        config(['profanity.lists' => ['ar' => ['يا ابن ال'], 'en' => ['fuck you']], 'profanity.allowed_phrases' => []]);
        $filter = new ProfanityFilter();

        $this->assertNull($filter->firstMatch('يا ابن الحلال ساعدني'), 'never across words');
        $this->assertNull($filter->firstMatch('fuck youth'));
        $this->assertNotNull($filter->firstMatch('well, fuck you!'));
        $this->assertNotNull($filter->firstMatch('fuckyou'));
    }

    public function test_allowed_phrases_are_taken_out_before_checking(): void
    {
        $filter = app(ProfanityFilter::class);

        $this->assertNull($filter->firstMatch('summa cum laude, then magna cum laude'));
        $this->assertNotNull($filter->firstMatch('cum laude and cum'), 'the word itself is still caught');
    }
}
