<?php

namespace Tests\Feature\Agents;

use App\Models\Plan;
use App\Models\SiteSetting;
use App\Support\Agents\AgentAccess;
use App\Support\SeoHelper;
use App\Support\StudioFacts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Once;
use Illuminate\Testing\TestResponse;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Tests\TestCase;

/**
 * Search engines and AI agents hear of the voice agents: llms.txt has their own section, their
 * page has its own structured data, questions, words and picture, and it reads as Markdown.
 * None of it names another company, and none of it shows while Agents are closed.
 */
class VoiceAgentsSeoTest extends TestCase
{
    use RefreshDatabase;

    private const OTHER_NAMES = ['WordPress', 'Shopify', 'Wix', 'Laravel', 'Salla', 'Zid', 'Gemini', 'Google', 'Nabrah', 'Microsoft'];

    protected function setUp(): void
    {
        parent::setUp();
        // The app booted before the test database was made: forget what it found then.
        Once::flush();
        SiteSetting::set('agents_credits_per_minute', '1500', 'agents');
        Plan::factory()->create(['slug' => 'creator-seo', 'name' => 'Creator', 'price_cents' => 2200, 'monthly_chars' => 180000, 'is_active' => true,
            'agents_enabled' => true, 'agents_max' => 3, 'agent_minutes_per_month' => 0, 'agent_concurrent_calls' => 5, 'agent_max_call_minutes' => 10]);
    }

    private function page(string $locale, array $headers = []): TestResponse
    {
        app()->setLocale($locale);

        return $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class, LaravelLocalizationViewPath::class])
            ->withSession(['locale' => $locale])->withHeaders($headers)
            ->get($this->localizedRoute('agents-landing'));
    }

    /** @return array<int, array<string, mixed>> */
    private function graph(string $html): array
    {
        $this->assertSame(1, preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m));

        return json_decode($m[1], true, flags: JSON_THROW_ON_ERROR)['@graph'];
    }

    public function test_llms_txt_describes_the_voice_agents_from_the_plans_and_settings(): void
    {
        $text = (string) $this->get('/llms.txt')->assertOk()->getContent();
        $base = rtrim((string) config('app.url'), '/');

        $this->assertStringContainsString('It also makes AI voice agents for businesses', $text);
        $this->assertStringContainsString("\n## Voice agents\n", $text);
        $this->assertStringContainsString("[Voice agents]({$base}/en/voice-agents)", $text);
        $this->assertStringContainsString("[Arabic version]({$base}/ar/voice-agents)", $text);
        // Every number from the server: the rate, the free test seconds and the plan's own limits.
        $this->assertStringContainsString('one call minute uses 1,500 credits', $text);
        $this->assertStringContainsString('Speaks '.StudioFacts::arabicDialects().' spoken Arabic dialects, Modern Standard Arabic and '.StudioFacts::englishAccents().' English accents', $text);
        $this->assertStringContainsString('  - Creator, $22 a month: up to '.number_format(intdiv(180000, 1500)).' call minutes a month, 3 agents, 5 calls at the same time, calls up to 10 minutes long', $text);
        $this->assertStringContainsString('Free test calls: every account gets '.(AgentAccess::freeTestSeconds() / 60).' minutes of test calls free', $text);
        // The section sits with what the studio does, before the official addresses.
        $this->assertLessThan(strpos($text, '## Official URLs'), strpos($text, '## Voice agents'));
        foreach (self::OTHER_NAMES as $name) {
            $this->assertStringNotContainsStringIgnoringCase($name, $text, "llms.txt names {$name}");
        }
    }

    public function test_nothing_tells_of_the_voice_agents_while_they_are_closed(): void
    {
        SiteSetting::set('agents_audience', AgentAccess::AUDIENCE_OFF, 'agents');

        $text = (string) $this->get('/llms.txt')->assertOk()->getContent();
        $this->assertStringNotContainsString('voice agent', strtolower($text));
        $this->assertStringNotContainsString('/voice-agents', $text);
        $this->assertNotContains('AI voice agents that answer a business\'s callers in their dialect, on its website, a link or a QR code', SeoHelper::featureList('en'));
        $this->get('/sitemap.xml')->assertOk()->assertDontSee('/voice-agents', false);
    }

    public function test_the_page_has_its_own_product_questions_and_words_for_search_engines(): void
    {
        foreach (['en', 'ar'] as $locale) {
            $html = (string) $this->page($locale)->assertOk()->getContent();
            $graph = collect($this->graph($html));

            // The page is about the voice agents, a product of the studio's own.
            $agents = $graph->firstWhere('@id', SeoHelper::canonicalUrl('/en', 'en').'#voice-agents');
            $this->assertNotNull($agents, $locale.': no voice agent node');
            $this->assertSame('SoftwareApplication', $agents['@type']);
            $this->assertSame('AI voice agent', $agents['applicationSubCategory']);
            $this->assertSame(SeoHelper::pageUrl('agents_landing', $locale), $agents['url']);
            $this->assertSame(SeoHelper::voiceAgentFeatures($locale), $agents['featureList']);
            $this->assertSame(['@type' => 'AggregateOffer', 'priceCurrency' => strtoupper((string) config('cashier.currency', 'usd')),
                'lowPrice' => '22.00', 'highPrice' => '22.00', 'offerCount' => 1, 'url' => SeoHelper::pageUrl('pricing', $locale)], $agents['offers']);
            $this->assertStringContainsString('images/og/listen-voice-agents-'.$locale.'.png', $agents['image']);
            $page = $graph->firstWhere('@type', 'WebPage');
            $this->assertSame(['@id' => $agents['@id']], $page['about']);
            $this->assertSame(['@id' => $agents['@id']], $page['mainEntity']);

            // The questions at the foot of the page, with the rate written in.
            $faq = $graph->firstWhere('@type', 'FAQPage');
            $this->assertNotNull($faq, $locale.': no questions');
            app()->setLocale($locale);
            $this->assertSame(array_column(__('agents_landing.faq'), 0), array_column($faq['mainEntity'], 'name'));
            $answers = implode(' ', array_column(array_column($faq['mainEntity'], 'acceptedAnswer'), 'text'));
            $this->assertStringNotContainsString(':rate', $answers);
            $this->assertStringContainsString('1,500', $answers);

            // Its own words, not the studio's.
            $this->assertStringContainsString('<meta name="keywords" content="'.e(__('agents_landing.meta_keywords')).'">', $html);
            $this->assertStringContainsString($locale === 'ar' ? 'وكيل صوتي' : 'AI voice agent', __('agents_landing.meta_keywords'));

            $json = json_encode($graph->all(), JSON_UNESCAPED_UNICODE);
            foreach (self::OTHER_NAMES as $name) {
                $this->assertStringNotContainsStringIgnoringCase($name, $json.' '.__('agents_landing.meta_keywords'), "{$locale}: {$name}");
            }
        }
    }

    public function test_keywords_set_in_the_dashboard_still_win(): void
    {
        SiteSetting::set('seo_agents_landing_keywords', 'my own words', 'seo');

        $this->page('en')->assertOk()->assertSee('<meta name="keywords" content="my own words">', false);
    }

    public function test_the_page_is_shared_with_its_own_picture_in_its_language(): void
    {
        // Even with a site-wide picture set in the dashboard.
        SiteSetting::set('og_image_url', 'https://example.test/site.png', 'seo');

        foreach (['en', 'ar'] as $locale) {
            $this->assertFileExists(public_path('images/og/listen-voice-agents-'.$locale.'.png'));
            $html = (string) $this->page($locale)->assertOk()->getContent();
            $this->assertMatchesRegularExpression('#<meta property="og:image" content="[^"]*images/og/listen-voice-agents-'.$locale.'\.png\?v=\d+">#', $html);
            $this->assertMatchesRegularExpression('#<meta name="twitter:image" content="[^"]*images/og/listen-voice-agents-'.$locale.'\.png\?v=\d+">#', $html);
            $this->assertStringContainsString('<meta property="og:image:width" content="1200">', $html);
            $this->assertStringContainsString('<meta property="og:image:height" content="630">', $html);
        }

        // Other pages keep the site's picture.
        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class, LaravelLocalizationViewPath::class])
            ->get($this->localizedRoute('pricing'))->assertOk()
            ->assertSee('<meta property="og:image" content="https://example.test/site.png">', false);
    }

    public function test_an_ai_agent_reads_the_page_as_markdown(): void
    {
        $response = $this->page('en', ['Accept' => 'text/markdown'])->assertOk();

        $this->assertStringStartsWith('text/markdown', (string) $response->headers->get('Content-Type'));
        $markdown = (string) $response->getContent();
        $this->assertStringStartsWith("---\ntitle: ", $markdown);
        $this->assertStringContainsString(__('agents_landing.steps_title'), $markdown);
        $this->assertStringContainsString(__('agents_landing.faq.0.0'), $markdown);
        $this->assertStringNotContainsString('<script', $markdown);
    }

    public function test_the_studio_lists_its_voice_agents_among_what_it_does(): void
    {
        $this->assertContains('AI voice agents that answer a business\'s callers in their dialect, on its website, a link or a QR code', SeoHelper::featureList('en'));
        $this->assertContains('وكلاء صوتيون بالذكاء الاصطناعي يردون على عملاء نشاطك بلهجتهم، في موقعك أو عبر رابط أو رمز استجابة سريعة', SeoHelper::featureList('ar'));
    }
}
