<?php

namespace Tests\Feature;

use App\Models\SiteSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pages that keep their own layout: the 404 page and sign in / register.
 * There is one site design; the old switchable New theme is gone.
 */
class SitePagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_old_new_theme_is_gone(): void
    {
        SiteSetting::set('site_design', 'new', 'general');

        $this->getLocalizedRoute('home')
            ->assertOk()
            ->assertDontSee('design-new', false)
            ->assertDontSee('class="nt"', false)
            ->assertDontSee('data-design="new"', false);
        $this->assertFileDoesNotExist(public_path('css/design-new.css'));
    }

    public function test_not_found_page_uses_the_listen_design(): void
    {
        $this->get('/en/this-page-does-not-exist-'.uniqid())
            ->assertNotFound()
            ->assertSee('nf-flatline', false)
            ->assertSee('lang="en"', false)
            ->assertSee(__('design.system.h404', [], 'en'), false);

        $this->get('/ar/this-page-does-not-exist-'.uniqid())
            ->assertNotFound()
            ->assertSee('lang="ar"', false)
            ->assertSee('dir="rtl"', false)
            ->assertSee(__('design.system.h404', [], 'ar'), false);
    }

    public function test_not_found_page_without_a_language_prefix_uses_the_main_language(): void
    {
        SiteSetting::set('site_main_language', 'ar', 'general');

        $this->get('/this-page-does-not-exist-'.uniqid())
            ->assertNotFound()
            ->assertSee('lang="ar"', false)
            ->assertSee('href="'.url('/ar').'"', false);
    }

    public function test_sign_in_and_register_use_the_split_layout(): void
    {
        $this->getLocalizedRoute('login')
            ->assertOk()
            ->assertSee('class="auth-split-body"', false)
            ->assertSee('css/auth.css', false)
            ->assertSee('class="auth-showcase"', false)
            ->assertSee('data-theme-toggle', false)
            ->assertSee('action="'.route('login.submit').'"', false)
            ->assertSee('id="email"', false)
            ->assertSee('id="password"', false)
            ->assertSee(route('legal.terms'), false)
            ->assertSee(__('design.auth.login_headline'), false);

        $this->getLocalizedRoute('register')
            ->assertOk()
            ->assertSee('class="auth-split-body"', false)
            ->assertSee('action="'.route('register.submit').'"', false)
            ->assertSee('id="password_confirmation"', false)
            ->assertSee(__('design.auth.register_headline'), false)
            ->assertSee(__('design.auth.perk_extension'), false)
            ->assertDontSee('auth-facts', false);
    }

    public function test_sign_in_and_register_show_the_speaker_cast_in_the_page_language(): void
    {
        app()->setLocale('en');
        $english = $this->getLocalizedRoute('login')->assertOk();
        $english->assertSee('class="vs-art vs-cast"', false)
            ->assertSee('css/voice-showcase.css', false)
            ->assertSee("Wait, is that my script? I typed it into Listen and it sounds amazing!")
            ->assertDontSee('كتبتها في إسمع وطلعت بلهجتي بالضبط!', false)
            ->assertDontSee('data-vp-track', false);

        app()->setLocale('ar');
        $this->getLocalizedRoute('register')->assertOk()
            ->assertSee('كتبتها في إسمع وطلعت بلهجتي بالضبط!', false)
            ->assertDontSee("Wait, is that my script");
    }
}
