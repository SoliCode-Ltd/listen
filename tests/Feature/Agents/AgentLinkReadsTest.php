<?php

namespace Tests\Feature\Agents;

use App\Jobs\ReadAgentLinks;
use App\Models\Agent;
use App\Models\AgentSource;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\Agents\LinkReader;
use App\Services\Agents\LiveSession;
use App\Services\Agents\SiteReader;
use App\Support\Agents\AgentInstructions;
use App\Support\Agents\AgentLinkReads;
use App\Support\Agents\AgentLinks;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Tests\TestCase;

/**
 * The agent knows the business's latest news from its links (Knowledge → Social media links): the
 * newest posts of its accounts that anyone can see, its website's news feed, and pages the owner
 * adds, read when added and again every few hours, with their dates, as facts and never as orders.
 */
class AgentLinkReadsTest extends TestCase
{
    use RefreshDatabase;

    private const SITE = 'https://clinic.example';

    /** The hosts the tests read from, all at a documentation address. */
    private const HOSTS = ['clinic.example', 'www.clinic.example', 'syndication.twitter.com', 't.me', 'www.youtube.com', 'news.sos-ksa.org'];

    protected function setUp(): void
    {
        parent::setUp();
        Plan::factory()->free()->create(['agents_enabled' => true, 'monthly_chars' => 10000, 'voice_tier' => 'standard']);
        SiteSetting::set('agents_credits_per_minute', '8100', 'agents');
        $this->app->bind(SiteReader::class, fn () => new class extends SiteReader
        {
            protected function addressFor(string $host): ?string
            {
                return in_array($host, ['clinic.example', 'www.clinic.example', 'syndication.twitter.com', 't.me', 'www.youtube.com', 'news.sos-ksa.org'], true) ? '203.0.113.7' : null;
            }
        });
    }

    private function agent(User $owner, array $attributes = []): Agent
    {
        return Agent::create(array_merge([
            'user_id' => $owner->id, 'name' => 'Nawaf', 'business_name' => 'Saudi Ophthalmological Society', 'business_type' => 'healthcare',
            'jobs' => ['answer'], 'language' => 'ar', 'dialect' => 'ar-SA', 'status' => Agent::READY, 'website_url' => self::SITE,
        ], $attributes));
    }

    private function withoutLocale(): static
    {
        return $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class, LaravelLocalizationViewPath::class, ValidateCsrfToken::class]);
    }

    private static function html(string $body): PromiseInterface
    {
        return Http::response($body, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /** The account's timeline as X shows it on other websites: its posts, one it shared, a reply to someone else, an old one. */
    private static function xTimeline(): string
    {
        $post = fn (int $daysAgo, string $text, array $more = []) => ['type' => 'tweet', 'content' => ['tweet' => $more + [
            'created_at' => now()->subDays($daysAgo)->format('D M d H:i:s O Y'), 'full_text' => $text,
            'user' => ['screen_name' => $more['user']['screen_name'] ?? 'SOS_KSA', 'name' => 'الجمعية السعودية لطب العيون'],
        ]]];
        $entries = [
            $post(10, 'التسجيل في ورشة القرنية مفتوح &amp; مجاني'),
            $post(2, 'ندوة طب العيون يوم 20 نوفمبر في الرياض https://t.co/abc123'),
            $post(1, 'RT: خبر من حساب آخر', ['retweeted_status' => ['id_str' => '1']]),
            $post(1, '@someone شكرًا لك', ['in_reply_to_screen_name' => 'someone']),
            $post(400, 'مؤتمر قديم'),
        ];

        return '<html><body><script id="__NEXT_DATA__" type="application/json">'.json_encode(['props' => ['pageProps' => ['timeline' => ['entries' => $entries]]]]).'</script></body></html>';
    }

    public function test_an_x_account_gives_its_own_newest_posts_with_their_dates_and_no_links(): void
    {
        Http::fake(['https://syndication.twitter.com/srv/timeline-profile/screen-name/SOS_KSA' => self::html(self::xTimeline())]);

        $read = app(LinkReader::class)->read(['network' => 'x', 'url' => 'https://x.com/SOS_KSA']);

        $this->assertTrue($read['ok']);
        $this->assertSame('posts', $read['kind']);
        // Its own posts only, newest first, from the last six months, words without links.
        $this->assertSame(['ندوة طب العيون يوم 20 نوفمبر في الرياض', 'التسجيل في ورشة القرنية مفتوح & مجاني'], array_column($read['items'], 'text'));
        $this->assertSame(now()->subDays(2)->utc()->format('Y-m-d'), substr($read['items'][0]['at'], 0, 10));
        $this->assertFalse($read['items'][0]['about']);
        $this->assertSame('الجمعية السعودية لطب العيون', $read['title']);
    }

    public function test_a_telegram_channel_and_a_youtube_channel_give_their_newest_posts(): void
    {
        $message = fn (string $at, string $text, bool $forwarded = false) => '<div class="tgme_widget_message_wrap js-widget_message_wrap"><div class="tgme_widget_message">'
            .($forwarded ? '<div class="tgme_widget_message_forwarded_from">From another channel</div>' : '')
            .'<div class="tgme_widget_message_text js-message_text" dir="auto">'.$text.'</div><a class="tgme_widget_message_date"><time datetime="'.$at.'" class="time">10:00</time></a></div></div>';
        $telegram = '<html><head><meta property="og:title" content="SOS Channel"></head><body><div class="tgme_channel_info"></div>'
            .$message(now()->subDays(5)->toIso8601String(), 'ورشة <b>جديدة</b><br/>الأحد القادم')
            .$message(now()->subDays(1)->toIso8601String(), 'منشور من قناة أخرى', true)
            .$message(now()->subDays(3)->toIso8601String(), 'نتائج القبول <a href="https://example.org/r">هنا</a>').'</body></html>';
        $video = fn (string $title, string $ago) => ['richItemRenderer' => ['content' => ['lockupViewModel' => ['metadata' => ['lockupMetadataViewModel' => [
            'title' => ['content' => $title],
            'metadata' => ['contentMetadataViewModel' => ['metadataRows' => [['metadataParts' => [['text' => ['content' => '1.2K views']], ['text' => ['content' => $ago]]]]]]],
        ]]]]]];
        $youtube = '<html><script>var ytInitialData = '.json_encode(['contents' => ['tabs' => [['richGridRenderer' => ['contents' => [
            $video('مؤتمر الجمعية 2026', '2 weeks ago'),
            ['richItemRenderer' => ['content' => ['videoRenderer' => ['title' => ['runs' => [['text' => 'لقاء مع الأطباء']]], 'publishedTimeText' => ['simpleText' => '3 days ago']]]]],
        ]]]]], 'metadata' => ['channelMetadataRenderer' => ['title' => 'SOS']]]).';</script></html>';
        Http::fake(['https://t.me/s/sos_channel' => self::html($telegram), 'https://www.youtube.com/@sosksa/videos' => self::html($youtube)]);
        $reader = app(LinkReader::class);

        $channel = $reader->read(['network' => 'telegram', 'url' => 'https://t.me/sos_channel']);
        $this->assertSame(['نتائج القبول هنا', 'ورشة جديدة الأحد القادم'], array_column($channel['items'], 'text'));
        $this->assertSame('SOS Channel', $channel['title']);

        $videos = $reader->read(['network' => 'youtube', 'url' => 'https://www.youtube.com/@sosksa']);
        $this->assertSame(['لقاء مع الأطباء', 'مؤتمر الجمعية 2026'], array_column($videos['items'], 'text'));
        // YouTube says only how long ago: the date is "around".
        $this->assertTrue($videos['items'][0]['about']);
        $this->assertSame(now()->subDays(3)->format('Y-m-d'), substr($videos['items'][0]['at'], 0, 10));

        // A private channel's invite is never asked for.
        $this->assertSame(LinkReader::CLOSED, LinkReader::way(['network' => 'telegram', 'url' => 'https://t.me/+AbCdEfGh123']));
    }

    public function test_a_page_with_a_news_feed_gives_its_posts_and_a_page_without_one_its_words(): void
    {
        $feed = '<?xml version="1.0"?><rss version="2.0"><channel><title>SOS News</title>'
            .'<item><title>ندوة القرنية</title><pubDate>Mon, '.now()->subDays(4)->format('d M Y').' 09:00:00 +0300</pubDate><description><![CDATA[<p>يوم <b>الخميس</b> القادم</p>]]></description></item>'
            .'<item><title>خبر قديم</title><pubDate>'.now()->subYears(2)->toRfc2822String().'</pubDate></item></channel></rss>';
        Http::fake([
            'https://www.clinic.example' => self::html('<html><head><title>SOS</title><link rel="alternate" type="application/rss+xml" title="Comments Feed" href="/comments/feed/"><link rel="alternate" type="application/rss+xml" title="SOS Feed" href="/feed/"></head><body>Home</body></html>'),
            'https://www.clinic.example/feed/' => Http::response($feed, 200, ['Content-Type' => 'application/rss+xml; charset=UTF-8']),
            'https://news.sos-ksa.org/events' => self::html('<html><head><title>Events</title></head><body><nav>Menu</nav><main><h1>الفعاليات القادمة</h1><p>المؤتمر السنوي يوم 12 ديسمبر 2026 في جدة، والتسجيل مفتوح.</p></main></body></html>'),
            'https://clinic.example' => self::html('<html><head><title>SOS</title></head><body>No feed here</body></html>'),
        ]);
        $reader = app(LinkReader::class);

        // The website's own news feed, with its newest posts (a wrong weekday does not move the date).
        $news = $reader->read(['network' => 'website', 'url' => 'https://www.clinic.example'], self::SITE);
        $this->assertSame('posts', $news['kind']);
        $this->assertSame(['ندوة القرنية: يوم الخميس القادم'], array_column($news['items'], 'text'));
        $this->assertSame(now()->subDays(4)->format('Y-m-d'), substr($news['items'][0]['at'], 0, 10));

        // Another website's page: its words.
        $page = $reader->read(['network' => 'page', 'url' => 'https://news.sos-ksa.org/events'], self::SITE);
        $this->assertSame('page', $page['kind']);
        $this->assertStringContainsString('المؤتمر السنوي يوم 12 ديسمبر 2026', $page['text']);
        $this->assertStringNotContainsString('Menu', $page['text']);

        // The website's home page without a feed: the agent has it from the website already.
        $home = $reader->read(['network' => 'website', 'url' => self::SITE], self::SITE);
        $this->assertSame(['ok' => true, 'kind' => 'none'], ['ok' => $home['ok'], 'kind' => $home['kind']]);
    }

    public function test_sites_that_need_signing_in_are_never_asked_and_odd_links_are_refused(): void
    {
        Http::fake();
        $reader = app(LinkReader::class);
        foreach (['https://www.instagram.com/sos_ksa' => 'instagram', 'https://www.facebook.com/sosksa' => 'facebook', 'https://www.tiktok.com/@sos' => 'tiktok', 'https://www.linkedin.com/company/sos' => 'linkedin'] as $url => $network) {
            $this->assertSame(LinkReader::CLOSED, LinkReader::way(['network' => $network, 'url' => $url]));
            $this->assertFalse($reader->read(['network' => $network, 'url' => $url])['ok']);
        }
        $this->assertSame(LinkReader::NONE, LinkReader::way(['network' => 'whatsapp', 'url' => 'https://wa.me/966501234567']));
        Http::assertNothingSent();

        // An X post is kept as its account; a page of another website is only read; nothing else is kept.
        $this->assertSame(['network' => 'x', 'url' => 'https://x.com/SOS_KSA', 'shown' => true], AgentLinks::accept('https://twitter.com/SOS_KSA/status/1850000000000000000?s=20'));
        $this->assertSame(['network' => 'page', 'url' => 'https://news.sos-ksa.org/events?id=7', 'shown' => false], AgentLinks::accept('news.sos-ksa.org/events?id=7&utm_source=x#top'));
        foreach (['https://www.instagram.com/p/Cx1Ab2', 'https://www.youtube.com/watch?v=abc', 'http://localhost/news', 'http://127.0.0.1/a', 'https://10.0.0.5/x',
            'https://news.sos-ksa.org:8080/a', 'https://user:pass@news.sos-ksa.org/', 'javascript:alert(1)', 'ftp://news.sos-ksa.org/a', 'intranet/news'] as $bad) {
            $this->assertNull(AgentLinks::accept($bad, self::SITE), $bad);
        }
    }

    public function test_the_owner_adds_links_the_agent_reads_them_and_knows_the_newest_posts_with_their_dates(): void
    {
        Http::fake([
            'https://syndication.twitter.com/srv/timeline-profile/screen-name/SOS_KSA' => self::html(self::xTimeline()),
            'https://news.sos-ksa.org/events' => self::html('<html><head><title>Events</title></head><body><main><p>المؤتمر السنوي يوم 12 ديسمبر 2026 في جدة، والتسجيل مفتوح للجميع.</p></main></body></html>'),
            'https://clinic.example' => self::html('<html><head><title>SOS</title></head><body>Home</body></html>'),
        ]);
        $owner = User::factory()->create();
        $agent = $this->agent($owner);
        app()->setLocale('ar');

        $res = $this->actingAs($owner)->withoutLocale()->patchJson($this->localizedRoute('voice-agents.update', ['agent' => $agent]), ['links' => [
            'https://x.com/SOS_KSA/status/1850000000000000000', 'https://news.sos-ksa.org/events?utm_source=wa', 'https://www.instagram.com/sos_ksa', self::SITE,
        ]])->assertOk();

        // Read at once (the queue runs here): what each says on the card.
        $rows = collect($res->json('agent.links'))->keyBy('url');
        $this->assertSame(['https://x.com/SOS_KSA', 'https://news.sos-ksa.org/events', 'https://www.instagram.com/sos_ksa', self::SITE], $rows->keys()->all());
        $this->assertSame('posts', $rows['https://x.com/SOS_KSA']['read']['state']);
        $this->assertStringContainsString(trans_choice('agents.links_read.posts', 2, ['count' => 2, 'date' => now()->subDays(2)->locale('ar')->translatedFormat('j F')]), $rows['https://x.com/SOS_KSA']['read']['text']);
        $this->assertSame(['shown' => false, 'state' => 'page'], ['shown' => $rows['https://news.sos-ksa.org/events']['shown'], 'state' => $rows['https://news.sos-ksa.org/events']['read']['state']]);
        $this->assertSame(['state' => 'closed', 'text' => __('agents.links_read.closed')], $rows['https://www.instagram.com/sos_ksa']['read']);
        // The website's home page without news is read with the website: no line under it.
        $this->assertNull($rows[self::SITE]['read']);
        $this->assertSame(3, AgentSource::where('agent_id', $agent->id)->where('kind', AgentSource::LINK)->count());

        // On a call: the newest posts with their dates, the page as the owner's, never as orders.
        $agent->refresh();
        $prompt = AgentInstructions::build($agent);
        $this->assertStringContainsString("# Newest posts from Saudi Ophthalmological Society's accounts and pages", $prompt);
        $this->assertStringContainsString('## X (Twitter): @SOS_KSA (read on '.now()->setTimezone('Asia/Riyadh')->format('j F Y').')', $prompt);
        $this->assertStringContainsString('- '.now()->subDays(2)->setTimezone('Asia/Riyadh')->format('j F Y').': ندوة طب العيون يوم 20 نوفمبر في الرياض', $prompt);
        $this->assertStringContainsString('## a page the owner added (news.sos-ksa.org/events) (read on', $prompt);
        $this->assertStringContainsString('المؤتمر السنوي يوم 12 ديسمبر 2026', $prompt);
        $this->assertStringNotContainsString('t.co', $prompt);
        $this->assertLessThan(strpos($prompt, '# Links you can write in the chat'), strpos($prompt, '# Newest posts from'));

        // The page is read, never written in the chat: the call's tool offers only the accounts and the website.
        $tool = collect(LiveSession::tools($agent))->firstWhere('name', 'show_links');
        $this->assertSame(['x', 'instagram', 'website'], $tool['parameters']['properties']['networks']['items']['enum']);
        $this->assertNotContains('https://news.sos-ksa.org/events', array_column(AgentLinks::of($agent), 'url'));

        // The card asks again while links are read, and lists them for the owner only.
        $this->getJson($this->localizedRoute('voice-agents.links.reads', ['agent' => $agent]))->assertOk()->assertJsonCount(4, 'links');
        $this->actingAs(User::factory()->create())->getJson($this->localizedRoute('voice-agents.links.reads', ['agent' => $agent]))->assertNotFound();

        // Removing a link forgets what was read from it.
        $this->actingAs($owner)->patchJson($this->localizedRoute('voice-agents.update', ['agent' => $agent]), ['links' => ['https://x.com/SOS_KSA']])->assertOk();
        $this->assertSame(['https://x.com/SOS_KSA'], AgentSource::where('agent_id', $agent->id)->where('kind', AgentSource::LINK)->pluck('url')->all());
    }

    public function test_a_link_that_cannot_be_read_now_keeps_what_was_read_before(): void
    {
        $owner = User::factory()->create();
        $agent = $this->agent($owner, ['settings' => ['links' => [['network' => 'x', 'url' => 'https://x.com/SOS_KSA']]]]);
        Http::fakeSequence('https://syndication.twitter.com/*')->push(self::xTimeline(), 200, ['Content-Type' => 'text/html'])->push('Rate limit exceeded', 429, ['Content-Type' => 'text/plain']);

        AgentLinkReads::read($agent, app(LinkReader::class));
        $first = AgentSource::where('agent_id', $agent->id)->where('kind', AgentSource::LINK)->firstOrFail();
        $this->travel(7)->hours();
        AgentLinkReads::read($agent, app(LinkReader::class), true);

        $row = $first->fresh();
        $this->assertSame(AgentSource::READY, $row->status);
        $this->assertSame('unreachable', $row->error);
        $this->assertSame($first->content, $row->content);
        $this->assertStringContainsString('ندوة طب العيون', AgentInstructions::build($agent->fresh()));
        // Tried again, so not tried again before its next turn.
        $this->assertTrue($row->updated_at->gt(now()->subMinute()));
    }

    public function test_links_are_read_again_every_few_hours_and_an_older_agent_gets_its_links_read(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $old = $this->agent($owner, ['status' => Agent::LIVE, 'settings' => ['links' => [['network' => 'telegram', 'url' => 'https://t.me/sos_channel']]]]);
        $fresh = $this->agent($owner, ['status' => Agent::LIVE, 'settings' => ['links' => [['network' => 'x', 'url' => 'https://x.com/SOS_KSA']]]]);
        $stale = $this->agent($owner, ['status' => Agent::LIVE, 'settings' => ['links' => [['network' => 'x', 'url' => 'https://x.com/Other']]]]);
        $closed = $this->agent($owner, ['status' => Agent::LIVE, 'settings' => ['links' => [['network' => 'instagram', 'url' => 'https://www.instagram.com/sos_ksa']]]]);
        foreach ([$fresh, $stale] as $agent) {
            AgentLinkReads::prepare($agent);
            AgentSource::where('agent_id', $agent->id)->update(['status' => AgentSource::READY, 'content' => '{"kind":"posts","items":[]}', 'read_at' => now()]);
        }
        AgentSource::where('agent_id', $stale->id)->update(['updated_at' => now()->subHours(AgentLinkReads::EVERY_HOURS + 1)]);

        $this->artisan('agents:read-links')->assertSuccessful();

        $sent = [];
        Queue::assertPushed(ReadAgentLinks::class, function (ReadAgentLinks $job) use (&$sent) {
            $sent[$job->agentId] = $job->due;

            return true;
        });
        $this->assertSame([$old->id => false, $stale->id => true], $sent);
        $this->assertSame(AgentSource::PENDING, AgentSource::where('agent_id', $old->id)->value('status'));
        $this->assertFalse(AgentSource::where('agent_id', $closed->id)->exists());
    }

    public function test_the_knowledge_card_says_what_the_agent_read_from_each_link(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $agent = $this->agent($owner, ['settings' => ['links' => [['network' => 'x', 'url' => 'https://x.com/SOS_KSA'], ['network' => 'page', 'url' => 'https://news.sos-ksa.org/events']]]]);
        app()->setLocale('ar');

        // An agent made before links were read gets them read when its page opens.
        $html = (string) $this->actingAs($owner)->withoutLocale()->get($this->localizedRoute('voice-agents.show', ['agent' => $agent]))->assertOk()->getContent();
        Queue::assertPushed(ReadAgentLinks::class, 1);
        $this->assertStringContainsString('data-state="reading"', $html);
        $this->assertStringContainsString(e(__('agents.links_read.reading')), $html);
        $this->assertStringContainsString('<i class="ag-link-tag" title="'.e(__('agents.links_read_only_hint')).'">'.e(__('agents.links_read_only')).'</i>', $html);
        // The page knows where to ask again while they are read.
        $this->assertStringContainsString('link_reads', $html);
        // Opening it again sends nothing new.
        $this->get($this->localizedRoute('voice-agents.show', ['agent' => $agent]))->assertOk();
        Queue::assertPushed(ReadAgentLinks::class, 1);
    }
}
