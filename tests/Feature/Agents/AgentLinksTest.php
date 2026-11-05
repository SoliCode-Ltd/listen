<?php

namespace Tests\Feature\Agents;

use App\Models\Agent;
use App\Models\AgentConversation;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Agents\AgentReview;
use App\Services\Agents\LiveSession;
use App\Services\Agents\SiteReader;
use App\Support\Agents\AgentInstructions;
use App\Support\Agents\AgentLinks;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Tests\TestCase;

/**
 * A caller who asks for the business's accounts on social media gets them written in the call's
 * chat, from its website (the icons in its header and footer too) or as the owner set them, and
 * never anything but the business's own accounts and pages.
 */
class AgentLinksTest extends TestCase
{
    use RefreshDatabase;

    private const SITE = 'https://clinic.example';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.gemini_tts.api_key' => 'test-key']);
        Plan::factory()->free()->create(['agents_enabled' => true, 'monthly_chars' => 10000, 'voice_tier' => 'standard']);
        SiteSetting::set('agents_credits_per_minute', '8100', 'agents');
        // The test website lives on a made-up public host, at a documentation address.
        $this->app->bind(SiteReader::class, fn () => new class extends SiteReader
        {
            protected function addressFor(string $host): ?string
            {
                return $host === 'clinic.example' ? '203.0.113.7' : null;
            }
        });
    }

    private function withoutLocale(): static
    {
        return $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class, LaravelLocalizationViewPath::class, ValidateCsrfToken::class]);
    }

    private function agent(User $owner, array $attributes = []): Agent
    {
        return Agent::create(array_merge([
            'user_id' => $owner->id, 'name' => 'Nawaf', 'business_name' => 'Saudi Ophthalmological Society', 'business_type' => 'healthcare',
            'jobs' => ['answer'], 'language' => 'ar', 'dialect' => 'ar-SA', 'status' => Agent::READY, 'website_url' => self::SITE,
        ], $attributes));
    }

    /** The society's website: its accounts in the header, the footer and its structured data, and other links besides. */
    private function fakeWebsite(): void
    {
        Http::fake([
            '*generateContent' => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode([
                'first_message' => 'هلا والله، معك نواف من الجمعية', 'identity' => 'Calm.', 'never' => [], 'steps' => [], 'handoff' => '',
                'knowledge' => 'الجمعية السعودية لطب العيون', 'questions' => [],
            ], JSON_UNESCAPED_UNICODE)]]]]]]),
            'https://clinic.example' => fn () => Http::response($this->home, 200, ['Content-Type' => 'text/html']),
            'https://clinic.example/' => fn () => Http::response($this->home, 200, ['Content-Type' => 'text/html']),
            'https://clinic.example/contact-us' => Http::response('<title>Contact</title><p>Call us</p>'
                .'<footer><a href="https://twitter.com/SOS_KSA">X</a> <a href="https://api.whatsapp.com/send?phone=966500000000&amp;text=hi">Chat</a></footer>', 200, ['Content-Type' => 'text/html']),
            '*' => Http::response('nope', 404),
        ]);
    }

    private string $home = '<title>SOS</title><header><a href="https://twitter.com/SOS_KSA"><svg></svg></a></header>'
        .'<p>Saudi Ophthalmological Society</p><a href="/contact-us">Contact</a>'
        .'<p>Read the ministry\'s news <a href="https://x.com/SaudiMOH">here</a>.</p>'
        .'<a href="https://www.facebook.com/sharer/sharer.php?u=x">Share</a> <a href="https://www.youtube.com/watch?v=abc">Video</a>'
        .'<script type="application/ld+json">{"@type":"Organization","sameAs":["https:\/\/www.linkedin.com\/company\/saudi-oph","https://instagram.com/sos_ksa"]}</script>'
        ."<footer><a href=\"https://instagram.com/sos_ksa/?igshid=1\">IG</a><a href='https://twitter.com/SOS_KSA'>X</a></footer>";

    public function test_only_an_accounts_own_page_is_kept_written_one_way(): void
    {
        $kept = fn (string $url, ?string $site = null) => AgentLinks::clean($url, $site)['url'] ?? null;

        $this->assertSame('https://www.facebook.com/SOS.KSA', $kept('https://m.facebook.com/SOS.KSA/?ref=page_internal'));
        $this->assertSame('https://www.instagram.com/sos_ksa', $kept('instagram.com/sos_ksa/?igshid=abc'));
        $this->assertSame('https://x.com/SOS_KSA', $kept('https://twitter.com/SOS_KSA'));
        $this->assertSame('https://www.linkedin.com/company/saudi-oph', $kept('https://sa.linkedin.com/company/saudi-oph/about'));
        $this->assertSame('https://www.youtube.com/@sosksa', $kept('https://www.youtube.com/@sosksa'));
        $this->assertSame('https://www.tiktok.com/@sos.ksa', $kept('https://www.tiktok.com/@sos.ksa'));
        $this->assertSame('https://wa.me/966501234567', $kept('https://api.whatsapp.com/send?phone=966501234567&text=hi'));
        $this->assertSame('https://t.me/sos_channel', $kept('https://t.me/s/sos_channel'));
        // Shapes real websites use (a ministry's and a university's footers).
        $this->assertSame('https://www.snapchat.com/t/k38TTpLO', $kept('https://snapchat.com/t/k38TTpLO'));
        $this->assertSame('https://www.youtube.com/user/pdksuchannel', $kept('https://www.youtube.com/user/pdksuchannel'));
        $this->assertSame('https://www.linkedin.com/school/king-saud-university', $kept('https://www.linkedin.com/school/king-saud-university/'));
        $this->assertSame('https://wa.me/447403650155', $kept('https://wa.me/447403650155?text=%D9%85%D8%B1%D8%AD%D8%A8%D8%A7'));
        // A page of the business's own website, without its tracking.
        $this->assertSame('https://www.clinic.example/register?id=7', $kept('https://www.clinic.example/register?utm_source=x&id=7', self::SITE));

        // Never a share button, a single post or video, another website, or a script.
        foreach (['https://www.facebook.com/sharer/sharer.php?u=x', 'https://www.facebook.com/SOS.KSA/posts/123', 'https://developers.facebook.com/docs',
            'https://www.instagram.com/p/Cx123/', 'https://twitter.com/intent/tweet?text=hi', 'https://x.com/SOS_KSA/status/1', 'https://youtu.be/abc',
            'https://www.youtube.com/watch?v=1', 'https://www.tiktok.com/@sos/video/1', 'https://wa.me/?text=share', 'https://evil.example/x',
            'javascript:alert(1)', 'https://user:pass@instagram.com/x', 'https://www.instagram.com:8443/sos'] as $url) {
            $this->assertNull(AgentLinks::clean($url, self::SITE), $url);
        }

        $this->assertSame('instagram.com/sos_ksa', AgentLinks::label('https://www.instagram.com/sos_ksa'));
        $this->assertSame('WhatsApp: +966501234567', AgentLinks::describe(['network' => 'whatsapp', 'url' => 'https://wa.me/966501234567']));
    }

    public function test_the_website_reader_finds_the_accounts_in_its_header_footer_and_structured_data(): void
    {
        $this->fakeWebsite();

        $read = app(SiteReader::class)->read(self::SITE.'/');

        // One account a network, the one on every page (not the ministry's, linked once from an
        // article), and no share button or video.
        $this->assertSame([
            ['network' => 'x', 'url' => 'https://x.com/SOS_KSA'],
            ['network' => 'instagram', 'url' => 'https://www.instagram.com/sos_ksa'],
            ['network' => 'linkedin', 'url' => 'https://www.linkedin.com/company/saudi-oph'],
            ['network' => 'whatsapp', 'url' => 'https://wa.me/966500000000'],
        ], $read['links']);
        $this->assertStringContainsString('Saudi Ophthalmological Society', $read['text']);
    }

    public function test_a_new_agent_keeps_the_links_found_on_its_website(): void
    {
        $this->fakeWebsite();
        $owner = User::factory()->create();

        $this->actingAs($owner)->withoutLocale()->postJson($this->localizedRoute('voice-agents.store'), [
            'business_name' => 'Saudi Ophthalmological Society', 'name' => 'Nawaf', 'business_type' => 'healthcare',
            'language' => 'ar', 'dialect' => 'ar-SA', 'website_url' => 'clinic.example',
        ])->assertOk()->assertJsonPath('agent.links.0.label', 'x.com/SOS_KSA')->assertJsonPath('agent.links.4.network', 'website');

        $agent = Agent::where('user_id', $owner->id)->sole();
        $this->assertSame(['https://x.com/SOS_KSA', 'https://www.instagram.com/sos_ksa', 'https://www.linkedin.com/company/saudi-oph',
            'https://wa.me/966500000000', 'https://clinic.example'], array_column(AgentLinks::of($agent), 'url'));
        $this->assertNotNull($agent->setting('links_found_at'));
    }

    public function test_the_owner_adds_and_removes_links_and_only_accounts_are_kept(): void
    {
        $owner = User::factory()->create();
        $agent = $this->agent($owner, ['settings' => ['links' => [['network' => 'x', 'url' => 'https://x.com/SOS_KSA']]]]);
        $this->actingAs($owner);

        // A link to anywhere else is refused, and nothing changes.
        $this->withoutLocale()->patchJson($this->localizedRoute('voice-agents.update', ['agent' => $agent]), ['links' => ['https://x.com/SOS_KSA', 'https://phish.example/login']])
            ->assertStatus(422)->assertJsonPath('message', __('agents.links_invalid'));
        $this->assertCount(1, AgentLinks::of($agent->fresh()));

        $this->withoutLocale()->patchJson($this->localizedRoute('voice-agents.update', ['agent' => $agent]), ['links' => ['https://x.com/SOS_KSA', 'snapchat.com/add/sos.ksa', 'https://www.clinic.example/register']])
            ->assertOk()->assertJsonPath('agent.links.1.url', 'https://www.snapchat.com/add/sos.ksa')->assertJsonPath('agent.links.2.network', 'website');
        $agent->refresh();
        $this->assertTrue($agent->setting('links_edited'));
        $this->assertStringContainsString('Links it writes in the chat when asked: https://x.com/SOS_KSA | https://www.snapchat.com/add/sos.ksa', AgentReview::material($agent));

        // Removing them all leaves none, and the website's are not brought back when the agent is written again.
        $this->withoutLocale()->patchJson($this->localizedRoute('voice-agents.update', ['agent' => $agent]), ['links' => []])->assertOk()->assertJsonCount(0, 'agent.links');
        AgentLinks::keepFound($agent->fresh(), [['network' => 'x', 'url' => 'https://x.com/SOS_KSA']]);
        $this->assertSame([], AgentLinks::of($agent->fresh()));
    }

    public function test_find_on_my_website_adds_the_new_ones(): void
    {
        $this->fakeWebsite();
        $owner = User::factory()->create();
        $agent = $this->agent($owner, ['settings' => ['links' => [['network' => 'tiktok', 'url' => 'https://www.tiktok.com/@sos.ksa']]]]);

        $this->actingAs($owner)->withoutLocale()->postJson($this->localizedRoute('voice-agents.links', ['agent' => $agent]))
            ->assertOk()->assertJsonPath('added', 5)->assertJsonPath('links.0.url', 'https://www.tiktok.com/@sos.ksa')
            ->assertJsonPath('message', trans_choice('agents.links_found', 5, ['count' => 5]));
        $this->assertCount(6, AgentLinks::of($agent->fresh()));

        $this->assertTrue($agent->fresh()->setting('links_edited'));
        // Nothing new the second time; the page's own first search leaves the list to follow the website.
        $agent->update(['settings' => array_merge($agent->fresh()->settings, ['links_edited' => false])]);
        $this->postJson($this->localizedRoute('voice-agents.links', ['agent' => $agent]), ['auto' => true])->assertOk()->assertJsonPath('added', 0);
        $this->assertFalse($agent->fresh()->setting('links_edited'));
        // A website that cannot be read says so.
        $agent->update(['website_url' => 'https://down.example']);
        $this->postJson($this->localizedRoute('voice-agents.links', ['agent' => $agent]))->assertStatus(422)->assertJsonPath('message', __('agents.links_unreachable'));
        // Another member cannot (to them the agent is not there).
        $this->actingAs(User::factory()->create())->postJson($this->localizedRoute('voice-agents.links', ['agent' => $agent]))->assertNotFound();
    }

    public function test_the_knowledge_tab_lists_the_links(): void
    {
        $owner = User::factory()->create();
        $agent = $this->agent($owner, ['settings' => ['links' => [['network' => 'instagram', 'url' => 'https://www.instagram.com/sos_ksa'], ['network' => 'website', 'url' => self::SITE]]]]);
        app()->setLocale('ar');

        $html = (string) $this->actingAs($owner)->withoutLocale()->get($this->localizedRoute('voice-agents.show', ['agent' => $agent]))->assertOk()->getContent();

        $this->assertStringContainsString('data-ag-links ', $html);
        $this->assertStringContainsString('>instagram.com/sos_ksa</a>', $html);
        $this->assertStringContainsString(e(__('agents.links_your_site')), $html);
        $this->assertStringContainsString('data-ag-links-find', $html);
        $this->assertLessThan(strpos($html, 'data-ag-files'), strpos($html, 'data-ag-links '));
    }

    /** A live agent on a paid plan, ready to take calls from its website. */
    private function liveAgent(array $settings): Agent
    {
        $plan = Plan::factory()->create(['slug' => 'pro-'.uniqid(), 'price_cents' => 4500, 'voice_tier' => 'hd', 'monthly_chars' => 500000,
            'allow_overage' => false, 'agents_enabled' => true, 'agent_concurrent_calls' => 20]);
        $owner = User::factory()->create();
        Subscription::create(['user_id' => $owner->id, 'plan_id' => $plan->id, 'status' => 'active']);

        return $this->agent($owner, ['status' => Agent::LIVE, 'live_voice' => 'Achernar', 'max_call_seconds' => 600, 'first_message' => 'هلا', 'settings' => $settings]);
    }

    private function callApi(string $path, array $body): TestResponse
    {
        return $this->call('POST', '/agent-api'.$path, [], [], [], ['CONTENT_TYPE' => 'text/plain;charset=UTF-8', 'REMOTE_ADDR' => '203.0.113.9', 'HTTP_ORIGIN' => self::SITE], json_encode($body));
    }

    public function test_a_call_writes_the_links_the_caller_asked_for_and_never_reads_them_out(): void
    {
        Http::fake(['*auth_tokens' => Http::response(['name' => 'auth_tokens/live-key'])]);

        // An agent without links has no such tool, and its instructions say nothing of links.
        $plain = $this->liveAgent([]);
        $this->assertSame(['save_contact', 'end_call'], array_column(LiveSession::tools($plain), 'name'));
        $this->assertStringNotContainsString('show_links', AgentInstructions::build($plain));

        $agent = $this->liveAgent(['links' => [['network' => 'x', 'url' => 'https://x.com/SOS_KSA'], ['network' => 'instagram', 'url' => 'https://www.instagram.com/sos_ksa'],
            ['network' => 'website', 'url' => self::SITE]]]);
        $tool = collect(LiveSession::tools($agent))->firstWhere('name', 'show_links');
        $this->assertSame(['x', 'instagram', 'website'], $tool['parameters']['properties']['networks']['items']['enum']);
        $text = AgentInstructions::build($agent);
        $this->assertStringContainsString("# Links you can write in the chat\n", $text);
        $this->assertStringContainsString("- Instagram: @sos_ksa\n- Website: clinic.example", $text);
        $this->assertStringContainsString('Never read a link or a web address aloud', $text);
        $this->assertStringContainsString("Only when they ask for them, you may write Saudi Ophthalmological Society's own links in the chat", $text);

        $start = $this->callApi('/calls', ['key' => $agent->mainWidget()->public_key, 'visitor' => 'v-links'])->assertOk();
        [$id, $key] = [$start->json('conversation'), $start->json('call_key')];

        $this->callApi("/calls/{$id}/tool", ['call_key' => $key, 'name' => 'show_links', 'args' => ['networks' => ['instagram']]])->assertOk()
            ->assertJsonPath('result', 'shown')->assertJsonPath('shown', ['Instagram'])
            ->assertJsonPath('links', [['network' => 'instagram', 'url' => 'https://www.instagram.com/sos_ksa', 'label' => 'instagram.com/sos_ksa']]);
        $this->callApi("/calls/{$id}/tool", ['call_key' => $key, 'name' => 'show_links', 'args' => []])->assertOk()->assertJsonCount(3, 'links');
        // A network it has no account on: nothing is written, and the agent is told what there is.
        $this->callApi("/calls/{$id}/tool", ['call_key' => $key, 'name' => 'show_links', 'args' => ['networks' => ['tiktok']]])->assertOk()
            ->assertJsonPath('result', 'none')->assertJsonMissingPath('links');

        // Kept with the call's words where it came, and shown to the owner as written in the chat.
        $this->callApi("/calls/{$id}/ping", ['call_key' => $key, 'turns' => [['role' => 'caller', 'text' => 'وش حسابكم في انستقرام؟'],
            ['role' => 'agent', 'kind' => 'links', 'text' => 'instagram.com/sos_ksa'], ['role' => 'agent', 'text' => 'كتبت لك الرابط في المحادثة']]])->assertOk();
        $transcript = AgentConversation::where('public_id', $id)->sole()->transcript;
        $this->assertSame(['caller', 'agent', 'agent'], array_column($transcript, 'role'));
        $this->assertSame('links', $transcript[1]['kind']);
        $this->assertArrayNotHasKey('kind', $transcript[2]);
    }

    public function test_a_call_writes_the_links_six_times_at_most(): void
    {
        Http::fake(['*auth_tokens' => Http::response(['name' => 'auth_tokens/live-key'])]);
        $agent = $this->liveAgent(['links' => [['network' => 'x', 'url' => 'https://x.com/SOS_KSA']]]);
        $start = $this->callApi('/calls', ['key' => $agent->mainWidget()->public_key, 'visitor' => 'v-many'])->assertOk();

        foreach (range(1, 7) as $i) {
            $result = $this->callApi("/calls/{$start->json('conversation')}/tool", ['call_key' => $start->json('call_key'), 'name' => 'show_links', 'args' => []])->json('result');
        }
        $this->assertSame('enough', $result);
    }
}
