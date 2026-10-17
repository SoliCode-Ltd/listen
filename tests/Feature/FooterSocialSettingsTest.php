<?php

namespace Tests\Feature;

use App\Models\SiteSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FooterSocialSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_footer_social_links_are_hidden_when_disabled(): void
    {
        SiteSetting::set('footer_social_enabled', '0', 'general');
        SiteSetting::set('footer_social_twitter_url', 'https://twitter.com/listen', 'general');
        SiteSetting::set('footer_social_facebook_url', 'https://facebook.com/listen', 'general');
        SiteSetting::set('footer_social_github_url', 'https://github.com/listen', 'general');
        SiteSetting::set('footer_social_linkedin_url', 'https://www.linkedin.com/company/listen', 'general');

        $this->getLocalizedRoute('home')
            ->assertOk()
            ->assertDontSee('aria-label="Twitter"', false)
            ->assertDontSee('aria-label="Facebook"', false)
            ->assertDontSee('aria-label="GitHub"', false)
            ->assertDontSee('aria-label="LinkedIn"', false);
    }

    public function test_footer_social_links_render_with_valid_urls_including_facebook(): void
    {
        SiteSetting::set('footer_social_enabled', '1', 'general');
        SiteSetting::set('footer_social_twitter_url', 'https://twitter.com/listen', 'general');
        SiteSetting::set('footer_social_facebook_url', 'https://facebook.com/listen', 'general');
        SiteSetting::set('footer_social_github_url', 'https://github.com/listen', 'general');
        SiteSetting::set('footer_social_linkedin_url', 'https://www.linkedin.com/company/listen', 'general');

        $this->getLocalizedRoute('home')
            ->assertOk()
            ->assertSee('footer-social', false)
            ->assertSee('aria-label="Twitter"', false)
            ->assertSee('aria-label="Facebook"', false)
            ->assertSee('aria-label="GitHub"', false)
            ->assertSee('aria-label="LinkedIn"', false)
            ->assertSee('href="https://facebook.com/listen"', false);
    }

    public function test_footer_social_links_skip_invalid_urls(): void
    {
        SiteSetting::set('footer_social_enabled', '1', 'general');
        SiteSetting::set('footer_social_facebook_url', 'not-a-url', 'general');
        SiteSetting::set('footer_social_twitter_url', 'https://twitter.com/listen', 'general');

        $this->getLocalizedRoute('home')
            ->assertOk()
            ->assertSee('aria-label="Twitter"', false)
            ->assertDontSee('aria-label="Facebook"', false);
    }

    public function test_no_social_icons_show_until_an_account_is_set(): void
    {
        SiteSetting::set('footer_social_enabled', '1', 'general');
        SiteSetting::set('footer_social_twitter_url', '', 'general');
        SiteSetting::set('footer_social_facebook_url', '', 'general');
        SiteSetting::set('footer_social_github_url', '', 'general');
        SiteSetting::set('footer_social_linkedin_url', '', 'general');

        // An icon that goes nowhere looks unfinished.
        $this->getLocalizedRoute('home')
            ->assertOk()
            ->assertDontSee('aria-label="Twitter"', false)
            ->assertDontSee('aria-label="Facebook"', false)
            ->assertDontSee('aria-label="GitHub"', false)
            ->assertDontSee('aria-label="LinkedIn"', false);
    }
}
