<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use App\Support\Credits;
use App\Support\SeoDialectPages;
use App\Support\SeoEnglishPages;
use App\Support\SeoHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeoProductClaimsTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_public_page_promises_a_free_plan_or_old_counts(): void
    {
        foreach (SeoHelper::pageDefinitions() as $page => $definition) {
            if (! ($definition['indexable'] ?? false)) {
                continue;
            }
            foreach (['en', 'ar'] as $locale) {
                $text = SeoHelper::title('', $page, $locale).' '.SeoHelper::description($page, $locale);
                $this->assertDoesNotMatchRegularExpression('/\bfree\b|مجان/iu', $text, "{$page} ({$locale}): {$text}");
                // The old accent counts; "50+ emotions" is the emotions list, counted in DialectCountsTest.
                $this->assertDoesNotMatchRegularExpression('/\b50\b(?!\+? (?:emotions|شعور))|\b18 English|خمسين|18 لكنة/u', $text, "{$page} ({$locale}): {$text}");
            }
        }
    }

    public function test_landing_page_faqs_and_ctas_make_no_free_plan_claims(): void
    {
        $pages = [
            ['en', 'seo.text-to-speech', []], ['ar', 'seo.text-to-speech', []],
            ['en', 'seo.text-to-speech-arabic', []], ['ar', 'seo.text-to-speech-arabic', []],
            ['en', 'seo.ai-text-to-speech', []], ['ar', 'seo.ai-text-to-speech', []],
            ['en', 'seo.dialect', ['dialect' => 'egyptian']], ['ar', 'seo.dialect', ['dialect' => 'chadian']],
            ['en', 'seo.accent', ['accent' => 'british']], ['ar', 'seo.accent', ['accent' => 'kenyan']],
            ['en', 'seo.accent', ['accent' => 'spanish-accent']], ['ar', 'seo.accent', ['accent' => 'japanese-accent']],
        ];

        foreach ($pages as [$locale, $route, $params]) {
            $html = $this->page($locale, $route, $params)->assertOk()->getContent();
            $this->assertDoesNotMatchRegularExpression('/free account|free plan|free to try|Free forever|حساب مجاني|حسابك المجاني|الخطة المجانية|جرّبه مجاناً/iu', $html, $route.' '.$locale);
        }
    }

    public function test_the_demo_is_promised_once_not_as_an_allowance_for_every_new_account(): void
    {
        // One demo per device, and an email sign-up confirms its address first.
        $pages = [['seo.text-to-speech', []], ['seo.text-to-speech-arabic', []], ['seo.ai-text-to-speech', []],
            ['seo.arabic-dialects', []], ['seo.english-accents', []], ['seo.dialect', ['dialect' => 'egyptian']], ['seo.accent', ['accent' => 'british']]];
        foreach ($pages as [$route, $params]) {
            foreach (['en', 'ar'] as $locale) {
                $html = (string) $this->page($locale, $route, $params)->assertOk()->getContent();
                $this->assertDoesNotMatchRegularExpression('/small allowance|Every new account|رصيد صغير|كل حساب جديد/u', $html, $route.' '.$locale);
            }
        }

        $this->page('en', 'seo.dialect', ['dialect' => 'egyptian'])->assertSee('Yes. New members can try the voices once in the demo, then you pick the plan that fits your use.');
        $this->page('ar', 'seo.dialect', ['dialect' => 'egyptian'])->assertSee('نعم، يمكن للأعضاء الجدد تجربة الأصوات مرة واحدة في العرض التجريبي، ثم تختار الباقة المناسبة لاستخدامك.');
        $this->page('en', 'seo.english-accents')->assertSee('Yes. New members can try the voices and accents once in the demo.');
        $this->page('ar', 'seo.arabic-dialects')->assertSee('نعم، يمكن للأعضاء الجدد تجربة الأصوات واللهجات مرة واحدة في العرض التجريبي.');
        $this->assertStringContainsString('new members can try the voices once in the demo', (string) $this->get('/llms.txt')->getContent());
    }

    public function test_plans_are_described_in_credits_not_characters(): void
    {
        // Plans meter the audio itself: credits, 900 of them a minute.
        $this->assertSame(1, Credits::minutesFor(900));

        $this->page('en', 'seo.text-to-speech')->assertOk()
            ->assertSee('Plans add monthly credits, premium voices, dialects, accents and emotions.')
            ->assertDontSee('monthly characters');
        $this->page('ar', 'seo.text-to-speech')->assertOk()
            ->assertSee('الباقات تضيف رصيداً شهرياً وأصواتاً مميزة ولهجات ولكنات ومشاعر.')
            ->assertDontSee('أحرفاً شهرية');
        $this->page('ar', 'seo.text-to-speech-arabic')->assertOk()
            ->assertSee('ثم تختار الباقة التي تناسب الرصيد الذي تحتاجه.')
            ->assertDontSee('عدد الأحرف');
    }

    public function test_downloads_are_promised_on_paid_plans_only(): void
    {
        // A demo clip's download button opens billing instead.
        $this->actingAs(User::factory()->create());
        $this->page('en', 'demo.page')->assertOk()->assertSee('data-download-redirect-url="'.route('billing.page'), false);
        auth()->logout();

        foreach ([['seo.dialect', ['dialect' => 'egyptian']], ['seo.accent', ['accent' => 'british']]] as [$route, $params]) {
            $this->page('en', $route, $params)->assertOk()
                ->assertSee('On every paid plan, each clip downloads as MP3.')
                ->assertDontSee('Every clip downloads as MP3.');
            $this->page('ar', $route, $params)->assertOk()
                ->assertSee('في كل باقة مدفوعة يمكنك تحميل كل مقطع بصيغة MP3.');
        }
        $this->page('en', 'seo.text-to-speech')->assertOk()
            ->assertSee('Sign up and choose a paid plan, paste your text, pick a language, voice and dialect, then generate and download the MP3.');
        $this->page('ar', 'seo.text-to-speech')->assertOk()->assertSee('سجّل حساباً واختر باقة مدفوعة، الصق النص');
        $this->page('ar', 'seo.text-to-speech-arabic')->assertOk()->assertSee('أنشئ حساباً واختر باقة مدفوعة، الصق النص');
    }

    public function test_pages_promise_every_major_dialect_not_every_dialect(): void
    {
        $this->page('ar', 'seo.text-to-speech-arabic')->assertOk()
            ->assertSee('تحويل النص العربي إلى كلام بصوت طبيعي وبكل اللهجات الرئيسية')
            ->assertSee('الفصحى وكل اللهجات الرئيسية');
        $this->page('en', 'seo.text-to-speech')->assertOk()->assertSee('English and Arabic, every major dialect');
        $this->page('ar', 'seo.text-to-speech')->assertOk()->assertSee('العربية بكل لهجاتها الرئيسية والإنجليزية');
        $this->page('ar', 'seo.english-accents')->assertOk()->assertSee('<title>تحويل النص إلى صوت إنجليزي بكل اللكنات الرئيسية', false);

        $pages = [['seo.text-to-speech', []], ['seo.text-to-speech-arabic', []], ['seo.ai-text-to-speech', []], ['seo.ai-voice-generator', []],
            ['seo.arabic-dialects', []], ['seo.english-accents', []], ['seo.dialect', ['dialect' => 'saudi']], ['seo.accent', ['accent' => 'british']],
            ['home', []], ['samples', []], ['seo.features', []], ['pricing', []]];
        foreach ($pages as [$route, $params]) {
            foreach (['en', 'ar'] as $locale) {
                // "All Arabic dialects" links to the list of the ones Listen has, and stays.
                $this->assertDoesNotMatchRegularExpression(
                    '/every (?:dialect|accent)\b|كل اللهجات(?! (?:الرئيسية|العربية))|بكل لهجاتها(?! الرئيسية)|بكل اللكنات(?! الرئيسية)|كل البلاد العربية/u',
                    (string) $this->page($locale, $route, $params)->assertOk()->getContent(),
                    $route.' '.$locale
                );
            }
        }
    }

    public function test_accent_pages_for_other_languages_render_with_breadcrumbs(): void
    {
        $html = $this->page('en', 'seo.accent', ['accent' => 'spanish-accent'])->assertOk()->getContent();
        $this->assertStringContainsString('<title>English Text to Speech with a Spanish Accent', $html);
        $this->assertStringContainsString('"name":"English accents"', $html);

        $ar = $this->page('ar', 'seo.accent', ['accent' => 'german-accent'])->assertOk()->getContent();
        $this->assertStringContainsString('تحويل النص إلى صوت إنجليزي بلكنة ألمانية', $ar);
    }

    public function test_the_sitemap_lists_the_new_language_dialect_and_accent_pages(): void
    {
        $xml = $this->get('/sitemap.xml')->assertOk()->getContent();

        $this->assertStringNotContainsString('/languages/', $xml);
        foreach (['chadian', 'south-sudanese', 'ahwazi', 'anatolian'] as $slug) {
            $this->assertContains($slug, SeoDialectPages::slugs());
            $this->assertStringContainsString('/ar/arabic-dialects/'.$slug.'<', $xml);
        }
        foreach (['pakistani', 'filipino', 'ghanaian', 'kenyan', 'french-accent', 'spanish-accent', 'german-accent', 'japanese-accent', 'chinese-accent'] as $slug) {
            $this->assertNotSame([], SeoEnglishPages::accents($slug), $slug);
            $this->assertStringContainsString('/en/english-accents/'.$slug.'<', $xml);
        }
        $this->assertSame('English Text to Speech with a French Accent', SeoEnglishPages::title('french-accent', 'en'));
    }

    public function test_the_price_shown_to_search_engines_starts_at_the_cheapest_paid_plan(): void
    {
        Plan::factory()->create(['price_cents' => 0, 'is_active' => true]);
        Plan::factory()->create(['price_cents' => 900, 'is_active' => true]);
        Plan::factory()->create(['price_cents' => 4900, 'is_active' => true]);

        $html = $this->page('en', 'pricing')->assertOk()->getContent();

        $this->assertStringContainsString('"lowPrice":"9.00"', $html);
        $this->assertStringContainsString('"offerCount":2', $html);
    }

    private function page(string $locale, string $route, array $params = []): \Illuminate\Testing\TestResponse
    {
        app()->setLocale($locale);

        return $this->getLocalizedRoute($route, $params);
    }
}
