<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\SiteSetting;
use App\Support\HomeFaq;
use App\Support\SeoHelper;
use App\Support\StudioFacts;
use App\Support\TtsStudioConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * What search engines and AI assistants read about the studio: the features page,
 * the homepage questions, llms.txt and the structured data say what it does, with
 * every count from its catalogs and what depends on a plan read from the plans.
 */
class StudioFeaturesPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_features_page_names_what_the_studio_does_in_both_languages(): void
    {
        $this->page('en', 'seo.features')->assertOk()
            ->assertSee('Everything the Listen voice studio can do')
            ->assertSee(StudioFacts::emotions().' emotions for a whole script')
            ->assertSee(StudioFacts::styles().' performance styles')
            ->assertSee(StudioFacts::environments().' sound environments')
            ->assertSee('An API for developers')
            // The lists name every choice, as the studio's pickers do.
            ->assertSee(TtsStudioConfig::emotionCatalog()[0]['label'])
            ->assertSee(TtsStudioConfig::performanceStyleCatalog()[0]['label'])
            ->assertSee('&lt;laugh&gt;', false)
            ->assertSee('FAQPage', false)
            ->assertSee('/en/features"', false);

        $this->page('ar', 'seo.features')->assertOk()
            ->assertSee('كل ما يقدّمه استوديو إسمع للأصوات')
            ->assertSee(StudioFacts::emotions().' شعوراً للنص كله')
            ->assertSee(StudioFacts::styles().' أسلوب أداء')
            ->assertSee(TtsStudioConfig::emotionCatalog()[0]['label_ar']);
    }

    public function test_dialogue_and_cloning_are_described_only_while_a_plan_on_sale_has_them(): void
    {
        $this->page('en', 'seo.features')->assertOk()
            ->assertDontSee('Two voices in one recording')
            ->assertDontSee('Voice cloning');
        $this->assertNotContains('Two-voice dialogue in one recording, each speaker in their own dialect', SeoHelper::featureList('en'));
        $this->assertStringNotContainsString('dialogue', SeoHelper::siteDescription('en'));

        Plan::factory()->create(['slug' => 'studio', 'name_en' => 'Studio', 'name_ar' => 'ستوديو', 'price_cents' => 4999,
            'dialogue_enabled' => true, 'voice_cloning_enabled' => true, 'voice_cloning_max_voices' => 10]);
        Plan::factory()->create(['slug' => 'pro', 'name_en' => 'Pro', 'price_cents' => 1999,
            'dialogue_enabled' => false, 'voice_cloning_enabled' => true, 'voice_cloning_max_voices' => 3]);
        // A plan that isn't on sale says nothing about what the others have.
        Plan::factory()->create(['slug' => 'old', 'name_en' => 'Old', 'price_cents' => 999, 'is_active' => false, 'dialogue_enabled' => true]);

        $this->page('en', 'seo.features')->assertOk()
            ->assertSee('Two voices in one recording')
            ->assertSee('On the Studio plan.')
            ->assertSee('3 to 10 voices, depending on the plan.')
            ->assertDontSee('Old plan');
        $this->page('ar', 'seo.features')->assertOk()->assertSee('في باقة ستوديو.');
        $this->assertContains('Two-voice dialogue in one recording, each speaker in their own dialect', SeoHelper::featureList('en'));
        $this->assertStringContainsString('two-voice dialogue', SeoHelper::siteDescription('en'));

        Plan::factory()->create(['slug' => 'max', 'name_en' => 'Max', 'name_ar' => 'ماكس', 'price_cents' => 9999, 'dialogue_enabled' => true]);
        $this->page('en', 'seo.features')->assertOk()->assertSee('On the Studio and Max plans.');
        $this->page('ar', 'seo.features')->assertOk()->assertSee('في باقتي ستوديو وماكس.');
        $this->assertStringContainsString('Dialogue mode is on the Studio and Max plans.', collect(HomeFaq::items('en'))->firstWhere('question', 'Can two voices talk in one recording?')['answer']);
    }

    public function test_the_features_page_is_in_the_sitemap_the_footer_and_llms_txt(): void
    {
        $this->get('/sitemap.xml')->assertOk()
            ->assertSee('/en/features<', false)
            ->assertSee('/ar/features<', false);

        $this->page('en', 'pricing')->assertOk()->assertSee('/features"', false);

        $text = (string) $this->get('/llms.txt')->assertOk()->getContent();
        $this->assertStringContainsString('is a professional AI voice studio for Arabic and English', $text);
        $this->assertStringContainsString('## What the studio does', $text);
        $this->assertStringContainsString('/en/features)', $text);
        $this->assertStringContainsString('/en/developers)', $text);
        $this->assertStringContainsString(StudioFacts::emotions().' emotions', $text);
        $this->assertStringNotContainsString('light use', $text);
    }

    public function test_the_homepage_answers_what_the_studio_is_and_its_structured_data_holds_the_same_questions(): void
    {
        $html = (string) $this->page('en', 'home')->assertOk()
            ->assertSee('How many dialects and accents does Listen have?')
            ->assertSee('/features"', false)
            ->getContent();

        $faq = collect($this->jsonLd($html))->firstWhere('@type', 'FAQPage');
        $this->assertNotNull($faq, 'the homepage has FAQ structured data');
        $this->assertSame(array_column(HomeFaq::items('en'), 'question'), array_column($faq['mainEntity'], 'name'));
        $this->assertStringContainsString('more than '.StudioFacts::roundedDown(StudioFacts::dialectsAndAccents()).' dialects and accents', $faq['mainEntity'][1]['acceptedAnswer']['text']);

        $this->page('ar', 'home')->assertOk()->assertSee('كم لهجة ولكنة في منصة إسمع؟');
    }

    public function test_the_pricing_questions_still_show_with_their_structured_data(): void
    {
        $html = (string) $this->page('en', 'pricing')->assertOk()
            ->assertSee('class="faq-section"', false)
            ->getContent();

        $faq = collect($this->jsonLd($html))->firstWhere('@type', 'FAQPage');
        $this->assertNotNull($faq);
        $this->assertGreaterThanOrEqual(4, count($faq['mainEntity']));
    }

    public function test_the_structured_data_describes_a_studio_and_says_which_listen_it_is(): void
    {
        $graph = $this->jsonLd((string) $this->page('en', 'home')->assertOk()->getContent());
        $organization = collect($graph)->firstWhere('@type', 'Organization');
        $app = collect($graph)->firstWhere('@type', 'SoftwareApplication');

        $this->assertNotNull($organization);
        $this->assertNotNull($app);
        $this->assertStringContainsString('listenai.studio', $organization['disambiguatingDescription']);
        $this->assertContains('listenai.studio', $organization['alternateName']);
        $this->assertNotContains('Listen AI', $organization['alternateName'], 'the name of unrelated apps');
        $this->assertSame(SeoHelper::featureList('en'), $app['featureList']);
        $this->assertContains(StudioFacts::emotions().' emotions and '.StudioFacts::styles().' performance styles', $app['featureList']);
    }

    public function test_the_basic_plan_tagline_says_what_the_plan_holds(): void
    {
        $old = ['description_en' => 'The premium voice for light use, in your dashboard and the browser extension.',
            'description_ar' => 'الصوت المتميز للاستخدام الخفيف، في لوحة التحكم وإضافة المتصفح.'];
        $new = ['description_en' => 'Premium voices with all the studio\'s dialects and accents, for a smaller monthly volume, in your dashboard and the browser extension.',
            'description_ar' => 'أصوات متميزة بجميع لهجات الاستوديو ولكناته، لكمية شهرية أصغر، في لوحة التحكم وإضافة المتصفح.'];
        $basic = Plan::query()->where('slug', 'basic')->first() ?? Plan::factory()->create(['slug' => 'basic']);
        $basic->update($old);
        $migration = require database_path('migrations/2026_10_31_120000_basic_plan_says_what_it_holds.php');

        $migration->up();
        $this->assertSame($new, $basic->fresh()->only(array_keys($new)));

        $migration->down();
        $this->assertSame($old, $basic->fresh()->only(array_keys($old)));

        // A tagline written in the admin since stays as it is.
        $basic->update(['description_en' => 'Written in the admin.']);
        $migration->up();
        $this->assertSame('Written in the admin.', $basic->fresh()->description_en);
        $this->assertSame($new['description_ar'], $basic->fresh()->description_ar);
    }

    public function test_the_old_full_square_icons_give_way_to_the_bundled_ones_with_room_around_them(): void
    {
        SiteSetting::set('seo_favicon_url', 'https://listenai.studio/storage/settings/seo/01KKZQ0BNM2JW3P5PAA49S84DJ.png', 'seo');
        SiteSetting::set('seo_apple_touch_icon_url', 'https://listenai.studio/storage/settings/seo/01KKZQ0BNNJ6Y2F44Z8HQ1NJFB.png', 'seo');
        SiteSetting::set('seo_favicon_svg_url', 'https://listenai.studio/storage/settings/seo/a-newer-upload.svg', 'seo');
        $this->assertStringContainsString('01KKZQ0BNM2JW3P5PAA49S84DJ', SeoHelper::primaryFaviconUrl());

        (require database_path('migrations/2026_10_31_120100_site_icons_with_room_around_them.php'))->up();

        $this->assertSame('', (string) DB::table('site_settings')->where('key', 'seo_favicon_url')->value('value'));
        $this->assertSame('', (string) DB::table('site_settings')->where('key', 'seo_apple_touch_icon_url')->value('value'));
        $this->assertSame('https://listenai.studio/storage/settings/seo/a-newer-upload.svg', (string) DB::table('site_settings')->where('key', 'seo_favicon_svg_url')->value('value'), 'an icon uploaded later stays');
        $this->assertStringContainsString('/favicon.png', SeoHelper::primaryFaviconUrl());

        // The bundled icons leave a clear margin around the mark, so it never touches the words beside it.
        foreach (['favicon.png', 'apple-touch-icon.png'] as $file) {
            $image = imagecreatefrompng(public_path($file));
            $size = imagesx($image);
            $margin = (int) floor($size * 0.1);
            foreach ([[0, 0], [$margin, $size - 1 - $margin], [$size - 1, $size - 1], [(int) ($size / 2), $margin - 1]] as [$x, $y]) {
                $alpha = (imagecolorat($image, $x, $y) >> 24) & 0x7F;
                $this->assertSame(127, $alpha, "{$file} is transparent at {$x},{$y}");
            }
            $this->assertSame(0, (imagecolorat($image, (int) ($size / 2), (int) ($size / 3)) >> 24) & 0x7F, "{$file} draws the mark in the middle");
        }
        $this->assertStringContainsString('<g transform="translate(16 16) scale(0.75)">', (string) file_get_contents(public_path('favicon.svg')));
    }

    private function page(string $locale, string $route): \Illuminate\Testing\TestResponse
    {
        app()->setLocale($locale);

        return $this->getLocalizedRoute($route);
    }

    /**
     * Every node of the page's JSON-LD.
     *
     * @return array<int, array<string, mixed>>
     */
    private function jsonLd(string $html): array
    {
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
        $nodes = [];
        foreach ($m[1] as $json) {
            $data = json_decode($json, true);
            foreach ($data['@graph'] ?? [$data] as $node) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }
}
