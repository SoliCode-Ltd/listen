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
        // A hang-up the model wrote out instead of calling it (a test call on staging): nothing of it
        // shows, not even a stray brace; cut off before its closing tag, it still goes.
        $this->assertSame('مع السلامة.', SpokenNumbers::forDisplay("مع السلامة.\n<metadata>{\"action\": \"end_call\", \"arguments\": {\"reason\": \"done\"}}</metadata>", true));
        $this->assertSame('Goodbye.', SpokenNumbers::forDisplay('Goodbye. <metadata>{"action": "end_call", "arguments": {"reason"'));
        $this->assertSame('Thanks, bye.', SpokenNumbers::withoutMarks('Thanks, {"tool": {"name": "end_call"}}bye.'));
        $this->assertSame("محمد · \u{2066}050 123 4567\u{2069}", SpokenNumbers::forDisplay('محمد · 050 123 4567', true));
    }

    public function test_a_number_the_transcription_spaced_out_digit_by_digit_is_one_number(): void
    {
        // A test call: 01068121705, said as "صفر عشرة ستة…", came as single digits with spaces and
        // read backwards on the Arabic page ("5 0 7 1 2 1 8 6 10 0").
        $this->assertSame('01068121705', SpokenNumbers::digits('0 10 6 8 1 2 1 7 0 5'));
        $this->assertSame('رقمي 01068121705.', SpokenNumbers::digits('رقمي 0 1 0 6 8 1 2 1 7 0 5.'));
        // Egyptian mobile prefixes read as numbers, and Arabic-Indic digits.
        $this->assertSame('رقمي 01068121705', SpokenNumbers::digits('رقمي زيرو عشرة ستة تمانية واحد اتنين واحد سبعة صفر خمسة'));
        $this->assertSame('رقمي 01112345678', SpokenNumbers::digits('رقمي صفر حداشر واحد اتنين تلاتة أربعة خمسة ستة سبعة تمانية'));
        $this->assertSame('رقمي 01068121705', SpokenNumbers::digits('رقمي ٠١٠٦٨١٢١٧٠٥'));
        // What is not a phone number stays: groups the speaker made, a few small numbers, a time, years.
        foreach (['is 087 123 4567?', 'عندي 2 3 4 5 أسئلة', 'الساعة 10 30', 'من 2025 إلى 2026', 'الساعة عشرة'] as $text) {
            $this->assertSame($text, SpokenNumbers::digits($text));
        }
    }

    public function test_an_international_number_keeps_its_plus_sign_in_front_on_an_arabic_line(): void
    {
        $this->assertSame("رقمي \u{2066}+966501234567\u{2069}", SpokenNumbers::isolate('رقمي +966501234567'));
        $this->assertSame("رقمي \u{2066}+966501234567\u{2069}", SpokenNumbers::isolate("رقمي \u{2066}+966501234567\u{2069}"));
    }
}
