<?php

namespace Tests\Feature;

use App\Services\VoiceCloningService;
use App\Support\ArabicDialects;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The dialect rewrite runs before every Arabic premium generation, so it decides
 * much of the wait: Fusha that needs no rewrite skips it, long scripts go in pieces at once.
 */
class DialectRewriteSpeedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.gemini_tts.api_key' => 'test-key']);
    }

    public function test_colloquial_words_are_recognised_and_fusha_is_not(): void
    {
        $this->assertFalse(ArabicDialects::looksColloquial('أعلنت الحكومة اليوم عن خطة جديدة لدعم الدول العربية في مجال التعليم، وقد عاد الوزير من رحلته.'));
        $this->assertFalse(ArabicDialects::looksColloquial('في ذلك الحين كانت هوايته القراءة، وكان بحاجة إلى وقت خالص للتأمل.'));
        $this->assertTrue(ArabicDialects::looksColloquial('أنا عايز أروح دلوقتي'));
        $this->assertTrue(ArabicDialects::looksColloquial('شلونك اليوم؟'));
        $this->assertTrue(ArabicDialects::looksColloquial('شو بدك تعمل؟'));
        $this->assertTrue(ArabicDialects::looksColloquial('ومش عارف'), 'with a joined و');
        $this->assertTrue(ArabicDialects::looksColloquial('عَايِز'), 'diacritics are ignored');
        $this->assertTrue(ArabicDialects::isPlainFusha('ar-XA'));
        $this->assertTrue(ArabicDialects::isPlainFusha('ar_xa_nw'));
        $this->assertFalse(ArabicDialects::isPlainFusha('ar-XA-CL'));
        $this->assertFalse(ArabicDialects::isPlainFusha('ar-EG'));
    }

    public function test_fusha_script_for_fusha_skips_the_rewrite(): void
    {
        Http::fake();
        $text = 'أعلنت الحكومة اليوم عن خطة جديدة لدعم التعليم.';

        $this->assertSame($text, app(VoiceCloningService::class)->speakableText($text, 'ar-XA'));
        Http::assertNothingSent();
    }

    public function test_colloquial_script_for_fusha_and_other_registers_are_still_rewritten(): void
    {
        Http::fake(fn () => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'أريد أن أذهب الآن']]]]]]));

        $this->assertSame('أريد أن أذهب الآن', app(VoiceCloningService::class)->speakableText('أنا عايز أروح دلوقتي', 'ar-XA'));
        app(VoiceCloningService::class)->speakableText('أعلنت الحكومة اليوم عن خطة جديدة.', 'ar-XA-CL');
        Http::assertSentCount(2);
    }

    public function test_a_long_script_is_rewritten_in_pieces_at_once_and_joined_in_order(): void
    {
        $prompts = [];
        $levels = [];
        Http::fake(function ($request) use (&$prompts, &$levels) {
            $prompt = $request->data()['contents'][0]['parts'][0]['text'];
            $prompts[] = $prompt;
            $levels[] = $request->data()['generationConfig']['thinkingConfig']['thinkingLevel'] ?? null;
            $transcript = substr($prompt, strrpos($prompt, "Transcript:\n") + strlen("Transcript:\n"));

            return Http::response(['candidates' => [['content' => ['parts' => [['text' => str_replace('أريد', 'عايز', $transcript)]]]]]]);
        });
        $sentences = [];
        for ($i = 1; $i <= 12; $i++) {
            $sentences[] = "الجملة رقم {$i}: أريد أن أذهب إلى السوق لأشتري الخبز والحليب والفاكهة الطازجة لأسرتي الكبيرة اليوم.";
        }
        $text = implode(' ', $sentences);

        $out = app(VoiceCloningService::class)->speakableText($text, 'ar-EG');

        $this->assertGreaterThanOrEqual(2, count($prompts), 'sent in pieces');
        foreach ($prompts as $prompt) {
            $this->assertLessThanOrEqual(600, mb_strlen(substr($prompt, strrpos($prompt, "Transcript:\n") + strlen("Transcript:\n"))));
        }
        $this->assertSame(array_fill(0, count($prompts), 'low'), $levels);
        $this->assertSame(str_replace('أريد', 'عايز', $text), $out, 'every piece back, in order, with the spaces between them');
    }

    public function test_a_long_dialogue_block_keeps_one_line_per_line_across_pieces(): void
    {
        Http::fake(function ($request) {
            $prompt = $request->data()['contents'][0]['parts'][0]['text'];
            $transcript = substr($prompt, strrpos($prompt, "Transcript:\n") + strlen("Transcript:\n"));

            return Http::response(['candidates' => [['content' => ['parts' => [['text' => str_replace('كيف', 'إزاي', $transcript)]]]]]]);
        });
        $lines = [];
        for ($i = 1; $i <= 14; $i++) {
            $lines[] = "كيف حالك اليوم يا صديقي العزيز؟ هذا هو السطر رقم {$i} من الحوار الطويل بيننا.";
        }

        $out = app(VoiceCloningService::class)->speakableText(implode("\n", $lines), 'ar-EG');

        $this->assertSame(array_map(fn ($l) => str_replace('كيف', 'إزاي', $l), $lines), explode("\n", $out));
    }

    public function test_a_piece_that_fails_is_sent_again_on_its_own(): void
    {
        $calls = 0;
        Http::fake(function ($request) use (&$calls) {
            $calls++;
            $prompt = $request->data()['contents'][0]['parts'][0]['text'];
            $transcript = substr($prompt, strrpos($prompt, "Transcript:\n") + strlen("Transcript:\n"));
            if ($calls === 1) {
                return Http::response(['error' => ['message' => 'overloaded']], 503);
            }

            return Http::response(['candidates' => [['content' => ['parts' => [['text' => '✓'.$transcript]]]]]]);
        });
        $text = implode(' ', array_fill(0, 12, 'أريد أن أذهب إلى السوق لأشتري الخبز والحليب والفاكهة الطازجة لأسرتي الكبيرة اليوم.'));

        $out = app(VoiceCloningService::class)->speakableText($text, 'ar-EG');

        $pieces = substr_count($out, '✓');
        $this->assertStringStartsWith('✓', $out);
        $this->assertGreaterThanOrEqual(2, $pieces, 'every piece came back');
        $this->assertSame($pieces + 1, $calls, 'only the failed piece was sent twice');
    }

    public function test_the_script_stays_as_written_when_a_piece_cannot_be_rewritten(): void
    {
        Http::fake(function ($request) {
            $prompt = $request->data()['contents'][0]['parts'][0]['text'];
            if (str_contains($prompt, 'الثالثة')) {
                throw new ConnectionException('cURL error 28: Operation timed out');
            }

            return Http::response(['candidates' => [['content' => ['parts' => [['text' => 'مكتوب من جديد']]]]]]);
        });
        $parts = [];
        foreach (['الأولى', 'الثانية', 'الثالثة'] as $n) {
            $parts[] = str_repeat("هذه هي الفقرة {$n} وأريد أن أقرأها بصوت واضح. ", 8);
        }
        $text = trim(implode("\n", $parts));

        $this->assertSame($text, app(VoiceCloningService::class)->speakableText($text, 'ar-EG'));
    }
}
