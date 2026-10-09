<?php

namespace Tests\Feature;

use App\Models\SiteSetting;
use App\Support\SiteFont;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteFontTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_whole_site_uses_alexandria_by_default(): void
    {
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            foreach (['home', 'pricing', 'login'] as $route) {
                $this->getLocalizedRoute($route)->assertOk()
                    ->assertSee('family=Alexandria:wght@300..800', false)
                    ->assertSee("--site-font: 'Alexandria'", false)
                    // Applied to every element, buttons and form fields included.
                    ->assertSee('font-family: var(--site-font) !important', false)
                    ->assertDontSee('family=IBM+Plex', false);
            }
        }
    }

    public function test_the_font_chosen_in_the_admin_is_applied_and_unknown_fonts_fall_back(): void
    {
        SiteSetting::set('site_font', 'Cairo');
        app()->setLocale('ar');
        $this->getLocalizedRoute('home')->assertOk()
            ->assertSee('family=Cairo:wght@300..800', false)
            ->assertSee("--site-font: 'Cairo'", false)
            ->assertDontSee('family=Alexandria', false);

        SiteSetting::set('site_font', 'Comic Sans; } body { color: red');
        $this->assertSame(SiteFont::DEFAULT, SiteFont::current());
    }

    public function test_every_font_option_has_weights_to_load(): void
    {
        foreach (SiteFont::OPTIONS as $font => $weights) {
            $this->assertMatchesRegularExpression('/^wght@\d{3}(\.\.\d{3}|(;\d{3})+)$/', $weights, $font);
        }
    }
}
