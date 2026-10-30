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

    public function test_the_ard_catalog_lists_what_agents_can_use(): void
    {
        $response = $this->get('/.well-known/ai-catalog.json')->assertOk()->assertHeader('Access-Control-Allow-Origin', '*');
        $this->assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));

        $catalog = $response->json();
        $this->assertNotSame('', $catalog['specVersion']);
        $this->assertNotSame('', $catalog['host']['displayName']);
        $this->assertNotSame('', $catalog['host']['identifier']);
        $domain = (string) parse_url(url('/'), PHP_URL_HOST);
        foreach ($catalog['entries'] as $entry) {
            $this->assertStringStartsWith("urn:air:{$domain}:", $entry['identifier']);
            $this->assertNotSame('', $entry['displayName']);
            $this->assertNotSame('', $entry['type']);
            // Exactly one of url or data.
            $this->assertTrue(isset($entry['url']) xor isset($entry['data']), $entry['identifier']);
            $this->assertGreaterThanOrEqual(2, count($entry['representativeQueries']));
            $this->assertLessThanOrEqual(5, count($entry['representativeQueries']));
            $this->get((string) parse_url($entry['url'], PHP_URL_PATH))->assertOk();
        }

        $this->assertStringContainsString('Agentmap: '.url('/.well-known/ai-catalog.json'), $this->get('/robots.txt')->getContent());
        $this->assertStringContainsString('<link rel="ai-catalog" href="'.url('/.well-known/ai-catalog.json').'">', $this->getLocalizedRoute('home')->getContent());
    }

    public function test_auth_md_says_a_person_makes_the_key_and_how_to_send_it(): void
    {
        $response = $this->get('/auth.md')->assertOk();
        $this->assertStringStartsWith('text/markdown', (string) $response->headers->get('Content-Type'));

        $markdown = $response->getContent();
        $this->assertMatchesRegularExpression('/^# .*auth\.md/m', $markdown);
        $this->assertStringContainsString('an agent cannot create a Listen account for itself', $markdown);
        $this->assertStringContainsString('X-Listen-Api-Key', $markdown);
        foreach (\App\Support\Api\ApiScopes::all() as $scope) {
            $this->assertStringContainsString('`'.$scope.'`', $markdown);
        }
        // The MCP server takes the same key, or "Sign in with Listen", whose documents it names and serves.
        $this->assertStringContainsString('## MCP server', $markdown);
        $this->assertStringContainsString('`'.url('/mcp').'`', $markdown);
        foreach (['/.well-known/oauth-authorization-server', '/.well-known/oauth-protected-resource/mcp'] as $document) {
            $this->assertStringContainsString(url($document), $markdown);
            $this->get($document)->assertOk();
        }
    }

    public function test_every_discovery_file_points_to_the_mcp_server(): void
    {
        $catalog = collect($this->get('/.well-known/ai-catalog.json')->json('entries'))->keyBy('type');
        $this->assertSame(url('/mcp/server-card'), $catalog['application/mcp-server-card+json']['url']);

        $linkset = collect($this->get('/.well-known/api-catalog')->json('linkset'))->keyBy('anchor');
        $this->assertSame(url('/mcp/server-card'), $linkset[url('/mcp')]['service-desc'][0]['href']);

        $this->assertStringContainsString(url('/mcp').' (Streamable HTTP', $this->get('/llms.txt')->getContent());
        $this->assertStringContainsString('`'.url('/mcp').'`', $this->get('/.well-known/agent-skills/'.AgentDiscovery::SKILL.'/SKILL.md')->getContent());
        // The skill index still matches the skill it lists.
        $this->assertSame('sha256:'.hash('sha256', $this->get('/.well-known/agent-skills/'.AgentDiscovery::SKILL.'/SKILL.md')->getContent()),
            $this->get('/.well-known/agent-skills/index.json')->json('skills.0.digest'));
    }

    public function test_pages_offer_their_tools_to_agents_in_the_browser(): void
    {
        $page = $this->getLocalizedRoute('pricing')->assertOk()->getContent();
        $this->assertStringContainsString('<script id="listen-webmcp">', $page);
        $this->assertStringContainsString("name: 'listen_read_page'", $page);
        $this->assertStringContainsString("name: 'listen_open_page'", $page);

        $contact = $this->getLocalizedRoute('contact')->assertOk()->getContent();
        $this->assertStringContainsString('toolname="listen_contact"', $contact);
        $this->assertSame(4, substr_count($contact, 'toolparamdescription='));
    }
}
