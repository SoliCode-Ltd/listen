<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * NVIDIA Inception, the one other company the site names (CLAUDE.md): the strip right under the homepage
 * hero and the badge in the footer, in English and Arabic, with NVIDIA's own badge file.
 */
class HomeInceptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_strip_sits_right_under_the_hero_in_both_languages(): void
    {
        app()->setLocale('en');
        $en = $this->getLocalizedRoute('home')->assertOk();
        $en->assertSee('Listen is a member of <b>NVIDIA Inception</b>, a program for startups building AI solutions.', false)
            ->assertSee('images/partners/nvidia-inception.svg', false)
            ->assertSee('alt="NVIDIA Inception Program"', false);
        $html = $en->getContent();
        $hero = strpos($html, '<section class="hero">');
        $strip = strpos($html, '<section class="inception-strip"');
        $this->assertNotFalse($strip);
        $this->assertGreaterThan($hero, $strip);
        // Nothing between the hero's end and the strip.
        $this->assertSame(0, substr_count(substr($html, $hero + 1, $strip - $hero), '<section'));

        app()->setLocale('ar');
        $this->getLocalizedRoute('home')->assertOk()
            ->assertSee('إسمع عضو في برنامج <b>إنسبشن من إنفيديا</b> للشركات الناشئة في مجال الذكاء الاصطناعي.', false);
    }

    public function test_the_footer_shows_the_badge_on_every_page(): void
    {
        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            $this->getLocalizedRoute('pricing')->assertOk()
                ->assertSee('class="footer-inception"', false)
                ->assertSee('images/partners/nvidia-inception.svg', false);
        }
    }

    public function test_the_badge_is_nvidias_own_file_framed_to_its_outline(): void
    {
        $svg = (string) file_get_contents(public_path('images/partners/nvidia-inception.svg'));
        $this->assertStringContainsString('viewBox="25.4 25.3 449.6 165.4"', $svg);
        // NVIDIA's green, unchanged.
        $this->assertStringContainsString('#76b900', $svg);
    }
}
