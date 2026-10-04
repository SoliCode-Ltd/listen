<?php

namespace Tests\Feature;

use App\Models\SiteSetting;
use App\Models\User;
use App\Support\SiteDesign;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteDesignTest extends TestCase
{
    use RefreshDatabase;

    public function test_main_design_is_the_default_and_loads_nothing_extra(): void
    {
        $this->assertSame(SiteDesign::MAIN, SiteDesign::configured());

        $this->getLocalizedRoute('home')
            ->assertOk()
            ->assertDontSee('data-design="new"', false)
            ->assertDontSee('css/design-new.css', false)
            ->assertDontSee('id="ntHome"', false)
            ->assertDontSee('design-preview-badge', false);
    }

    public function test_new_design_loads_its_stylesheet_and_home_sections(): void
    {
        SiteSetting::set(SiteDesign::SETTING_KEY, SiteDesign::NEW, 'general');

        $this->getLocalizedRoute('home')
            ->assertOk()
            ->assertSee('class="nt" data-design="new" data-page="home"', false)
            ->assertSee('css/design-new.css', false)
            ->assertSee('id="ntHome"', false)
            ->assertSee(__('design.home.h1_line1'), false)
            ->assertSee(__('design.home.clone_title'), false)
            ->assertDontSee('design-preview-badge', false);
    }

    public function test_new_design_keeps_the_shared_header_controls(): void
    {
        SiteSetting::set(SiteDesign::SETTING_KEY, SiteDesign::NEW, 'general');

        $this->getLocalizedRoute('pricing')
            ->assertOk()
            ->assertSee('data-design="new"', false)
            ->assertSee('class="locale-switch', false)
            ->assertSee('id="themeToggle"', false)
            ->assertSee('id="mobileMenuToggle"', false)
            ->assertSee('class="mobile-locale-switch', false);
    }

    public function test_unknown_setting_values_fall_back_to_main(): void
    {
        SiteSetting::set(SiteDesign::SETTING_KEY, 'something-else', 'general');

        $this->assertSame(SiteDesign::MAIN, SiteDesign::configured());
        $this->getLocalizedRoute('home')
            ->assertOk()
            ->assertDontSee('data-design="new"', false);
    }

    public function test_admin_can_preview_the_new_design_without_changing_it_for_visitors(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->getWithDesignQuery('new')
            ->assertOk();

        $this->actingAs($admin)
            ->getLocalizedRoute('pricing')
            ->assertOk()
            ->assertSee('data-design="new"', false)
            ->assertSee('design-preview-badge', false);

        $this->assertSame(SiteDesign::MAIN, SiteDesign::configured());

        $this->actingAs($admin)
            ->getWithDesignQuery('reset')
            ->assertOk();

        $this->actingAs($admin)
            ->getLocalizedRoute('pricing')
            ->assertOk()
            ->assertDontSee('data-design="new"', false)
            ->assertDontSee('design-preview-badge', false);
    }

    public function test_admin_can_preview_the_main_design_while_new_is_live(): void
    {
        SiteSetting::set(SiteDesign::SETTING_KEY, SiteDesign::NEW, 'general');
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->getWithDesignQuery('main')
            ->assertOk()
            ->assertDontSee('data-design="new"', false)
            ->assertSee('design-preview-badge', false);
    }

    public function test_preview_query_is_ignored_for_visitors_and_regular_users(): void
    {
        $this->getWithDesignQuery('new')
            ->assertOk()
            ->assertDontSee('data-design="new"', false);

        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)
            ->getWithDesignQuery('new')
            ->assertOk()
            ->assertDontSee('data-design="new"', false);

        $this->actingAs($user)
            ->getLocalizedRoute('pricing')
            ->assertOk()
            ->assertDontSee('data-design="new"', false);
    }

    public function test_new_design_copy_exists_in_both_languages(): void
    {
        $en = require lang_path('en/design.php');
        $ar = require lang_path('ar/design.php');

        $this->assertSame(array_keys($en['home']), array_keys($ar['home']));
        foreach ($ar['home'] as $key => $value) {
            $this->assertNotSame('', trim((string) $value), "Arabic design.home.{$key} is empty");
        }
    }

    public function test_admin_site_settings_saves_the_design_choice(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);

        \Livewire\Livewire::test(\App\Filament\Pages\SiteSettings::class)
            ->assertSet('data.'.SiteDesign::SETTING_KEY, SiteDesign::MAIN)
            ->set('data.'.SiteDesign::SETTING_KEY, SiteDesign::NEW)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(SiteDesign::NEW, SiteDesign::configured());

        \Livewire\Livewire::test(\App\Filament\Pages\SiteSettings::class)
            ->assertSet('data.'.SiteDesign::SETTING_KEY, SiteDesign::NEW)
            ->set('data.'.SiteDesign::SETTING_KEY, SiteDesign::MAIN)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(SiteDesign::MAIN, SiteDesign::configured());
    }

    public function test_dashboard_sidebar_extras_render_only_in_the_new_design(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getLocalizedRoute('dashboard')
            ->assertOk()
            ->assertSee('data-dashboard-nav', false)
            ->assertDontSee('nt-plan-card', false);

        SiteSetting::set(SiteDesign::SETTING_KEY, SiteDesign::NEW, 'general');

        $this->actingAs($user)
            ->getLocalizedRoute('dashboard')
            ->assertOk()
            ->assertSee('data-dashboard-nav', false)
            ->assertSee('nt-plan-card', false)
            ->assertSee('id="ntPlanMeter"', false)
            ->assertSee(__('design.dashboard.upgrade'), false)
            ->assertSee(route('billing.page'), false);
    }

    public function test_new_dashboard_overview_keeps_every_live_element_exactly_once(): void
    {
        SiteSetting::set(SiteDesign::SETTING_KEY, SiteDesign::NEW, 'general');
        $user = User::factory()->create();

        $html = $this->actingAs($user)
            ->getLocalizedRoute('dashboard')
            ->assertOk()
            ->assertSee('data-nt-overview', false)
            ->getContent();

        foreach (['usageCharsText', 'usageCharsMeter', 'usageRequestsText', 'usageRequestsMeter', 'kpiRequests', 'kpiChars', 'kpiTotal',
            'statProcessing', 'statDone', 'statFailed', 'contentTextarea', 'generateBtn', 'voiceForm', 'commandCenterSection'] as $id) {
            $this->assertSame(1, substr_count($html, 'id="'.$id.'"'), "id {$id} should appear exactly once");
        }
    }

    public function test_not_found_page_uses_the_listen_design_in_both_themes(): void
    {
        foreach ([SiteDesign::MAIN, SiteDesign::NEW] as $design) {
            SiteSetting::set(SiteDesign::SETTING_KEY, $design, 'general');

            $this->get('/en/this-page-does-not-exist-'.uniqid())
                ->assertNotFound()
                ->assertSee('nt-flatline', false)
                ->assertSee('css/design-new.css', false)
                ->assertSee('lang="en"', false)
                ->assertSee(__('design.system.h404', [], 'en'), false);

            $this->get('/ar/this-page-does-not-exist-'.uniqid())
                ->assertNotFound()
                ->assertSee('lang="ar"', false)
                ->assertSee('dir="rtl"', false)
                ->assertSee(__('design.system.h404', [], 'ar'), false);
        }
    }

    public function test_not_found_page_without_a_language_prefix_uses_the_main_language(): void
    {
        SiteSetting::set('site_main_language', 'ar', 'general');

        $this->get('/this-page-does-not-exist-'.uniqid())
            ->assertNotFound()
            ->assertSee('lang="ar"', false)
            ->assertSee('href="'.url('/ar').'"', false);
    }

    public function test_unsubscribe_page_follows_the_selected_design(): void
    {
        $url = \Illuminate\Support\Facades\URL::signedRoute('newsletter.unsubscribe', ['email' => 'sara@example.com', 'lang' => 'ar']);

        $this->get($url)->assertOk()->assertDontSee('data-design="new"', false)->assertSee('sara@example.com');

        SiteSetting::set(SiteDesign::SETTING_KEY, SiteDesign::NEW, 'general');

        $this->get($url)
            ->assertOk()
            ->assertSee('data-design="new"', false)
            ->assertSee('sara@example.com')
            ->assertSee(__('design.system.unsub_title', [], 'ar'), false);
    }

    private function getWithDesignQuery(string $design): \Illuminate\Testing\TestResponse
    {
        return $this->withoutMiddleware([
            \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath::class,
        ])->get($this->localizedRoute('home').'?'.SiteDesign::PREVIEW_QUERY_KEY.'='.$design);
    }
}
