<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Support\SeoDialectPages;
use App\Support\SeoEnglishPages;
use App\Support\SeoHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeoProductClaimsTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_public_page_promises_a_free_plan_or_old_counts(): void
    {
        foreach (SeoHelper::pageDefinitions() as $page => $definition) {
            if (! ($definition['indexable'] ?? false)) {
                continue;
            }
            foreach (['en', 'ar'] as $locale) {
                $text = SeoHelper::title('', $page, $locale).' '.SeoHelper::description($page, $locale);
                $this->assertDoesNotMatchRegularExpression('/\bfree\b|مجان/iu', $text, "{$page} ({$locale}): {$text}");
                $this->assertDoesNotMatchRegularExpression('/\b50\b|\b18 English|خمسين|18 لكنة/u', $text, "{$page} ({$locale}): {$text}");
            }
        }
    }

    public function test_landing_page_faqs_and_ctas_make_no_free_plan_claims(): void
    {
        $pages = [
            ['en', 'seo.text-to-speech', []], ['ar', 'seo.text-to-speech', []],
            ['en', 'seo.text-to-speech-arabic', []], ['ar', 'seo.text-to-speech-arabic', []],
            ['en', 'seo.ai-text-to-speech', []], ['ar', 'seo.ai-text-to-speech', []],
            ['en', 'seo.dialect', ['dialect' => 'egyptian']], ['ar', 'seo.dialect', ['dialect' => 'chadian']],
            ['en', 'seo.accent', ['accent' => 'british']], ['ar', 'seo.accent', ['accent' => 'kenyan']],
            ['en', 'seo.accent', ['accent' => 'spanish-accent']], ['ar', 'seo.accent', ['accent' => 'japanese-accent']],
        ];

        foreach ($pages as [$locale, $route, $params]) {
            $html = $this->page($locale, $route, $params)->assertOk()->getContent();
            $this->assertDoesNotMatchRegularExpression('/free account|free plan|free to try|Free forever|حساب مجاني|حسابك المجاني|الخطة المجانية|جرّبه مجاناً/iu', $html, $route.' '.$locale);
        }
    }

    public function test_accent_pages_for_other_languages_render_with_breadcrumbs(): void
    {
        $html = $this->page('en', 'seo.accent', ['accent' => 'spanish-accent'])->assertOk()->getContent();
        $this->assertStringContainsString('<title>English Text to Speech with a Spanish Accent', $html);
        $this->assertStringContainsString('"name":"English accents"', $html);

        $ar = $this->page('ar', 'seo.accent', ['accent' => 'german-accent'])->assertOk()->getContent();
        $this->assertStringContainsString('تحويل النص إلى صوت إنجليزي بلكنة ألمانية', $ar);
    }

    public function test_the_sitemap_lists_the_new_language_dialect_and_accent_pages(): void
    {
        $xml = $this->get('/sitemap.xml')->assertOk()->getContent();

        $this->assertStringNotContainsString('/languages/', $xml);
        foreach (['chadian', 'south-sudanese', 'ahwazi', 'anatolian'] as $slug) {
            $this->assertContains($slug, SeoDialectPages::slugs());
            $this->assertStringContainsString('/ar/arabic-dialects/'.$slug.'<', $xml);
        }
        foreach (['pakistani', 'filipino', 'ghanaian', 'kenyan', 'french-accent', 'spanish-accent', 'german-accent', 'japanese-accent', 'chinese-accent'] as $slug) {
            $this->assertNotSame([], SeoEnglishPages::accents($slug), $slug);
            $this->assertStringContainsString('/en/english-accents/'.$slug.'<', $xml);
        }
        $this->assertSame('English Text to Speech with a French Accent', SeoEnglishPages::title('french-accent', 'en'));
    }

    public function test_the_price_shown_to_search_engines_starts_at_the_cheapest_paid_plan(): void
    {
        Plan::factory()->create(['price_cents' => 0, 'is_active' => true]);
        Plan::factory()->create(['price_cents' => 900, 'is_active' => true]);
        Plan::factory()->create(['price_cents' => 4900, 'is_active' => true]);

        $html = $this->page('en', 'pricing')->assertOk()->getContent();

        $this->assertStringContainsString('"lowPrice":"9.00"', $html);
        $this->assertStringContainsString('"offerCount":2', $html);
    }

    private function page(string $locale, string $route, array $params = []): \Illuminate\Testing\TestResponse
    {
        app()->setLocale($locale);

        return $this->getLocalizedRoute($route, $params);
    }
}
