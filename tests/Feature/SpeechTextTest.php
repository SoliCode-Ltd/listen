<?php

namespace Tests\Feature;

use App\Models\SiteSetting;
use App\Support\SpeechText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Before a voice reads, an abbreviation whose dot would read as the end of a
 * sentence is written out, and doubled punctuation is made single. Anything
 * unsure is left as written, and a full stop that may end a sentence stays.
 */
class SpeechTextTest extends TestCase
{
    use RefreshDatabase;

    public function test_arabic_abbreviations_are_said_in_full(): void
    {
        $this->assertSame(
            'قابلت دكتور أحمد الساعة ٩ مساءً. ثم سافرنا عام ٢٠٢٤ ميلادي.',
            SpeechText::smooth('قابلت د. أحمد الساعة ٩ م. ثم سافرنا عام ٢٠٢٤م.'),
        );
        $this->assertSame(
            'ولد سنة ٥٠٠ قبل الميلاد وعاش في الشام. راسلنا على صندوق بريد ١٢٣.',
            SpeechText::smooth('ولد سنة ٥٠٠ ق.م وعاش في الشام. راسلنا على ص.ب ١٢٣.'),
        );
        $this->assertSame('الساعة 10:30 صباحاً وعام ١٤٤٦ هجري', SpeechText::smooth('الساعة 10:30 ص وعام ١٤٤٦ هـ'));
        $this->assertSame('خضار وفواكه إلى آخره. ثم رجعنا', SpeechText::smooth('خضار وفواكه إلخ. ثم رجعنا'));
        $this->assertSame('وقال ودكتور سمير', SpeechText::smooth('وقال ود. سمير'));
    }

    public function test_unsure_arabic_is_left_as_written(): void
    {
        foreach (['مدة الفيلم ٩٠ د. فقط', 'عدد. كبير', 'الساعة ٩ مع أحمد', 'م. أحمد مهندس'] as $text) {
            $this->assertSame($text, SpeechText::smooth($text));
        }
    }

    public function test_english_abbreviations_and_doubled_marks(): void
    {
        $this->assertSame(
            'Dr Lee said, for example at 9 PM today, et cetera and more?',
            SpeechText::smooth('Dr. Lee said, e.g. at 9 p.m. today, etc. and more??'),
        );
        $this->assertSame('See you at 9 AM. Then we go… Wow!', SpeechText::smooth('See you at 9 a.m. Then we go..... Wow!!!'));
        $this->assertSame('Martin Luther King Jr. He was born.', SpeechText::smooth('Martin Luther King Jr. He was born.'));
        $this->assertSame('حقاً؟ نعم، أكيد', SpeechText::smooth('حقاً؟؟ نعم،، أكيد'));
    }

    public function test_tags_are_never_touched(): void
    {
        $this->assertSame('<laugh> Dr Lee [e.g. calm] wait!', SpeechText::smooth('<laugh> Dr. Lee [e.g. calm] wait!!'));
    }

    public function test_it_can_be_switched_off(): void
    {
        SiteSetting::set(SpeechText::SETTING, '0');

        $this->assertSame('Dr. Lee!!', SpeechText::smooth('Dr. Lee!!'));
    }
}
