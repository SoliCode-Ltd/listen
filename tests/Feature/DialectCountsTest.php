<?php

namespace Tests\Feature;

use App\Support\ArabicDialects;
use App\Support\DialectCounts;
use App\Support\DialogueSpeakers;
use App\Support\EnglishAccents;
use App\Support\SeoDialectPages;
use App\Support\SeoEnglishPages;
use App\Support\SeoHelper;
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

    public function test_every_number_written_in_the_language_files_matches_the_catalogs(): void
    {
        $arabic = [
            '/more than (\d+) (?:Arabic|regional)/u', '/(\d+)\+ (?:Arabic|Dialects)/u',
            '/أكثر من (\d+) لكنة (?:عربية|محلية)/u', '/\x{2066}\+(\d+)\x{2069} لهجة/u',
        ];
        $english = ['/more than (\d+) English/u', '/(\d+)\+ English/u', '/(\d+) لكنة إنجليزية/u', '/بأكثر من (\d+) لكنة:/u'];
        $found = ['arabic' => 0, 'english' => 0];

        foreach (['en', 'ar'] as $locale) {
            $text = implode("\n", Arr::flatten(require lang_path($locale.'/ui.php')));
            foreach (['arabic' => $arabic, 'english' => $english] as $kind => $patterns) {
                $expected = $kind === 'arabic' ? DialectCounts::arabic() : DialectCounts::english();
                foreach ($patterns as $pattern) {
                    preg_match_all($pattern, $text, $m);
                    foreach ($m[1] as $number) {
                        $found[$kind]++;
                        $this->assertSame($expected, (int) $number, "lang/{$locale}/ui.php quotes {$number} {$kind}, the catalog has {$expected}+ ({$pattern})");
                    }
                }
            }
        }

        $this->assertGreaterThanOrEqual(12, $found['arabic'], 'the patterns still find the Arabic counts');
        $this->assertGreaterThanOrEqual(6, $found['english'], 'the patterns still find the English counts');
    }

    public function test_seo_texts_quote_the_current_counts(): void
    {
        $content = json_encode(SeoHelper::landingPageContentDefaults(), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('more than '.DialectCounts::arabic().' Arabic accents', $content);
        $this->assertStringContainsString('أكثر من '.DialectCounts::arabic().' لكنة عربية', $content);
        $this->assertStringContainsString('more than '.DialectCounts::english().' English accents', $content);
        $this->assertStringContainsString('أكثر من '.DialectCounts::english().' لكنة إنجليزية', $content);
        $this->assertDoesNotMatchRegularExpression('/(?:more than|أكثر من) (?:100|40) |\b(?:100|40)\+ (?:Arabic|English)/u', $content);
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
}
