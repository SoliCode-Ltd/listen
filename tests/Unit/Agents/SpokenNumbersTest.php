<?php

namespace Tests\Unit\Agents;

use App\Support\Agents\SpokenNumbers;
use PHPUnit\Framework\TestCase;

/**
 * A number an agent reads back digit by digit is written as the number, in
 * Arabic (every dialect's spelling) and English, and a phone number with
 * spaces keeps its order on an Arabic page.
 */
class SpokenNumbersTest extends TestCase
{
    public function test_arabic_digit_words_become_one_number(): void
    {
        $this->assertSame('رقمك هو 0501234567؟', SpokenNumbers::digits('رقمك هو صفر خمسة صفر واحد اثنين ثلاثة أربعة خمسة ستة سبعة؟'));
        // Egyptian spellings and groups read with commas.
        $this->assertSame('الرقم الأول: 01012345678. صح كده؟', SpokenNumbers::digits('الرقم الأول: صفر واحد صفر، واحد اتنين تلاتة، أربعة خمسة ستة، سبعة ثمانية. صح كده؟'));
        $this->assertSame('رقمك هو 03123456، ورح', SpokenNumbers::digits('رقمك هو صفر تلاتة واحد اتنين تلاتة أربعة خمسة ستة، ورح'));
        // Levantine and Gulf: تنين / ثنين (two), تمنية (eight).
        $this->assertSame('ورقمك هو 03123456، صح؟', SpokenNumbers::digits('ورقمك هو صفر تلاتة واحد تنين تلاتة أربعة خمسة ستة، صح؟'));
        $this->assertSame('رقمي 0528', SpokenNumbers::digits('رقمي صفر خمسة ثنين تمنية'));
        $this->assertSame('0507654321', SpokenNumbers::digits('صفر خمسة صفر سبعة ستة خمسة أربعة ثلاثة اثنين واحد'));
    }

    public function test_short_counts_and_ordinary_words_stay(): void
    {
        foreach (['الساعة أربعة تناسبني', 'ثلاث أربع مرات', 'يا ست الكل', 'خمس دقائق', 'one more thing', 'the first two minutes', 'five o\'clock'] as $text) {
            $this->assertSame($text, SpokenNumbers::digits($text));
        }
    }

    public function test_english_digit_words_keep_the_speakers_groups(): void
    {
        $this->assertSame('Let me confirm your number: 555 210 4876. Everything is set.', SpokenNumbers::digits('Let me confirm your number: five five five, two one zero, four eight seven six. Everything is set.'));
        $this->assertSame('your number is 07700 900 123?', SpokenNumbers::digits('your number is oh seven seven double oh, nine double oh, one two three?'));
        $this->assertSame('is 087 123 4567?', SpokenNumbers::digits('is zero eight seven, one two three, four five six seven?'));
        $this->assertSame('your number is 5552104876, correct?', SpokenNumbers::digits('your number is 5-5-5-2-1-0-4-8-7-6, correct?'));
    }

    public function test_a_spaced_number_is_kept_in_one_left_to_right_piece(): void
    {
        $this->assertSame("محمد · \u{2066}050 123 4567\u{2069} · الخميس 4 العصر", SpokenNumbers::isolate('محمد · 050 123 4567 · الخميس 4 العصر'));
        // Plain numbers need nothing.
        $this->assertSame('ورقمي 0501234567، و 100 جنيه', SpokenNumbers::isolate('ورقمي 0501234567، و 100 جنيه'));
    }

    public function test_a_line_for_the_page_loses_the_transcriptions_marks(): void
    {
        $this->assertSame('مع السلامة.', SpokenNumbers::forDisplay('مع السلامة.<no speech detected>', true));
        $this->assertSame('Have a great day!', SpokenNumbers::forDisplay('Have a great day!<no speech>{pause}'));
        $this->assertSame("محمد · \u{2066}050 123 4567\u{2069}", SpokenNumbers::forDisplay('محمد · 050 123 4567', true));
    }
}
