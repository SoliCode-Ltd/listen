<?php

namespace Tests\Feature;

use App\Services\TranslationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A transcript's paragraphs are sent to the quick translator together, not one
 * after another: each good answer is kept where the paragraph-by-paragraph
 * translation looks first, so it then needs no request of its own. An answer
 * left partly in the original script is not kept (the careful path does it).
 */
class TranslationPrefetchTest extends TestCase
{
    public function test_paragraphs_are_translated_together_and_then_read_from_what_was_kept(): void
    {
        Cache::flush();
        Http::fake(function ($request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);
            $out = match ($q['q'] ?? '') {
                'مرحبا خديجة' => 'Hello Khadija',
                'كل شي جاهز' => 'Everything is ready',
                default => 'Half translated يا شباب',
            };

            return Http::response([[[$out, $q['q'] ?? '', null, null, 10]], null, 'ar']);
        });
        $translator = app(TranslationService::class);

        $translator->prefetch(['مرحبا خديجة', 'كل شي جاهز', 'يلا يا شباب', ''], 'ar', 'en');

        Http::assertSentCount(3);
        $this->assertSame('Hello Khadija', $translator->maybeTranslate('مرحبا خديجة', 'ar', 'en', true));
        $this->assertSame('Everything is ready', $translator->maybeTranslate('كل شي جاهز', 'ar', 'en', true));
        Http::assertSentCount(3);
        $this->assertNull(Cache::get('listen:tr:v4:'.sha1('ar|en|يلا يا شباب')), 'an answer still holding Arabic is not kept');

        // Asked again: nothing is sent for what is already kept.
        $translator->prefetch(['مرحبا خديجة', 'كل شي جاهز'], 'ar', 'en');
        Http::assertSentCount(3);
    }
}
