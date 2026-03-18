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
            ->assertSee('/en/pricing', false)
            ->assertSee('/ar/pricing', false)
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
}
