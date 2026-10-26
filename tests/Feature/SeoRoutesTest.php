<?php

namespace Tests\Feature;

use App\Models\SiteSetting;
use App\Support\ArabicDialects;
use App\Support\DialectCounts;
use App\Support\EnglishAccents;
use App\Support\SeoHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeoRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_robots_and_sitemap_routes_are_available(): void
    {
        $this->get('/robots.txt')
            ->assertOk()
            ->assertSee('Sitemap:', false);

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertSee('/en/pricing', false)
            ->assertSee('/ar/pricing', false)
            ->assertSee('<urlset', false)
            ->assertSee('<url>', false)
            ->assertSee('<lastmod>', false)
            ->assertSee('hreflang="ar"', false);
    }

    public function test_llms_route_is_available_by_default(): void
    {
        $this->get('/llms.txt')
            ->assertOk()
            ->assertSee('## Official URLs', false);
    }

    public function test_llms_txt_counts_the_spoken_dialects_and_offers_samples_not_every_voice(): void
    {
        $text = (string) $this->get('/llms.txt')->assertOk()->getContent();

        // The spoken dialects, as the dialect hub counts them, not every catalog row but one: Fusha and its styles are named apart.
        $this->assertStringContainsString('It speaks '.DialectCounts::spokenArabic().' spoken Arabic dialects from '
            .(count(ArabicDialects::COUNTRIES) - 1).' countries, Modern Standard Arabic in '.(count(ArabicDialects::ALL) - DialectCounts::spokenArabic())
            .' styles and '.count(EnglishAccents::ALL).' English accents', $text);
        $this->assertStringNotContainsString((count(ArabicDialects::ALL) - 1).' Arabic', $text);
        // The samples page plays the samples that were made, not one for every accent and dialect.
        $this->assertStringContainsString('samples of English accents, moods, conversations and sound environments, ready to play', $text);
        $this->assertStringContainsString('has samples of Arabic dialects', $text);
        $this->assertStringNotContainsString('every English accent', $text);
        $this->assertStringNotContainsString('every Arabic dialect', $text);
    }

    public function test_llms_route_can_be_disabled_from_dashboard_setting(): void
    {
        SiteSetting::set('enable_llms_txt', '0', 'seo');

        $this->get('/llms.txt')->assertNotFound();
    }

    public function test_sitemap_can_be_controlled_from_dashboard_setting(): void
    {
        SiteSetting::set('sitemap_paths', "/custom-path|0.6|monthly\nhttps://example.org/docs|0.4|weekly", 'seo');

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertSee('/custom-path', false)
            ->assertSee('https://example.org/docs', false);
    }

    public function test_sitemap_keeps_manual_entries_but_drops_noindex_pages(): void
    {
        SiteSetting::set('sitemap_paths', "/ar/demo|0.8|weekly\n/en/dashboard|0.8|weekly\n/en/private-docs|0.5|monthly", 'seo');

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertDontSee('/ar/demo<', false)
            ->assertDontSee('/en/dashboard<', false)
            ->assertSee('/en/private-docs', false);
    }

    public function test_sitemap_excluded_paths_setting_blocks_matching_urls(): void
    {
        SiteSetting::set('sitemap_paths', "/en/private-docs|0.5|monthly\n/en/open-docs|0.5|monthly", 'seo');
        SiteSetting::set('sitemap_excluded_paths', "/private-docs\n/internal", 'seo');

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertDontSee('/en/private-docs', false)
            ->assertSee('/en/open-docs', false);
    }

    public function test_sitemap_can_switch_back_to_indexable_only_mode(): void
    {
        SiteSetting::set('sitemap_include_non_indexable', '0', 'seo');

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertDontSee('/en/demo', false)
            ->assertDontSee('/ar/demo', false)
            ->assertSee('/en/pricing', false);
    }

    public function test_sitemap_per_page_overrides_can_be_set_from_dashboard(): void
    {
        SiteSetting::set('seo_pricing_sitemap_priority', '0.4', 'seo');
        SiteSetting::set('seo_pricing_sitemap_change_frequency', 'monthly', 'seo');

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertSee('/en/pricing', false)
            ->assertSee('<changefreq>monthly</changefreq>', false)
            ->assertSee('<priority>0.4</priority>', false);
    }

    public function test_home_seo_meta_can_be_overridden_from_dashboard_settings(): void
    {
        SiteSetting::set('seo_home_description_en', 'Custom home description for SEO.', 'seo');
        SiteSetting::set('seo_home_robots', 'index,follow', 'seo');
        SiteSetting::set('meta_keywords_en', 'tts,ai voice,text to speech', 'seo');

        $this->getLocalizedRoute('home')
            ->assertOk()
            ->assertSee('<meta name="description" content="Custom home description for SEO.">', false)
            ->assertSee('<meta name="robots" content="index,follow">', false)
            ->assertSee('<meta name="keywords" content="tts,ai voice,text to speech">', false);
    }

    public function test_canonical_hreflang_and_favicon_tags_are_rendered(): void
    {
        SiteSetting::set('canonical_url', 'https://listen.example', 'seo');

        $response = $this->getLocalizedRoute('home')
            ->assertOk()
            ->assertSee('<link rel="canonical" href="https://listen.example/en">', false)
            ->assertSee('<link rel="alternate" hreflang="en" href="https://listen.example/en">', false)
            ->assertSee('<link rel="alternate" hreflang="ar" href="https://listen.example/ar">', false)
            ->assertSee('<link rel="alternate" hreflang="x-default" href="https://listen.example/en">', false)
            ->assertSee('/favicon.svg?v=', false)
            ->assertSee('/site.webmanifest', false)
            ->assertSee('msapplication-config', false);

        $this->assertStringContainsString('/favicon.svg?v=', (string) $response->getContent());
    }

    public function test_site_webmanifest_route_is_available(): void
    {
        $this->get('/site.webmanifest')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/manifest+json; charset=UTF-8')
            ->assertJsonPath('start_url', '/en');
    }

    public function test_favicon_ico_redirects_to_the_current_brand_icon(): void
    {
        $response = $this->get('/favicon.ico')->assertStatus(301);

        // A bitmap, for the readers that ask for /favicon.ico and can't draw an SVG.
        $this->assertStringContainsString('/favicon.png?v=', (string) $response->headers->get('Location'));
    }

    public function test_public_seo_landing_pages_and_extension_page_are_available(): void
    {
        $this->getLocalizedRoute('seo.ai-text-to-speech')
            ->assertOk()
            ->assertSee('FAQPage', false);

        $this->getLocalizedRoute('seo.ai-voice-generator')
            ->assertOk()
            ->assertSee('FAQPage', false);

        $this->getLocalizedRoute('seo.text-to-speech-arabic')
            ->assertOk()
            ->assertSee('FAQPage', false);

        $this->getLocalizedRoute('browsers-extension.page')
            ->assertOk();
    }

    public function test_arabic_text_to_speech_page_has_english_and_arabic_versions(): void
    {
        SiteSetting::set('canonical_url', 'https://listen.example', 'seo');

        $this->assertSame([
            'en' => 'https://listen.example/en/text-to-speech-arabic',
            'ar' => 'https://listen.example/ar/text-to-speech-arabic',
            'x-default' => 'https://listen.example/en/text-to-speech-arabic',
        ], SeoHelper::hreflangMap('text_to_speech_arabic'));

        $this->getPage('/text-to-speech-arabic', 'ar')
            ->assertOk()
            ->assertSee('تحويل النص العربي إلى كلام', false)
            ->assertSee('<html lang="ar"', false);
    }

    public function test_robots_txt_is_served_by_the_app_not_a_static_file(): void
    {
        $this->assertFileDoesNotExist(public_path('robots.txt'), 'A static robots.txt would hide the sitemap line from crawlers.');

        $this->get('/robots.txt')->assertOk()->assertSee('Sitemap: ', false);
    }

    public function test_private_pages_stay_noindex_even_with_an_index_override(): void
    {
        SiteSetting::set('seo_login_robots', 'index,follow', 'seo');
        SiteSetting::set('seo_register_robots', 'index,follow', 'seo');
        SiteSetting::set('seo_dashboard_robots', 'index,follow', 'seo');

        $this->assertStringContainsString('noindex', SeoHelper::robots('login'));
        $this->assertStringContainsString('noindex', SeoHelper::robots('register'));
        $this->assertStringContainsString('noindex', SeoHelper::robots('dashboard'));
        $this->assertStringNotContainsString('noindex', SeoHelper::robots('pricing'));

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertDontSee('/en/login<', false)
            ->assertDontSee('/en/register<', false)
            ->assertDontSee('/en/dashboard<', false);
    }

    public function test_page_description_wins_over_the_site_wide_description(): void
    {
        SiteSetting::set('meta_description_ar', 'وصف عام للموقع كله.', 'seo');

        app()->setLocale('ar');

        $this->assertSame(__('ui.seo.pricing_description', [], 'ar'), SeoHelper::description('pricing', 'ar'));
    }

    public function test_dialect_pages_render_with_alternates_breadcrumbs_and_schema(): void
    {
        SiteSetting::set('canonical_url', 'https://listen.example', 'seo');

        $this->getPage('/arabic-dialects/egyptian')
            ->assertOk()
            ->assertSee('<h1>Egyptian Arabic Text to Speech with Real Dialect Voices</h1>', false)
            ->assertSee('<link rel="canonical" href="https://listen.example/en/arabic-dialects/egyptian">', false)
            ->assertSee('<link rel="alternate" hreflang="ar" href="https://listen.example/ar/arabic-dialects/egyptian">', false)
            ->assertSee('Cairene', false)
            ->assertSee('دلوقتي', false)
            ->assertSee('"@type":"BreadcrumbList"', false)
            ->assertSee('"@type":"SoftwareApplication"', false)
            ->assertSee('"@type":"FAQPage"', false);

        $this->getPage('/arabic-dialects/saudi', 'ar')
            ->assertOk()
            ->assertSee('تحويل النص إلى صوت باللهجة السعودية بالذكاء الاصطناعي', false)
            ->assertSee('سعودي (حجازي)', false);

        $this->getPage('/arabic-dialects/klingon')->assertNotFound();
    }

    public function test_dialect_hub_links_every_dialect_page(): void
    {
        $response = $this->getPage('/arabic-dialects')->assertOk();

        foreach (\App\Support\SeoDialectPages::slugs() as $slug) {
            $response->assertSee('/arabic-dialects/'.$slug.'"', false);
        }
    }

    public function test_new_pages_are_in_the_sitemap(): void
    {
        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertSee('/en/text-to-speech<', false)
            ->assertSee('/ar/text-to-speech<', false)
            ->assertSee('/ar/text-to-speech-arabic<', false)
            ->assertSee('/ar/arabic-dialects<', false)
            ->assertSee('/en/arabic-dialects/egyptian<', false)
            ->assertSee('/ar/arabic-dialects/moroccan<', false);
    }

    public function test_pages_have_a_default_share_image_in_their_language(): void
    {
        $this->getPage('/text-to-speech')
            ->assertOk()
            ->assertSee('images/og/listen-og-en.png', false)
            ->assertSee('<meta property="og:image:width" content="1200">', false);

        $this->getPage('/text-to-speech', 'ar')
            ->assertOk()
            ->assertSee('images/og/listen-og-ar.png', false);
    }

    public function test_footer_links_only_the_two_hubs(): void
    {
        $this->getLocalizedRoute('pricing')
            ->assertOk()
            ->assertSee('/arabic-dialects"', false)
            ->assertSee('/english-accents"', false)
            ->assertDontSee('/arabic-dialects/egyptian"', false)
            ->assertDontSee('/english-accents/american"', false)
            ->assertDontSee('/ai-voice-generator"', false);
    }

    public function test_the_hubs_link_every_solution_page(): void
    {
        foreach (['/arabic-dialects', '/english-accents'] as $hub) {
            $this->getPage($hub)
                ->assertOk()
                ->assertSee('/text-to-speech"', false)
                ->assertSee('/ai-text-to-speech"', false)
                ->assertSee('/text-to-speech-arabic"', false)
                ->assertSee('/ai-voice-generator"', false);
        }
    }

    public function test_arabic_dialect_hub_links_every_dialect_page(): void
    {
        $response = $this->getPage('/arabic-dialects')->assertOk();

        foreach (array_keys(\App\Support\SeoDialectPages::PAGES) as $slug) {
            $response->assertSee('/arabic-dialects/'.$slug.'"', false);
        }
    }

    public function test_english_accent_pages_render_in_both_languages(): void
    {
        SiteSetting::set('canonical_url', 'https://listen.example', 'seo');

        $this->getPage('/english-accents/british')
            ->assertOk()
            ->assertSee('<h1>British Accent Text to Speech</h1>', false)
            ->assertSee('<link rel="alternate" hreflang="ar" href="https://listen.example/ar/english-accents/british">', false)
            ->assertSee('British (Scottish)', false)
            ->assertSee('"@type":"BreadcrumbList"', false)
            ->assertSee('"@type":"FAQPage"', false);

        $this->getPage('/english-accents/american', 'ar')
            ->assertOk()
            ->assertSee('تحويل النص إلى صوت إنجليزي بلكنة أمريكية', false);

        $this->getPage('/english-accents/martian')->assertNotFound();
    }

    public function test_english_accent_hub_links_every_accent_page(): void
    {
        $response = $this->getPage('/english-accents')->assertOk();

        foreach (\App\Support\SeoEnglishPages::slugs() as $slug) {
            $response->assertSee('/english-accents/'.$slug.'"', false);
        }
    }

    public function test_every_public_page_exists_in_english_and_arabic(): void
    {
        foreach (SeoHelper::pageDefinitions() as $page => $definition) {
            if (! ($definition['indexable'] ?? false)) {
                continue;
            }

            $this->assertSame(['en', 'ar'], SeoHelper::pageLocales($page), "{$page} must have English and Arabic versions.");
        }
    }

    public function test_english_accent_pages_are_in_the_sitemap_and_the_footer_links_the_hub(): void
    {
        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertSee('/en/english-accents<', false)
            ->assertSee('/ar/english-accents/british<', false)
            ->assertSee('/en/english-accents/arabic-accent<', false);

        $this->getLocalizedRoute('pricing')
            ->assertOk()
            ->assertSee('/english-accents"', false);
    }

    private function getPage(string $path, string $locale = 'en'): \Illuminate\Testing\TestResponse
    {
        app()->setLocale($locale);

        return $this->withoutMiddleware([
            \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath::class,
        ])->get($path);
    }

    public function test_google_analytics_id_is_rendered_when_configured(): void
    {
        SiteSetting::set('google_analytics_id', 'G-TEST123456', 'seo');

        $this->getLocalizedRoute('home')
            ->assertOk()
            ->assertSee('https://www.googletagmanager.com/gtag/js?id=G-TEST123456', false)
            ->assertSee("gtag('config', 'G-TEST123456');", false);
    }

    public function test_google_tag_id_is_rendered_when_configured(): void
    {
        SiteSetting::set('google_tag_id', 'AW-18014537634', 'seo');

        $this->getLocalizedRoute('home')
            ->assertOk()
            ->assertSee('https://www.googletagmanager.com/gtag/js?id=AW-18014537634', false)
            ->assertSee("gtag('config', 'AW-18014537634');", false);
    }

    public function test_both_ids_are_rendered_when_configured_together(): void
    {
        SiteSetting::set('google_analytics_id', 'G-TEST123456', 'seo');
        SiteSetting::set('google_tag_id', 'AW-18014537634', 'seo');

        $this->getLocalizedRoute('home')
            ->assertOk()
            ->assertSee("gtag('config', 'G-TEST123456');", false)
            ->assertSee("gtag('config', 'AW-18014537634');", false);
    }

    public function test_custom_google_tag_head_code_overrides_analytics_id_script(): void
    {
        SiteSetting::set('google_analytics_id', 'G-TEST123456', 'seo');
        SiteSetting::set('google_tag_head_code', '<script>window.customGoogleTag = true;</script>', 'seo');

        $this->getLocalizedRoute('home')
            ->assertOk()
            ->assertSee('<script>window.customGoogleTag = true;</script>', false)
            ->assertDontSee('https://www.googletagmanager.com/gtag/js?id=G-TEST123456', false);
    }

    public function test_facebook_pixel_code_is_rendered_when_configured(): void
    {
        SiteSetting::set('facebook_pixel_code', '<script>window.fbPixelTest = true;</script><noscript><img src="https://www.facebook.com/tr?id=123&ev=PageView&noscript=1" /></noscript>', 'seo');

        $this->getLocalizedRoute('home')
            ->assertOk()
            ->assertSee('<script>window.fbPixelTest = true;</script>', false)
            ->assertSee('https://www.facebook.com/tr?id=123&ev=PageView&noscript=1', false);
    }

    public function test_title_without_explicit_locale_uses_current_arabic_locale(): void
    {
        app()->setLocale('ar');

        SiteSetting::set('seo_home_title_en', 'English Home Title', 'seo');
        SiteSetting::set('seo_home_title_ar', 'عنوان الصفحة الرئيسية', 'seo');
        SiteSetting::set('meta_title_template_en', '%page_title% - Listen', 'seo');
        SiteSetting::set('meta_title_template_ar', '%page_title% - منصة إسمع', 'seo');

        $title = SeoHelper::title('', 'home');

        $this->assertStringContainsString('عنوان الصفحة الرئيسية', $title);
        $this->assertStringContainsString('منصة إسمع', $title);
        $this->assertStringNotContainsString('English Home Title', $title);
    }

    public function test_arabic_locale_variants_use_arabic_seo_metadata(): void
    {
        app()->setLocale('ar_EG');

        SiteSetting::set('seo_pricing_title_en', 'Pricing', 'seo');
        SiteSetting::set('seo_pricing_title_ar', 'أسعار منصة إسمع', 'seo');
        SiteSetting::set('meta_title_template_ar', '%page_title% - منصة إسمع', 'seo');

        $this->assertSame('ar', SeoHelper::currentLocale());

        $title = SeoHelper::title('', 'pricing');

        $this->assertStringContainsString('أسعار منصة إسمع', $title);
        $this->assertStringContainsString('منصة إسمع', $title);
        $this->assertStringNotContainsString('Pricing - Listen', $title);
    }

    public function test_arabic_locale_variant_renders_arabic_title_in_response(): void
    {
        app()->setLocale('ar_EG');

        SiteSetting::set('seo_home_title_en', 'English Home Title', 'seo');
        SiteSetting::set('seo_home_title_ar', 'عنوان الصفحة الرئيسية', 'seo');
        SiteSetting::set('meta_title_template_ar', '%page_title% - منصة إسمع', 'seo');

        $this->getLocalizedRoute('home')
            ->assertOk()
            ->assertSee('<title>عنوان الصفحة الرئيسية - منصة إسمع</title>', false)
            ->assertDontSee('<title>English Home Title - Listen</title>', false);
    }
}
