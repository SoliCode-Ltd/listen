<?php

namespace Tests\Feature;

use App\Filament\Pages\SeoSettings;
use App\Models\SiteSetting;
use App\Models\User;
use App\Support\SeoHelper;
use App\Support\SeoSettingDefaults;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The admin's SEO settings page shows the site's own titles, descriptions,
 * llms.txt and landing content, and saves only what was changed: a field left
 * as it was keeps following the site's text when that text improves.
 */
class SeoSettingsSaveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_saving_the_page_unchanged_keeps_no_copy_of_the_sites_own_text(): void
    {
        Livewire::test(SeoSettings::class)
            ->assertSet('data.seo_pricing_description_en', __('ui.seo.pricing_description', [], 'en'))
            ->assertSet('data.seo_pricing_description_ar', __('ui.seo.pricing_description', [], 'ar'))
            ->assertSet('data.llms_txt', SeoHelper::defaultLlmsTxt())
            ->call('save')
            ->assertHasNoErrors();

        foreach (['seo_pricing_title_en', 'seo_pricing_description_en', 'seo_pricing_description_ar', 'seo_pricing_robots',
            'seo_pricing_sitemap_priority', 'meta_description_en', 'meta_description_ar', 'site_seo_description_en',
            'llms_txt', 'robots_txt', 'seo_landing_text_to_speech_content_en', 'seo_landing_text_to_speech_content_ar'] as $key) {
            $this->assertFalse(SiteSetting::query()->where('key', $key)->exists(), "{$key} is not saved");
        }
        // The other settings save as before, and so do the titles of pages kept
        // out of search, whose views can pass their own (a pack's name on billing).
        $this->assertTrue(SiteSetting::query()->where('key', 'enable_llms_txt')->exists());
        $this->assertSame(__('ui.seo.billing_title', [], 'en'), SiteSetting::query()->where('key', 'seo_billing_title_en')->value('value'));
        $this->assertFalse(SiteSetting::query()->where('key', 'seo_billing_description_en')->exists());

        // When the site's own text improves, the page shows it.
        app('translator')->addLines(['ui.seo.pricing_description' => 'Better words for the pricing page.'], 'en');
        app()->setLocale('en');
        $this->getLocalizedRoute('pricing')->assertOk()
            ->assertSee('<meta name="description" content="Better words for the pricing page.">', false);
    }

    public function test_a_changed_field_is_saved_and_wins(): void
    {
        Livewire::test(SeoSettings::class)
            ->set('data.seo_pricing_description_en', 'Written in the admin.')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Written in the admin.', SiteSetting::query()->where('key', 'seo_pricing_description_en')->value('value'));
        app()->setLocale('en');
        $this->getLocalizedRoute('pricing')->assertOk()
            ->assertSee('<meta name="description" content="Written in the admin.">', false);
    }

    public function test_a_field_set_back_to_the_sites_text_lets_its_saved_copy_go(): void
    {
        SiteSetting::set('seo_pricing_description_en', 'Written in the admin.', 'seo');
        SiteSetting::set('seo_pricing_title_ar', __('ui.seo.pricing_title', [], 'ar'), 'seo');

        Livewire::test(SeoSettings::class)
            ->assertSet('data.seo_pricing_description_en', 'Written in the admin.')
            ->set('data.seo_pricing_description_en', __('ui.seo.pricing_description', [], 'en')."\r\n")
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFalse(SiteSetting::query()->where('key', 'seo_pricing_description_en')->exists());
        $this->assertFalse(SiteSetting::query()->where('key', 'seo_pricing_title_ar')->exists(), 'an unchanged copy saved earlier is let go');
    }

    public function test_landing_content_is_unchanged_whatever_its_json_layout(): void
    {
        $default = SeoSettingDefaults::landingJson('text_to_speech', 'en');
        $compact = json_encode(json_decode($default, true), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        Livewire::test(SeoSettings::class)
            ->assertSet('data.seo_landing_text_to_speech_content_en', $default)
            ->set('data.seo_landing_text_to_speech_content_en', $compact)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFalse(SiteSetting::query()->where('key', 'seo_landing_text_to_speech_content_en')->exists());
    }

    public function test_the_migration_lets_go_of_saved_copies_and_keeps_what_was_written(): void
    {
        $copies = [
            'seo_pricing_description_en' => __('ui.seo.pricing_description', [], 'en'),
            'seo_pricing_title_ar' => __('ui.seo.pricing_title', [], 'ar'),
            'seo_contact_robots' => (string) SeoHelper::pageDefinitions()['contact']['robots'],
            'llms_txt' => SeoHelper::defaultLlmsTxt(),
            'seo_landing_text_to_speech_content_ar' => SeoSettingDefaults::landingJson('text_to_speech', 'ar'),
        ];
        foreach ($copies as $key => $value) {
            SiteSetting::set($key, $value, 'seo');
        }
        SiteSetting::set('seo_text_to_speech_description_en', 'Written in the admin.', 'seo');
        SiteSetting::set('sitemap_paths', SeoHelper::defaultSitemapEntriesText(), 'seo');

        app()->setLocale('en');
        $before = $this->metaDescription((string) $this->getLocalizedRoute('pricing')->getContent());

        (require database_path('migrations/2026_11_01_120000_seo_settings_let_go_of_saved_defaults.php'))->up();

        foreach (array_keys($copies) as $key) {
            $this->assertFalse(SiteSetting::query()->where('key', $key)->exists(), "{$key} is let go");
        }
        $this->assertSame('Written in the admin.', SiteSetting::query()->where('key', 'seo_text_to_speech_description_en')->value('value'));
        // Left unsaved, the sitemap paths add no entries, so a saved list is not a copy.
        $this->assertTrue(SiteSetting::query()->where('key', 'sitemap_paths')->exists());
        $this->assertSame($before, $this->metaDescription((string) $this->getLocalizedRoute('pricing')->getContent()), 'no page changes');
        $this->assertSame(SeoHelper::defaultLlmsTxt()."\n", (string) $this->get('/llms.txt')->getContent());
    }

    private function metaDescription(string $html): string
    {
        preg_match('/<meta name="description" content="([^"]*)"/', $html, $m);

        return $m[1] ?? '';
    }
}
