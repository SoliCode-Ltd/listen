<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\SiteSetting;
use App\Support\SeoDialectPages;
use App\Support\SeoFeaturePages;
use App\Support\SeoHelper;
use App\Support\StudioFacts;
use App\Support\Transcription\TranscriptionSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The pages for what people ask search engines and AI assistants by name: a whole region
 * ("Gulf Arabic text to speech", "الدارجة") and a studio feature ("text to speech that
 * laughs and whispers", "two-voice podcast", "voice cloning in Arabic dialects").
 */
class SeoFeatureAndRegionPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_region_pages_list_their_countries_and_accents_in_both_languages(): void
    {
        $gulf = (string) $this->page('en', 'seo.dialect-region', ['region' => 'gulf'])->assertOk()->getContent();
        $this->assertStringContainsString('<title>Gulf Arabic (Khaleeji) Text to Speech', $gulf);
        $accents = SeoDialectPages::regionAccentCount('gulf');
        $this->assertGreaterThan(20, $accents);
        $this->assertStringContainsString($accents.' accents from Saudi Arabia, ', $gulf);
        // Its country pages, and the breadcrumb back to all dialects.
        foreach (['saudi', 'emirati', 'kuwaiti', 'qatari', 'bahraini', 'omani'] as $country) {
            $this->assertStringContainsString('/arabic-dialects/'.$country.'"', $gulf);
        }
        $this->assertStringNotContainsString('/arabic-dialects/egyptian"><', $gulf);
        $this->assertStringContainsString('"name":"Arabic dialects"', $gulf);

        $levant = (string) $this->page('ar', 'seo.dialect-region', ['region' => 'levantine'])->assertOk()->getContent();
        $this->assertStringContainsString('تحويل النص إلى صوت باللهجة الشامية بالذكاء الاصطناعي', $levant);
        $this->assertStringContainsString('لبنان', $levant);

        $maghreb = (string) $this->page('en', 'seo.dialect-region', ['region' => 'maghrebi'])->assertOk()->getContent();
        $this->assertStringContainsString('<title>Maghrebi Arabic and Darija Text to Speech', $maghreb);
        foreach (['moroccan', 'algerian', 'tunisian', 'libyan', 'mauritanian'] as $country) {
            $this->assertStringContainsString('/arabic-dialects/'.$country.'"', $maghreb);
        }

        // The Moroccan page carries the name people search for.
        $this->assertSame('Moroccan Arabic (Darija) Text to Speech', SeoDialectPages::title('moroccan', 'en'));
        $this->assertStringContainsString('(الدارجة)', SeoDialectPages::title('moroccan', 'ar'));
        // A country page still opens, and links to the regions.
        $saudi = (string) $this->page('ar', 'seo.dialect', ['dialect' => 'saudi'])->assertOk()->getContent();
        $this->assertStringContainsString('/arabic-dialects/gulf"', $saudi);
    }

    public function test_the_emotions_page_quotes_the_studios_own_numbers(): void
    {
        $en = (string) $this->page('en', 'seo.feature', ['feature' => 'text-to-speech-with-emotions'])->assertOk()->getContent();
        $this->assertStringContainsString('<title>Text to Speech with Emotions, Laughs and Whispers', $en);
        $this->assertStringContainsString(StudioFacts::soundTags().' sounds you write into the text', $en);
        $this->assertStringContainsString(StudioFacts::emotions().' emotions and '.StudioFacts::styles().' performance styles', $en);
        $this->assertStringContainsString('&lt;laugh&gt;', $en);
        // Its questions are marked up for search engines.
        $this->assertStringContainsString('"@type":"FAQPage"', $en);
        $this->assertStringContainsString('How do I add a laugh or a whisper to the text?', $en);

        $ar = (string) $this->page('ar', 'seo.feature', ['feature' => 'text-to-speech-with-emotions'])->assertOk()->getContent();
        $this->assertStringContainsString('تحويل النص إلى صوت بالمشاعر والضحك والهمس', $ar);
        $this->assertStringContainsString('&lt;ضحكة&gt;', $ar);
    }

    public function test_a_plan_feature_has_a_page_only_while_a_plan_on_sale_includes_it(): void
    {
        $this->page('en', 'seo.feature', ['feature' => 'two-voice-dialogue'])->assertNotFound();
        $this->page('en', 'seo.feature', ['feature' => 'voice-cloning'])->assertNotFound();
        $xml = (string) $this->get('/sitemap.xml')->getContent();
        $this->assertStringNotContainsString('/two-voice-dialogue<', $xml);
        $this->assertStringNotContainsString('/voice-cloning<', $xml);
        $this->assertStringNotContainsString('/two-voice-dialogue)', (string) $this->get('/llms.txt')->getContent());

        Plan::factory()->create(['slug' => 'studio', 'name_en' => 'Studio', 'name_ar' => 'ستوديو', 'price_cents' => 4999,
            'dialogue_enabled' => true, 'voice_cloning_enabled' => true, 'voice_cloning_max_voices' => 10]);
        Plan::factory()->create(['slug' => 'pro', 'name_en' => 'Pro', 'price_cents' => 1999,
            'dialogue_enabled' => false, 'voice_cloning_enabled' => true, 'voice_cloning_max_voices' => 3]);
        // A plan not on sale says nothing.
        Plan::factory()->create(['slug' => 'old', 'name_en' => 'Old', 'price_cents' => 999, 'is_active' => false, 'dialogue_enabled' => true]);

        $dialogue = (string) $this->page('en', 'seo.feature', ['feature' => 'two-voice-dialogue'])->assertOk()->getContent();
        $this->assertStringContainsString('<title>Two-Voice Dialogue and Podcast AI Voice Generator', $dialogue);
        $this->assertStringContainsString('Two-voice dialogue is included in the Studio plan.', $dialogue);
        $this->assertStringNotContainsString('Old', strip_tags($dialogue));

        $cloning = (string) $this->page('en', 'seo.feature', ['feature' => 'voice-cloning'])->assertOk()->getContent();
        $this->assertStringContainsString('3–10 voices, depending on the plan.', $cloning);
        $ar = (string) $this->page('ar', 'seo.feature', ['feature' => 'voice-cloning'])->assertOk()->getContent();
        $this->assertStringContainsString('من 3 إلى 10 أصوات حسب الباقة.', $ar);
        // Each feature page links to the others.
        $this->assertStringContainsString('/text-to-speech-with-emotions"', $cloning);
        $this->assertStringContainsString('/two-voice-dialogue"', $cloning);

        // (The sitemap is kept for an hour; a plan change shows in the next one.)
        Cache::forget(SeoHelper::SITEMAP_CACHE);
        $xml = (string) $this->get('/sitemap.xml')->getContent();
        foreach (['/en/two-voice-dialogue<', '/ar/voice-cloning<', '/en/text-to-speech-with-emotions<', '/ar/arabic-dialects/gulf<', '/en/arabic-dialects/maghrebi<'] as $url) {
            $this->assertStringContainsString($url, $xml);
        }
        $llms = (string) $this->get('/llms.txt')->getContent();
        $this->assertStringContainsString('## Studio features', $llms);
        $this->assertStringContainsString('/en/two-voice-dialogue)', $llms);
        $this->assertStringContainsString('/en/arabic-dialects/levantine)', $llms);
    }

    public function test_the_new_pages_name_no_other_company_and_promise_nothing_free(): void
    {
        Plan::factory()->create(['slug' => 'studio', 'name_en' => 'Studio', 'price_cents' => 4999,
            'dialogue_enabled' => true, 'voice_cloning_enabled' => true, 'voice_cloning_max_voices' => 10,
            'transcription_enabled' => true, 'transcription_minutes_month' => 1000]);
        SiteSetting::set('transcription_audience', TranscriptionSettings::AUDIENCE_EVERYONE, TranscriptionSettings::GROUP);
        $pages = array_merge(
            array_map(fn (string $slug): array => ['seo.feature', ['feature' => $slug]], SeoFeaturePages::slugs()),
            array_map(fn (string $slug): array => ['seo.dialect-region', ['region' => $slug]], SeoDialectPages::regionSlugs()),
        );
        foreach ($pages as [$route, $parameters]) {
            foreach (['en', 'ar'] as $locale) {
                $html = (string) $this->page($locale, $route, $parameters)->assertOk()->getContent();
                $main = strip_tags((string) preg_replace('#<(script|style)\b.*?</\1>#s', '', $html));
                $this->assertDoesNotMatchRegularExpression('/gemini|google|eleven ?labs|azure|polly|lahajati|لهجاتي|nabarati|munsit/iu', $main, "{$route} {$locale}");
                $this->assertDoesNotMatchRegularExpression('/\bfree (plan|trial)\b|مجاناً|مجانا/iu', $main, "{$route} {$locale}");
            }
        }
        $this->assertNotSame('', SeoHelper::title('', SeoFeaturePages::pageKey('voice-cloning'), 'ar'));
    }

    /**
     * Speech to text is told to search engines and AI tools only once it is public: open to
     * everyone (Speech to text → Settings) and on a plan on sale. Until then its page is not
     * found and the sitemap, llms.txt, the API description and the assistant tools say nothing.
     */
    public function test_speech_to_text_is_announced_only_once_it_is_public(): void
    {
        Plan::factory()->create(['slug' => 'pro', 'name_en' => 'Pro', 'name_ar' => 'احترافي', 'price_cents' => 4900,
            'transcription_enabled' => true, 'transcription_minutes_month' => 500, 'transcription_max_file_minutes' => 120]);
        $announced = function (): array {
            Cache::forget(SeoHelper::SITEMAP_CACHE);
            $llms = (string) $this->get('/llms.txt')->getContent();

            return [
                'page' => $this->page('en', 'seo.feature', ['feature' => 'speech-to-text'])->status(),
                'sitemap' => str_contains((string) $this->get('/sitemap.xml')->getContent(), '/en/speech-to-text<'),
                'llms' => str_contains($llms, '/en/speech-to-text') || str_contains(strtolower($llms), 'speech to text'),
                'openapi' => array_key_exists('/transcriptions', (array) $this->get('/v1/openapi.json')->json('paths')),
            ];
        };

        // Shown to the admin only (the default): nothing is announced.
        $this->assertSame(['page' => 404, 'sitemap' => false, 'llms' => false, 'openapi' => false], $announced());

        // Open to everyone but on no plan on sale: still nothing.
        SiteSetting::set('transcription_audience', TranscriptionSettings::AUDIENCE_EVERYONE, TranscriptionSettings::GROUP);
        Plan::query()->update(['is_active' => false]);
        $this->assertSame(['page' => 404, 'sitemap' => false, 'llms' => false, 'openapi' => false], $announced());

        // Public: the page, the sitemap, llms.txt and the API description all say it.
        Plan::query()->update(['is_active' => true]);
        $this->assertSame(['page' => 200, 'sitemap' => true, 'llms' => true, 'openapi' => true], $announced());

        $page = (string) $this->page('en', 'seo.feature', ['feature' => 'speech-to-text'])->getContent();
        $this->assertStringContainsString('500 minutes', $page);
        $this->assertStringContainsString('"FAQPage"', $page);
        $this->assertStringContainsString('hreflang="ar"', $page);
        $llms = (string) $this->get('/llms.txt')->getContent();
        $this->assertStringContainsString('/en/speech-to-text', $llms);
        $this->assertStringContainsString('Pro', $llms);
        $arabic = (string) $this->page('ar', 'seo.feature', ['feature' => 'speech-to-text'])->assertOk()->getContent();
        $this->assertStringContainsString('تحويل الصوت إلى نص', $arabic);
    }

    public function test_the_basic_plan_is_described_by_its_use_not_as_light_use(): void
    {
        $basic = Plan::factory()->create(['slug' => 'basic-light', 'name_en' => 'Basic', 'price_cents' => 400,
            'description_en' => 'The premium voice for light use, in your dashboard and the browser extension.',
            'description_ar' => 'الصوت المتميز للاستخدام الخفيف، في لوحة التحكم وإضافة المتصفح.']);
        $edited = Plan::factory()->create(['slug' => 'pro-edited', 'name_en' => 'Pro', 'price_cents' => 1999, 'description_en' => 'Written by the team.']);

        (require database_path('migrations/2026_11_30_120200_basic_plan_described_by_use_not_light_use.php'))->up();

        $this->assertSame('Premium voices for personal projects, in your dashboard and the browser extension.', $basic->fresh()->description_en);
        $this->assertSame('أصوات متميزة لمشاريعك الشخصية، في لوحة التحكم وإضافة المتصفح.', $basic->fresh()->description_ar);
        $this->assertSame('Written by the team.', $edited->fresh()->description_en);
    }

    private function page(string $locale, string $route, array $parameters = []): TestResponse
    {
        app()->setLocale($locale);

        return $this->getLocalizedRoute($route, $parameters);
    }
}
