<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\Voice;
use App\Support\SeoHelper;
use App\Support\StudioFacts;
use Database\Seeders\VoiceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * What search engines and AI assistants read about the studio: the structured data,
 * llms.txt and the meta descriptions say what it does, with every count from its
 * catalogs and what depends on a plan read from the plans on sale. Nothing a
 * visitor reads on the pages changes.
 */
class SeoStudioFactsTest extends TestCase
{
    use RefreshDatabase;

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
        $this->assertSame('SoliCode Ltd', $organization['parentOrganization']['name']);
        $this->assertSame(SeoHelper::featureList('en'), $app['featureList']);
        $this->assertContains(StudioFacts::emotions().' emotions and '.StudioFacts::styles().' performance styles', $app['featureList']);
        $this->assertStringContainsString('Professional AI voice studio', $app['description']);

        $arabic = collect($this->jsonLd((string) $this->page('ar', 'home')->assertOk()->getContent()))->firstWhere('@type', 'SoftwareApplication');
        $this->assertContains(StudioFacts::emotions().' شعوراً و'.StudioFacts::styles().' أسلوب أداء', $arabic['featureList']);
    }

    public function test_dialogue_and_cloning_are_described_only_while_a_plan_on_sale_has_them(): void
    {
        $this->assertNotContains('Two-voice dialogue in one recording, each speaker in their own dialect', SeoHelper::featureList('en'));
        $this->assertNotContains('Voice cloning with the speaker\'s consent, speaking the dialect you choose', SeoHelper::featureList('en'));
        $this->assertStringNotContainsString('dialogue', SeoHelper::siteDescription('en'));
        $this->assertStringNotContainsString('Two-voice dialogue', (string) $this->get('/llms.txt')->getContent());

        Plan::factory()->create(['slug' => 'studio', 'name_en' => 'Studio', 'name_ar' => 'ستوديو', 'price_cents' => 4999,
            'dialogue_enabled' => true, 'voice_cloning_enabled' => true, 'voice_cloning_max_voices' => 10]);
        Plan::factory()->create(['slug' => 'pro', 'name_en' => 'Pro', 'price_cents' => 1999,
            'dialogue_enabled' => false, 'voice_cloning_enabled' => true, 'voice_cloning_max_voices' => 3]);
        // A plan that isn't on sale says nothing about what the others have.
        Plan::factory()->create(['slug' => 'old', 'name_en' => 'Old', 'price_cents' => 999, 'is_active' => false, 'dialogue_enabled' => true]);

        $this->assertContains('Two-voice dialogue in one recording, each speaker in their own dialect', SeoHelper::featureList('en'));
        $this->assertContains('Voice cloning with the speaker\'s consent, speaking the dialect you choose', SeoHelper::featureList('en'));
        $this->assertStringContainsString(', voice cloning, with emotions', SeoHelper::siteDescription('en'));
        $this->assertStringContainsString('Members can clone their own voice', (string) $this->get('/llms.txt')->getContent());
        $this->assertStringContainsString('two-voice dialogue', SeoHelper::siteDescription('en'));
        $this->assertStringContainsString('وحوارات بصوتين', SeoHelper::siteDescription('ar'));
        $llms = (string) $this->get('/llms.txt')->getContent();
        $this->assertStringContainsString('(on the Studio plan)', $llms);
        $this->assertStringContainsString('(3 to 10 voices, depending on the plan)', $llms);
        $this->assertStringNotContainsString('Old', $llms);

        Plan::factory()->create(['slug' => 'max', 'name_en' => 'Max', 'name_ar' => 'ماكس', 'price_cents' => 9999, 'dialogue_enabled' => true]);
        $this->assertStringContainsString('(on the Studio and Max plans)', (string) $this->get('/llms.txt')->getContent());
        $this->assertSame('باقتي ستوديو وماكس', StudioFacts::planList(['ستوديو', 'ماكس'], 'ar'));
        $this->assertSame('the A, B and C plans', StudioFacts::planList(['A', 'B', 'C'], 'en'));
    }

    public function test_llms_txt_says_what_the_studio_does(): void
    {
        $text = (string) $this->get('/llms.txt')->assertOk()->getContent();

        $this->assertStringContainsString('is a professional AI voice studio for Arabic and English', $text);
        $this->assertStringContainsString('## What the studio does', $text);
        $this->assertStringContainsString('more than '.StudioFacts::roundedDown(StudioFacts::dialectsAndAccents()).' dialects and accents', $text);
        $this->assertStringContainsString(StudioFacts::emotions().' emotions, '.StudioFacts::styles().' performance styles', $text);
        $this->assertStringContainsString('/en/developers)', $text);
        $this->assertStringNotContainsString('light use', $text);
        $this->assertDoesNotMatchRegularExpression('/gemini|google/i', $text, 'the speech provider is never named');
    }

    public function test_the_meta_descriptions_call_it_a_studio_and_the_pages_read_the_same_as_before(): void
    {
        $this->page('en', 'home')->assertOk()
            ->assertSee('<meta name="description" content="Professional AI voice studio:', false)
            ->assertSee('200+ accents. Real emotion.');
        $this->page('ar', 'home')->assertOk()
            ->assertSee('<meta name="description" content="استوديو أصوات احترافي بالذكاء الاصطناعي:', false);
        $this->page('en', 'pricing')->assertOk()
            ->assertSee('Every plan has all 200+ dialects and accents', false);
    }

    public function test_the_voice_count_comes_from_the_library_and_reaches_every_page_search_engines_read(): void
    {
        (new VoiceSeeder)->run();
        $arabic = Voice::query()->where('is_active', true)->where('language', 'ar')->count();
        $english = Voice::query()->where('is_active', true)->where('language', 'en')->count();
        $this->assertGreaterThan(100, $arabic + $english);
        $this->assertSame($arabic + $english, StudioFacts::voices());
        $count = $arabic + $english;
        $countAr = StudioFacts::arabicCount($count, 'صوت', 'أصوات', 'صوتاً');

        $this->page('en', 'home')->assertOk()
            ->assertSee('<meta name="description" content="Professional AI voice studio: '.$count.' natural voices, voice cloning in 140+ Arabic dialects', false);
        $this->page('ar', 'home')->assertOk()
            ->assertSee('<meta name="description" content="استوديو أصوات احترافي بالذكاء الاصطناعي: مكتبة من '.$countAr.'، واستنساخ الصوت، بـ140 لهجة عربية', false);

        $this->assertContains($count.' voices in Arabic and English', SeoHelper::featureList('en'));
        $this->assertContains('مكتبة من '.$countAr.' بالعربية والإنجليزية', SeoHelper::featureList('ar'));
        $this->assertStringContainsString("Its library has {$count} voices ({$arabic} Arabic and {$english} English).", (string) $this->get('/llms.txt')->getContent());
        $this->assertStringContainsString($count.' voices, ', SeoHelper::defaultSiteDescription('en'));
        $this->assertStringContainsString('مكتبة من '.$countAr, SeoHelper::defaultSiteDescription('ar'));
    }

    public function test_video_dubbing_is_described_and_its_page_searchable_only_once_it_is_open_to_everyone(): void
    {
        $dubbing = 'Video dubbing into the Arabic dialect or English accent you choose, keeping the music and sound effects';
        $this->assertNotContains($dubbing, SeoHelper::featureList('en'));
        $this->assertStringNotContainsString('video dubbing', SeoHelper::siteDescription('en'));
        $this->assertStringNotContainsString('Video dubbing', (string) $this->get('/llms.txt')->getContent());
        $this->assertStringContainsString('noindex', SeoHelper::robots('dub'));
        $this->assertStringNotContainsString('dub-my-video', (string) $this->get('/sitemap.xml')->getContent());
        $this->assertStringNotContainsString('dubbing', SeoHelper::description('home', 'en'));

        SiteSetting::set('video_dubbing_audience', 'everyone', 'video_dubbing');
        $this->assertStringContainsString('voice cloning and video dubbing in 140+', SeoHelper::description('home', 'en'));

        $this->assertContains($dubbing, SeoHelper::featureList('en'));
        $this->assertContains('دبلجة الفيديو إلى اللهجة العربية أو اللكنة الإنجليزية التي تختارها، مع بقاء الموسيقى والمؤثرات', SeoHelper::featureList('ar'));
        $this->assertStringContainsString(', video dubbing, with emotions', SeoHelper::siteDescription('en'));
        $this->assertStringContainsString('، ودبلجة الفيديو', SeoHelper::siteDescription('ar'));
        $llms = (string) $this->get('/llms.txt')->getContent();
        $this->assertStringContainsString('It also dubs videos into an Arabic dialect or English accent.', $llms);
        $this->assertStringContainsString('- Video dubbing: upload a short video', $llms);
        $this->assertStringContainsString('/en/dub-my-video', $llms);
        $this->assertStringNotContainsString('noindex', SeoHelper::robots('dub'));
        $sitemap = (string) $this->get('/sitemap.xml')->getContent();
        $this->assertStringContainsString('/en/dub-my-video', $sitemap);
        $this->assertStringContainsString('/ar/dub-my-video', $sitemap);
    }

    public function test_an_arabic_count_takes_the_noun_its_last_two_digits_ask_for(): void
    {
        $this->assertSame('3 أصوات', StudioFacts::arabicCount(3, 'صوت', 'أصوات', 'صوتاً'));
        $this->assertSame('107 أصوات', StudioFacts::arabicCount(107, 'صوت', 'أصوات', 'صوتاً'));
        $this->assertSame('11 صوتاً', StudioFacts::arabicCount(11, 'صوت', 'أصوات', 'صوتاً'));
        $this->assertSame('270 صوتاً', StudioFacts::arabicCount(270, 'صوت', 'أصوات', 'صوتاً'));
        $this->assertSame('200 صوت', StudioFacts::arabicCount(200, 'صوت', 'أصوات', 'صوتاً'));
        $this->assertSame('102 صوت', StudioFacts::arabicCount(102, 'صوت', 'أصوات', 'صوتاً'));
    }

    public function test_the_old_site_wide_description_saved_in_october_gives_way_to_the_sites_own(): void
    {
        $migration = require database_path('migrations/2026_11_02_120000_seo_let_go_of_the_old_site_wide_description.php');
        foreach ($migration::OCTOBER as $key => $text) {
            SiteSetting::set($key, $text, 'seo');
        }
        $this->assertSame($migration::OCTOBER['meta_description_en'], SeoHelper::description('', 'en'));

        $migration->up();

        $this->assertFalse(SiteSetting::query()->whereIn('key', array_keys($migration::OCTOBER))->exists());
        $this->assertSame(SeoHelper::siteDescription('en'), SeoHelper::description('', 'en'));
        $this->assertStringNotContainsString('لكنة عربية', SeoHelper::description('', 'ar'));

        // A description written differently in the admin stays.
        SiteSetting::set('meta_description_en', 'Written in the admin.', 'seo');
        $migration->up();
        $this->assertSame('Written in the admin.', SiteSetting::query()->where('key', 'meta_description_en')->value('value'));
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

    }

    public function test_the_site_icons_are_purple_to_their_edges_so_a_circle_shows_no_white_ring(): void
    {
        // Search results and profile pictures show the icon in a circle: anything not purple at
        // its edge (a transparent margin) shows there as a white ring around a smaller mark.
        $files = ['favicon.png', 'apple-touch-icon.png', 'icon-512.png', 'images/brand/listen-logo-512.png', 'images/brand/listen-profile-1024.png'];
        foreach ($files as $file) {
            $image = imagecreatefrompng(public_path($file));
            $size = imagesx($image);
            $this->assertSame($size, imagesy($image), "{$file} is square");
            $radius = $size / 2 - 1.5;
            for ($angle = 0; $angle < 360; $angle += 5) {
                $x = (int) round($size / 2 + $radius * cos(deg2rad($angle)));
                $y = (int) round($size / 2 + $radius * sin(deg2rad($angle)));
                $rgba = imagecolorsforindex($image, imagecolorat($image, $x, $y));
                $this->assertSame(0, $rgba['alpha'], "{$file} has no transparency at its edge ({$x},{$y})");
                $this->assertTrue($rgba['blue'] > 200 && $rgba['red'] < 200 && $rgba['green'] < $rgba['red'], "{$file} is purple at its edge ({$x},{$y})");
            }
            $middle = imagecolorsforindex($image, imagecolorat($image, (int) ($size / 2), (int) ($size / 2)));
            $this->assertSame([255, 255, 255], [$middle['red'], $middle['green'], $middle['blue']], "{$file} draws the white bars in the middle");
        }
        // Home screens on iPhone show transparency as black: that icon has none.
        $apple = imagecreatefrompng(public_path('apple-touch-icon.png'));
        $this->assertSame(0, (imagecolorat($apple, 0, 0) >> 24) & 0x7F, 'apple-touch-icon has no transparent corner');
        // The SVG icon is the same picture, to its edges.
        $svg = (string) file_get_contents(public_path('favicon.svg'));
        $this->assertStringContainsString('<image width="192" height="192" href="data:image/jpeg;base64,', $svg);
        $this->assertStringNotContainsString('scale(0.75)', $svg);
    }

    public function test_search_results_get_one_site_name_in_both_languages_and_never_the_address(): void
    {
        foreach (['ar', 'en'] as $locale) {
            $html = (string) $this->page($locale, 'home')->getContent();
            $site = collect($this->jsonLd($html))->firstWhere('@type', 'WebSite');
            $this->assertSame('Listen | إسمع', $site['name'] ?? null, "{$locale}: the site's name");
            $this->assertSame(rtrim(url('/'), '/').'/', $site['url'] ?? null, "{$locale}: on the domain's own home page");
            $this->assertContains('إسمع', $site['alternateName']);
            $this->assertContains('Listen', $site['alternateName']);
            foreach ($site['alternateName'] as $name) {
                $this->assertStringNotContainsString('.', $name, "{$locale}: the address is not given as a name");
            }
            $this->assertStringContainsString('<meta property="og:site_name" content="Listen | إسمع">', $html, "{$locale}: the share name matches");
        }

        // The team can set another name in the admin's settings.
        SiteSetting::set('search_site_name', 'إسمع | Listen', 'seo');
        $this->assertSame('إسمع | Listen', SeoHelper::searchSiteName());
        $this->assertNotContains('إسمع | Listen', SeoHelper::searchSiteAlternateNames());
    }

    public function test_the_manifest_gives_each_icon_its_real_size_and_type_and_a_logo_for_circles(): void
    {
        $manifest = SeoHelper::siteWebmanifest();
        $byFile = [];
        foreach ($manifest['icons'] as $icon) {
            $byFile[basename((string) parse_url($icon['src'], PHP_URL_PATH))] = $icon;
        }
        $this->assertSame(['any', 'image/svg+xml'], [$byFile['favicon.svg']['sizes'], $byFile['favicon.svg']['type']]);
        $this->assertSame(['192x192', 'image/png'], [$byFile['favicon.png']['sizes'], $byFile['favicon.png']['type']]);
        $this->assertSame(['180x180', 'image/png'], [$byFile['apple-touch-icon.png']['sizes'], $byFile['apple-touch-icon.png']['type']]);
        $this->assertSame(['512x512', 'image/png', 'maskable'], [$byFile['icon-512.png']['sizes'], $byFile['icon-512.png']['type'], $byFile['icon-512.png']['purpose']]);

        // The company's logo for search engines is the square mark, not the wide email logo.
        app()->setLocale('en');
        $html = (string) $this->getLocalizedRoute('home')->getContent();
        preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $ld);
        $this->assertStringContainsString('listen-logo-512.png', stripslashes($ld[1] ?? ''));
    }

    private function page(string $locale, string $route): TestResponse
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
