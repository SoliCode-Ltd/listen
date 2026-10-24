<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\ArabicDialects;
use App\Support\AudioEffects;
use App\Support\DialectCounts;
use App\Support\DialogueSpeakers;
use App\Support\EnglishAccents;
use App\Support\SeoDialectPages;
use App\Support\SeoEnglishPages;
use App\Support\SeoHelper;
use App\Support\TtsStudioConfig;
use App\Support\VoiceFlag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Tests\TestCase;

/**
 * Every number the site quotes for its dialects and accents matches the catalogs,
 * and every dialect or accent added to a catalog is complete and reaches its SEO page.
 */
class DialectCountsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_counts_are_the_catalogs_rounded_down_to_the_ten(): void
    {
        $spoken = count(array_filter(ArabicDialects::ALL, fn (array $row): bool => $row['region'] !== 'fusha'));
        $this->assertSame(intdiv($spoken, 10) * 10, DialectCounts::arabic());
        $this->assertSame(intdiv(count(EnglishAccents::ALL), 10) * 10, DialectCounts::english());
        $this->assertGreaterThanOrEqual(140, DialectCounts::arabic());
        $this->assertGreaterThanOrEqual(60, DialectCounts::english());
    }

    public function test_the_dialect_hub_counts_the_spoken_dialects_and_names_fusha_apart(): void
    {
        $spoken = array_filter(ArabicDialects::ALL, fn (array $row): bool => $row['region'] !== 'fusha');
        $countries = count(array_unique(array_map(fn (string $code): ?string => ArabicDialects::countryOf($code), array_keys($spoken))));
        $this->assertSame(count($spoken), DialectCounts::spokenArabic());
        $this->assertLessThan(count(ArabicDialects::ALL), count($spoken), 'Fusha and its styles are rows of the catalog too');
        $this->assertSame(count(ArabicDialects::COUNTRIES) - 1, $countries);

        $this->page('en', 'seo.arabic-dialects')->assertOk()
            ->assertSee('Listen speaks '.count($spoken).' Arabic accents from '.$countries.' countries plus Modern Standard Arabic')
            ->assertSee(count($spoken).' accents from '.$countries.' countries, plus Modern Standard Arabic (Fusha).')
            ->assertSee('choose Fusha or one of '.count($spoken).' Arabic accents.')
            ->assertDontSee(count(ArabicDialects::ALL).' Arabic accents')
            ->assertDontSee('Arab countries');
        $this->page('ar', 'seo.arabic-dialects')->assertOk()
            ->assertSee('منصة إسمع تتكلم '.count($spoken).' لكنة عربية من '.$countries.' دولة بالإضافة إلى الفصحى')
            ->assertSee(count($spoken).' لكنة من '.$countries.' دولة، بالإضافة إلى العربية الفصحى.')
            ->assertSee('واختر الفصحى أو واحدة من '.count($spoken).' لكنة عربية.')
            ->assertDontSee(count(ArabicDialects::ALL).' لكنة')
            ->assertDontSee('دولة عربية');
    }

    public function test_the_accent_hub_counts_english_with_an_arabic_accent_among_the_accents(): void
    {
        $count = count(EnglishAccents::ALL);
        $countries = count(array_unique(array_filter(array_column(EnglishAccents::ALL, 'country'), fn (string $country): bool => $country !== 'AR')));
        $this->assertSame('AR', EnglishAccents::ALL['en-AR']['country'], 'English with an Arabic accent is one of the catalog rows');
        $this->assertSame(count(EnglishAccents::COUNTRIES) - 1, $countries);

        $this->page('en', 'seo.english-accents')->assertOk()
            ->assertSee('Listen speaks English in '.$count.' accents from '.$countries.' countries: American, British, Australian, Canadian, Irish, Indian and more, including English with an Arabic accent.')
            ->assertSee($count.' accents from '.$countries.' countries, including English with an Arabic accent.')
            ->assertDontSee('plus English with an Arabic accent');
        $this->page('ar', 'seo.english-accents')->assertOk()
            ->assertSee('وغيرها، ومنها الإنجليزية بلكنة عربية.')
            ->assertSee($count.' لكنة من '.$countries.' دولة، منها الإنجليزية بلكنة عربية.')
            ->assertDontSee('بالإضافة إلى الإنجليزية بلكنة عربية');
    }

    public function test_every_number_written_in_the_language_files_matches_the_catalogs(): void
    {
        $arabic = [
            '/more than (\d+) (?:Arabic|regional)/u', '/(\d+)\+ (?:Arabic|regional|[Dd]ialects)/u',
            '/(\d+) (?:لكنة|لهجة) (?:عربية|محلية)/u', '/\x{2066}\+(\d+)\x{2069} لهجة/u',
        ];
        $english = ['/more than (\d+) English/u', '/(\d+)\+ English/u', '/(\d+) لكنة إنجليزية/u', '/بأكثر من (\d+) لكنة:/u'];
        // Both together, as the homepage quotes them: "200+ accents".
        $total = ['/(\d+)\+ accents/u', '/أكثر من (\d+) لهجة ولكنة/u'];
        $counts = ['arabic' => DialectCounts::arabic(), 'english' => DialectCounts::english(), 'total' => DialectCounts::arabic() + DialectCounts::english()];
        $found = ['arabic' => 0, 'english' => 0, 'total' => 0];

        foreach (['en', 'ar'] as $locale) {
            foreach (['ui', 'developers'] as $file) {
                $text = implode("\n", Arr::flatten(require lang_path($locale.'/'.$file.'.php')));
                foreach (['arabic' => $arabic, 'english' => $english, 'total' => $total] as $kind => $patterns) {
                    $expected = $counts[$kind];
                    foreach ($patterns as $pattern) {
                        preg_match_all($pattern, $text, $m);
                        foreach ($m[1] as $number) {
                            $found[$kind]++;
                            $this->assertSame($expected, (int) $number, "lang/{$locale}/{$file}.php quotes {$number} {$kind}, the catalog has {$expected}+ ({$pattern})");
                        }
                    }
                }
            }
        }

        $this->assertGreaterThanOrEqual(12, $found['arabic'], 'the patterns still find the Arabic counts');
        $this->assertGreaterThanOrEqual(6, $found['english'], 'the patterns still find the English counts');
        $this->assertGreaterThanOrEqual(2, $found['total'], 'the patterns still find the homepage total in both languages');
    }

    public function test_seo_texts_quote_the_current_counts(): void
    {
        $content = json_encode(SeoHelper::landingPageContentDefaults(), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString(DialectCounts::arabic().'+ Arabic accents', $content);
        $this->assertStringContainsString('الفصحى و'.DialectCounts::arabic().' لكنة عربية', $content);
        $this->assertStringContainsString('more than '.DialectCounts::english().' English accents', $content);
        $this->assertStringContainsString('أكثر من '.DialectCounts::english().' لكنة إنجليزية', $content);
        // Rounded down, the count can be the catalog's own number, and "more than" it then untrue.
        $this->assertStringNotContainsString('more than '.DialectCounts::arabic(), $content);
        $this->assertStringNotContainsString('أكثر من '.DialectCounts::arabic(), $content);
        $this->assertDoesNotMatchRegularExpression('/(?:more than|أكثر من) (?:100|40) |\b(?:100|40)\+ (?:Arabic|English)/u', $content);
    }

    /**
     * "More than N" needs more than N; "N+" (or "+N" in Arabic) at least N. Every such
     * count the public pages show, in either language, against what the studio has.
     */
    public function test_no_page_quotes_more_than_the_studio_has(): void
    {
        $arabic = DialectCounts::spokenArabic();
        $english = count(EnglishAccents::ALL);
        $styles = count(TtsStudioConfig::performanceStyleValues());
        $environments = count(array_filter(AudioEffects::ALL, fn (array $row): bool => $row[1] !== 'clean'));
        $places = count(array_filter(AudioEffects::ALL, fn (array $row): bool => $row[1] === 'space'));
        // pattern => [what the studio has, whether the page says "more than"]
        $claims = [
            '/more than (\d+) (?:Arabic|regional)/u' => [$arabic, true],
            '/(\d+)\+ (?:Arabic|regional|[Dd]ialects(?! and accents))/u' => [$arabic, false],
            '/أكثر من (\d+) (?:لكنة|لهجة) (?:عربية|محلية)/u' => [$arabic, true],
            '/\+(\d+) (?:لهجة(?! ولكنة)|لكنة (?:عربية|محلية))/u' => [$arabic, false],
            '/more than (\d+) (?:English )?accents/u' => [$english, true],
            '/(\d+)\+ English/u' => [$english, false],
            '/أكثر من (\d+) لكنة(?! (?:عربية|محلية))/u' => [$english, true],
            '/\+(\d+) لكنة(?! (?:عربية|محلية))/u' => [$english, false],
            '/(\d+)\+ accents/u' => [$arabic + $english, false],
            // Dialects and accents together: the Arabic dialects and the English accents.
            '/(\d+)\+ dialects and accents/u' => [$arabic + $english, false],
            '/\+(\d+) لهجة ولكنة/u' => [$arabic + $english, false],
            '/أكثر من (\d+) لهجة ولكنة/u' => [$arabic + $english, true],
            '/(\d+)\+ (?:[Pp]erformance )?styles/u' => [$styles, false],
            '/أكثر من (\d+) أسلوب/u' => [$styles, true],
            '/\+(\d+) أسلوب/u' => [$styles, false],
            '/(\d+)\+ [Ss]ound environments|\+(\d+) sound environments/u' => [$environments, false],
            '/أكثر من (\d+) بيئة/u' => [$environments, true],
            '/\+(\d+) بيئة/u' => [$environments, false],
            '/(\d+)\+ places/u' => [$places, false],
            '/أكثر من (\d+) مكان/u' => [$places, true],
        ];
        $pages = [
            ['home', []], ['samples', []], ['developers', []], ['pricing', []],
            ['seo.text-to-speech', []], ['seo.text-to-speech-arabic', []], ['seo.ai-text-to-speech', []], ['seo.ai-voice-generator', []],
            ['seo.arabic-dialects', []], ['seo.english-accents', []],
            ['seo.dialect', ['dialect' => 'egyptian']], ['seo.dialect', ['dialect' => 'fusha']],
            ['seo.accent', ['accent' => 'american']], ['seo.accent', ['accent' => 'arabic-accent']],
        ];

        $texts = ['llms.txt' => (string) $this->get('/llms.txt')->assertOk()->getContent()];
        foreach (['en', 'ar'] as $locale) {
            foreach ($pages as [$route, $params]) {
                $texts[$route.' '.$locale] = $this->shownText((string) $this->page($locale, $route, $params)->assertOk()->getContent());
            }
            foreach (array_keys(SeoHelper::pageDefinitions()) as $page) {
                $texts[$page.' description '.$locale] = SeoHelper::description($page, $locale);
            }
        }
        // The demo, for members, shows the counts too.
        $this->actingAs(User::factory()->create());
        foreach (['en', 'ar'] as $locale) {
            $texts['demo '.$locale] = $this->shownText((string) $this->page($locale, 'demo.page')->assertOk()->getContent());
        }

        $found = 0;
        foreach ($texts as $where => $text) {
            foreach ($claims as $pattern => [$has, $moreThan]) {
                preg_match_all($pattern, $text, $m);
                foreach (array_filter(array_merge(...array_slice($m, 1))) as $number) {
                    $found++;
                    $moreThan
                        ? $this->assertGreaterThan((int) $number, $has, "{$where} says more than {$number}, there are {$has} ({$pattern})")
                        : $this->assertGreaterThanOrEqual((int) $number, $has, "{$where} says {$number}+, there are {$has} ({$pattern})");
                }
            }
        }
        $this->assertGreaterThanOrEqual(40, $found, 'the patterns still find the counts the pages show');
    }

    public function test_every_arabic_dialect_is_complete_and_has_its_country_page(): void
    {
        $pageCountries = array_column(SeoDialectPages::PAGES, 'country');
        foreach (ArabicDialects::ALL as $code => $row) {
            $this->assertLessThanOrEqual(10, strlen($code), "{$code} fits the dialect columns");
            $this->assertMatchesRegularExpression(DialogueSpeakers::DIALECT_PATTERN, $code);
            foreach (['en', 'ar', 'region', 'prompt', 'clone'] as $field) {
                $this->assertNotSame('', trim((string) ($row[$field] ?? '')), "{$code} has {$field}");
            }
            $this->assertArrayHasKey($row['region'], ArabicDialects::regions(), $code);
            $this->assertNotEmpty(ArabicDialects::MARKERS[$code] ?? null, "{$code} has markers");
            $this->assertCount(2, ArabicDialects::ABOUT[$code] ?? [], "{$code} has an about line in both languages");
            $country = ArabicDialects::countryOf($code);
            $this->assertNotNull($country, "{$code} belongs to a known country");
            $this->assertContains($country, $pageCountries, "{$code} appears on a country page");
            if ($country !== 'XA') {
                $this->assertNotNull(VoiceFlag::code($code), "{$code} has a flag");
            }
        }
    }

    public function test_every_english_accent_is_complete_and_has_its_page(): void
    {
        $pageCountries = array_column(SeoEnglishPages::PAGES, 'country');
        foreach (EnglishAccents::ALL as $code => $row) {
            $this->assertLessThanOrEqual(10, strlen($code), "{$code} fits the dialect columns");
            $this->assertMatchesRegularExpression(DialogueSpeakers::DIALECT_PATTERN, $code);
            foreach (['en', 'ar', 'country', 'prompt', 'clone'] as $field) {
                $this->assertNotSame('', trim((string) ($row[$field] ?? '')), "{$code} has {$field}");
            }
            $this->assertArrayHasKey($row['country'], EnglishAccents::COUNTRIES, $code);
            $this->assertCount(2, EnglishAccents::ABOUT[$code] ?? [], "{$code} has an about line in both languages");
            $this->assertContains($row['country'], $pageCountries, "{$code} appears on an accent page");
            if ($row['country'] !== 'AR') {
                $this->assertNotNull(VoiceFlag::code($code), "{$code} has a flag");
            }
        }
    }

    public function test_the_new_pages_render_and_are_in_the_sitemap(): void
    {
        $this->page('en', 'seo.dialect', ['dialect' => 'nigerian'])->assertOk()->assertSee('Nigerian Shuwa')->assertSee('Borno');
        $this->page('ar', 'seo.dialect', ['dialect' => 'nigerian'])->assertOk()->assertSee('النيجيرية (الشوا)');

        $accents = ['trinidadian', 'bangladeshi', 'sri-lankan', 'malaysian', 'ugandan', 'zimbabwean',
            'vietnamese-accent', 'indonesian-accent', 'thai-accent', 'portuguese-accent', 'swedish-accent', 'ukrainian-accent'];
        foreach ($accents as $slug) {
            $this->assertNotSame([], SeoEnglishPages::accents($slug), $slug);
            $this->page('en', 'seo.accent', ['accent' => $slug])->assertOk();
        }
        $this->page('ar', 'seo.accent', ['accent' => 'sri-lankan'])->assertOk()->assertSee('سريلانكية');

        $xml = $this->get('/sitemap.xml')->assertOk()->getContent();
        $this->assertStringContainsString('/ar/arabic-dialects/nigerian<', $xml);
        foreach ($accents as $slug) {
            $this->assertStringContainsString('/en/english-accents/'.$slug.'<', $xml);
        }

        $this->page('en', 'seo.arabic-dialects')->assertOk()->assertSee('/arabic-dialects/nigerian', false);
        $this->page('en', 'seo.english-accents')->assertOk()->assertSee('/english-accents/ukrainian-accent', false);
    }

    private function page(string $locale, string $route, array $params = []): \Illuminate\Testing\TestResponse
    {
        app()->setLocale($locale);

        return $this->getLocalizedRoute($route, $params);
    }

    /** What a reader of the page sees, its meta tags too, without the isolates that keep "+140" in order in Arabic. */
    private function shownText(string $html): string
    {
        preg_match_all('/\bcontent="([^"]*)"/', $html, $meta);
        $text = html_entity_decode(preg_replace('/<[^>]*>/', ' ', $html).' '.implode(' ', $meta[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return (string) preg_replace('/\s+/u', ' ', str_replace(["\u{2066}", "\u{2067}", "\u{2068}", "\u{2069}"], '', $text));
    }
}
