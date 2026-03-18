<?php

namespace Tests\Feature;

use App\Models\SiteSetting;
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

    public function test_sitemap_keeps_manual_entries_when_not_excluded(): void
    {
        SiteSetting::set('sitemap_paths', "/ar/demo|0.8|weekly\n/en/dashboard|0.8|weekly\n/en/private-docs|0.5|monthly", 'seo');

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertSee('/ar/demo', false)
            ->assertSee('/en/dashboard', false)
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

        $this->assertStringContainsString('/favicon.svg?v=', (string) $response->headers->get('Location'));
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

    public function test_hreflang_map_for_english_only_page_excludes_arabic_variant(): void
    {
        SiteSetting::set('canonical_url', 'https://listen.example', 'seo');

        $map = SeoHelper::hreflangMap('text_to_speech_arabic');

        $this->assertSame([
            'en' => 'https://listen.example/en/text-to-speech-arabic',
            'x-default' => 'https://listen.example/en/text-to-speech-arabic',
        ], $map);
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
