<?php

namespace Tests\Feature;

use App\Models\SiteSetting;
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
            ->assertSee('/pricing', false);
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
        SiteSetting::set('seo_home_description', 'Custom home description for SEO.', 'seo');
        SiteSetting::set('seo_home_robots', 'index,follow', 'seo');
        SiteSetting::set('meta_keywords', 'tts,ai voice,text to speech', 'seo');

        $this->getLocalizedRoute('home')
            ->assertOk()
            ->assertSee('<meta name="description" content="Custom home description for SEO.">', false)
            ->assertSee('<meta name="robots" content="index,follow">', false)
            ->assertSee('<meta name="keywords" content="tts,ai voice,text to speech">', false);
    }
}
