<?php

namespace Tests\Feature;

use App\Models\SiteSetting;
use App\Support\AgentDiscovery;
use App\Support\HtmlToMarkdown;
use App\Support\SeoHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * What AI agents find on the site: a fast sitemap, robots.txt rules for AI
 * crawlers, public pages as Markdown on request, discovery links, the API
 * catalog and the agent skill for the developer API.
 */
class AgentReadyTest extends TestCase
{
    use RefreshDatabase;

    private const BROWSER_ACCEPT = 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8';

    public function test_the_sitemap_is_kept_once_built(): void
    {
        $first = $this->get('/sitemap.xml')->assertOk()->getContent();

        $this->assertTrue(Cache::has(SeoHelper::SITEMAP_CACHE));
        $this->assertSame($first, $this->get('/sitemap.xml')->getContent());

        // A newsletter's bookkeeping leaves it; a setting that can add or hide a page builds it again.
        SiteSetting::set('newsletter_send_hold_reason', '', 'mail');
        $this->assertTrue(Cache::has(SeoHelper::SITEMAP_CACHE));
        SiteSetting::set('video_dubbing_audience', 'everyone', 'video_dubbing');
        $this->assertFalse(Cache::has(SeoHelper::SITEMAP_CACHE));
    }

    public function test_robots_txt_allows_search_and_answers_but_not_training(): void
    {
        $robots = $this->get('/robots.txt')->assertOk()->getContent();

        $this->assertStringContainsString("User-agent: *\nContent-Signal: search=yes, ai-input=yes, ai-train=no\nAllow: /", $robots);
        $this->assertStringContainsString("User-agent: OAI-SearchBot\n", $robots);
        $this->assertStringContainsString("User-agent: cohere-training-data-crawler\nDisallow: /\n", $robots);
        foreach (SeoHelper::AI_TRAINING_CRAWLERS as $crawler) {
            $this->assertStringContainsString('User-agent: '.$crawler."\n", $robots);
        }
        $this->assertStringContainsString('Sitemap: ', $robots);
        // Gemini's answers come under the same name as its training, so it is not kept out.
        $this->assertStringNotContainsString('Google-Extended', $robots);
    }

    public function test_an_agent_asking_for_markdown_gets_the_page_as_markdown(): void
    {
        $response = $this->withHeaders(['Accept' => 'text/markdown'])->getLocalizedRoute('pricing')->assertOk();

        $this->assertStringStartsWith('text/markdown', (string) $response->headers->get('Content-Type'));
        $this->assertGreaterThan(0, (int) $response->headers->get('X-Markdown-Tokens'));
        $this->assertStringContainsString('Accept', (string) $response->headers->get('Vary'));

        $markdown = $response->getContent();
        $this->assertStringStartsWith("---\ntitle: ", $markdown);
        $this->assertMatchesRegularExpression('/^# \S/m', $markdown);
        $this->assertStringNotContainsString('<html', $markdown);
        $this->assertStringNotContainsString('<script', $markdown);
    }

    public function test_browsers_keep_getting_html(): void
    {
        foreach ([self::BROWSER_ACCEPT, '*/*', 'text/html, text/markdown'] as $accept) {
            $response = $this->withHeaders(['Accept' => $accept])->getLocalizedRoute('pricing')->assertOk();
            $this->assertStringStartsWith('text/html', (string) $response->headers->get('Content-Type'), $accept);
        }

        $ranked = $this->withHeaders(['Accept' => 'text/markdown, text/html;q=0.9'])->getLocalizedRoute('home')->assertOk();
        $this->assertStringStartsWith('text/markdown', (string) $ranked->headers->get('Content-Type'));
    }

    public function test_public_pages_point_agents_to_the_api_and_the_markdown(): void
    {
        $links = implode(', ', $this->withHeaders(['Accept' => self::BROWSER_ACCEPT])
            ->getLocalizedRoute('home')->assertOk()->headers->all('link'));

        $this->assertStringContainsString('/.well-known/api-catalog>; rel="api-catalog"', $links);
        $this->assertStringContainsString('/v1/openapi.json>; rel="service-desc"', $links);
        $this->assertStringContainsString('rel="service-doc"', $links);
        $this->assertStringContainsString('/llms.txt>; rel="describedby"', $links);
        $this->assertStringContainsString('rel="alternate"; type="text/markdown"', $links);
    }

    public function test_pages_for_members_are_not_turned_into_markdown(): void
    {
        $response = $this->withHeaders(['Accept' => 'text/markdown'])->getLocalizedRoute('login')->assertOk();

        $this->assertStringStartsWith('text/html', (string) $response->headers->get('Content-Type'));
        $this->assertSame([], $response->headers->all('link'));
    }

    public function test_the_api_catalog_points_to_the_api_its_description_and_guide(): void
    {
        $response = $this->get('/.well-known/api-catalog')->assertOk();

        $this->assertStringStartsWith('application/linkset+json', (string) $response->headers->get('Content-Type'));
        $entry = $response->json('linkset.0');
        $this->assertSame(url('/v1'), $entry['anchor']);
        $this->assertSame(url('/v1/openapi.json'), $entry['service-desc'][0]['href']);
        $this->assertSame(['en', 'ar'], array_column($entry['service-doc'], 'hreflang'));
        $this->assertSame(url('/up'), $entry['status'][0]['href']);

        $this->get('/v1/openapi.json')->assertOk();
        $this->get('/up')->assertOk();
    }

    public function test_the_skill_index_lists_the_skill_with_its_true_digest(): void
    {
        $index = $this->get('/.well-known/agent-skills/index.json')->assertOk()->json();

        $this->assertSame(AgentDiscovery::SKILLS_SCHEMA, $index['$schema']);
        $skill = $index['skills'][0];
        $this->assertMatchesRegularExpression('/^[a-z0-9]+(-[a-z0-9]+)*$/', $skill['name']);
        $this->assertSame('skill-md', $skill['type']);
        $this->assertLessThanOrEqual(1024, mb_strlen($skill['description']));

        $file = $this->get($skill['url'])->assertOk();
        $this->assertStringStartsWith('text/markdown', (string) $file->headers->get('Content-Type'));
        $this->assertSame('sha256:'.hash('sha256', $file->getContent()), $skill['digest']);

        // Front matter: the name and the same description, as plain YAML values.
        $this->assertStringStartsWith("---\nname: ".$skill['name']."\ndescription: ".$skill['description']."\n---\n", $file->getContent());
        $this->assertStringNotContainsString(': ', $skill['description']);
    }

    public function test_html_becomes_readable_markdown(): void
    {
        $html = <<<'HTML'
            <html lang="en"><head><title>Plans - Listen</title><meta name="description" content="All plans."></head>
            <body><nav><a href="/">Home</a></nav><main>
              <h1>Plans</h1>
              <p>Pay for <strong>the minutes</strong> you need. <a href="/en/pricing#compare">Compare</a> or <a href="/en/register">sign up</a>.</p>
              <div><s>$9</s> $6.75 /month</div>
              <ul><li>500 minutes</li><li>Dialogue <span>mode</span></li></ul>
              <table><tr><th>Feature</th><th>Pro</th></tr><tr><td>Dialogue</td><td><svg role="img" aria-label="✓"></svg></td></tr></table>
              <span aria-hidden="true">decoration</span><button>Play</button>
              <section data-markdown="skip"><p>Calculator</p></section>
              <script>alert(1)</script>
            </main></body></html>
            HTML;

        $markdown = HtmlToMarkdown::convert($html, 'https://listenai.studio/en/pricing');

        $this->assertStringStartsWith("---\ntitle: \"Plans - Listen\"\ndescription: \"All plans.\"\nurl: \"https://listenai.studio/en/pricing\"\nlanguage: \"en\"\n---\n\n# Plans", $markdown);
        $this->assertStringContainsString('Pay for **the minutes** you need. [Compare](https://listenai.studio/en/pricing#compare) or [sign up](https://listenai.studio/en/register).', $markdown);
        $this->assertStringContainsString('~~$9~~ $6.75 /month', $markdown);
        $this->assertStringContainsString("- 500 minutes\n- Dialogue mode", $markdown);
        $this->assertStringContainsString("| Feature | Pro |\n| --- | --- |\n| Dialogue | ✓ |", $markdown);
        foreach (['Home', 'decoration', 'Play', 'Calculator', 'alert'] as $left) {
            $this->assertStringNotContainsString($left, $markdown);
        }
    }
}
