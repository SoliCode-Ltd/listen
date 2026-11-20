<?php

namespace Tests\Feature;

use App\Filament\Pages\MemberSources;
use App\Filament\Resources\UserResource;
use App\Models\AdsPurchase;
use App\Models\MemberSource;
use App\Models\Payment;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\Acquisition\SourceReport;
use App\Services\Acquisition\VisitSources;
use App\Support\Acquisition\Channels;
use App\Support\Acquisition\Touch;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MemberSourcesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // A visitor from Egypt: where the consent rules for ads do not apply.
        SiteSetting::set('pricing_forced_country_code', 'EG', 'billing');
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string, 2: ?string}> */
    public static function arrivals(): array
    {
        return [
            'Google search in Egypt' => [['r' => 'www.google.com.eg'], Channels::SEARCH, 'Google'],
            'Google Ads' => [['r' => 'www.google.com', 'k' => ['gclid', 'gad_source']], Channels::GOOGLE_ADS, 'Google Ads'],
            'Google Ads tapped in an iPhone app' => [['k' => ['gbraid', 'gad_source']], Channels::GOOGLE_ADS, 'Google Ads, on an iPhone'],
            'ChatGPT Ads' => [['r' => 'chatgpt.com', 'k' => ['oppref']], Channels::CHATGPT_ADS, 'ChatGPT Ads'],
            'a link in a ChatGPT answer' => [['r' => 'chatgpt.com', 'q' => ['utm_source' => 'chatgpt.com']], Channels::AI, 'ChatGPT'],
            'Gemini, not Google search' => [['r' => 'gemini.google.com'], Channels::AI, 'Gemini'],
            'Perplexity' => [['r' => 'www.perplexity.ai'], Channels::AI, 'Perplexity'],
            'Instagram, not tagged as paid' => [['r' => 'l.instagram.com', 'k' => ['fbclid']], Channels::SOCIAL, 'Instagram'],
            'a Facebook ad tagged as paid' => [['r' => 'lm.facebook.com', 'k' => ['fbclid'], 'q' => ['utm_medium' => 'paid', 'utm_campaign' => 'Ramadan']], Channels::META_ADS, 'Facebook'],
            'TikTok ads' => [['k' => ['ttclid']], Channels::TIKTOK_ADS, 'TikTok ads'],
            'YouTube' => [['r' => 'm.youtube.com'], Channels::SOCIAL, 'YouTube'],
            'X' => [['r' => 't.co'], Channels::SOCIAL, 'X'],
            'Gmail on Android' => [['r' => 'android-app://com.google.android.gm'], Channels::EMAIL, 'Gmail'],
            'Google app on Android' => [['r' => 'android-app://com.google.android.googlequicksearchbox'], Channels::SEARCH, 'Google'],
            'a newsletter link' => [['q' => ['utm_source' => 'newsletter', 'utm_medium' => 'email']], Channels::EMAIL, 'newsletter'],
            'an Instagram bio link tagged by the owner' => [['r' => 'l.instagram.com', 'q' => ['utm_source' => 'bio']], Channels::SOCIAL, 'Instagram'],
            'a tagged link from nowhere known' => [['q' => ['utm_source' => 'partner-x']], Channels::CAMPAIGN, 'partner-x'],
            'an invite link' => [['k' => ['ref']], Channels::INVITE, 'Invite link'],
            'Google Play' => [['r' => 'play.google.com'], Channels::APP_STORE, 'Google Play'],
            'another website' => [['r' => 'blog.example.org'], Channels::WEBSITE, 'blog.example.org'],
            'typed in' => [[], Channels::DIRECT, 'Direct'],
            'back from signing in with Google' => [['r' => 'accounts.google.com'], Channels::DIRECT, 'Direct'],
            'moving inside the site' => [['r' => 'listen.test'], Channels::DIRECT, 'Direct'],
        ];
    }

    #[DataProvider('arrivals')]
    public function test_each_arrival_is_sorted_into_its_group(array $input, string $channel, ?string $detail): void
    {
        $sorted = Channels::classify(Touch::fromPage($input + ['p' => '/ar'], ['listen.test']));

        $this->assertSame($channel, $sorted['channel']);
        $this->assertSame($detail, $sorted['detail']);
    }

    public function test_a_campaign_is_named_by_the_link_or_by_the_google_ads_campaign(): void
    {
        $this->assertSame('Ramadan', Channels::classify(Touch::fromPage(['q' => ['utm_source' => 'facebook', 'utm_medium' => 'cpc', 'utm_campaign' => 'Ramadan']], []))['campaign']);
        $this->assertSame('Google Ads campaign 2134567', Channels::classify(Touch::fromPage(['k' => ['gclid'], 'q' => ['gad_campaignid' => '2134567']], []))['campaign']);
    }

    public function test_only_clean_short_values_are_kept(): void
    {
        $touch = Touch::fromPage([
            'r' => 'javascript:alert(1)',
            'p' => '/ar/arabic-dialects/gulf?gclid=abc#top',
            'q' => ['utm_source' => '=HYPERLINK("x")', 'utm_campaign' => str_repeat('a', 300), 'utm_evil' => 'x', 'gad_campaignid' => '12ab'],
            'k' => ['gclid', 'not-a-marker', 'FBCLID'],
        ], []);

        $this->assertSame('', $touch->host);
        $this->assertSame('/ar/arabic-dialects/gulf', $touch->path);
        $this->assertSame(['utm_source', 'utm_campaign'], array_keys($touch->campaign));
        $this->assertStringStartsNotWith('=', $touch->campaign['utm_source']);
        $this->assertSame(100, mb_strlen($touch->campaign['utm_campaign']));
        $this->assertSame(['gclid', 'fbclid'], $touch->clicks);

        $this->assertSame('/', Touch::fromPage(['p' => '/ar/<script>'], [])->path);
        $this->assertSame('google.com', Touch::fromPage(['r' => 'https://www.google.com/search?q=secret'], [])->host);
    }

    public function test_an_arrival_leaves_the_source_cookie_and_no_session(): void
    {
        $response = $this->landed(['r' => 'www.google.com.eg', 'p' => '/ar/arabic-dialects/gulf']);

        $response->assertNoContent();
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertNull($response->getCookie(config('session.cookie'), false), 'A reader of the public pages is given no session.');
        $this->assertNull($response->getCookie('XSRF-TOKEN', false));

        $kept = json_decode((string) $response->getCookie(VisitSources::COOKIE)->getValue(), true);
        $this->assertSame('google.com.eg', $kept['f']['h']);
        $this->assertSame('/ar/arabic-dialects/gulf', $kept['f']['p']);
        $this->assertSame($kept['f'], $kept['l']);
        $this->assertTrue($response->getCookie(VisitSources::COOKIE, false)->isHttpOnly());
    }

    public function test_the_first_visit_stays_and_the_latest_source_replaces_the_last(): void
    {
        $first = json_encode(['f' => ['h' => 'google.com', 'p' => '/ar', 'u' => [], 'k' => [], 't' => 100]]);

        // Typed in again: nothing changes, so no cookie is sent back.
        $this->withCookie(VisitSources::COOKIE, $first)->landed(['p' => '/ar/pricing'])
            ->assertNoContent()
            ->assertCookieMissing(VisitSources::COOKIE);

        // An ad's click later on: the first visit stays, the click is the latest.
        $response = $this->withCookie(VisitSources::COOKIE, $first)->landed(['p' => '/en', 'k' => ['gclid'], 'q' => ['utm_campaign' => 'Launch']]);
        $kept = json_decode((string) $response->getCookie(VisitSources::COOKIE)->getValue(), true);
        $this->assertSame('google.com', $kept['f']['h']);
        $this->assertSame(['gclid'], $kept['l']['k']);
        $this->assertSame('Launch', $kept['l']['u']['utm_campaign']);
    }

    public function test_nothing_is_kept_for_a_visitor_where_the_consent_rules_apply(): void
    {
        SiteSetting::set('pricing_forced_country_code', 'DE', 'billing');

        $this->landed(['r' => 'google.de'])->assertNoContent()->assertCookieMissing(VisitSources::COOKIE);

        // One kept before (from a trip abroad) is taken away.
        $response = $this->withCookie(VisitSources::COOKIE, json_encode(['f' => ['h' => 'google.com', 'p' => '/ar', 'u' => [], 'k' => [], 't' => 1]]))
            ->landed(['r' => 'google.de']);
        $this->assertTrue($response->getCookie(VisitSources::COOKIE, false)->isCleared());
    }

    public function test_signing_up_keeps_how_the_member_found_the_site(): void
    {
        $visits = json_encode([
            'f' => ['h' => 'chatgpt.com', 'p' => '/ar', 'u' => ['utm_source' => 'chatgpt.com'], 'k' => [], 't' => now()->subDays(3)->timestamp],
            'l' => ['h' => 'google.com', 'p' => '/en/pricing', 'u' => ['gad_campaignid' => '555'], 'k' => ['gclid'], 't' => now()->subHour()->timestamp],
        ]);

        $response = $this->withCookie(VisitSources::COOKIE, $visits)->signUp('found-us@example.test');

        $user = User::query()->where('email', 'found-us@example.test')->firstOrFail();
        $source = $user->memberSource;
        $this->assertNotNull($source);
        $this->assertSame(MemberSource::SIGNED_UP_WEB, $source->signed_up_in);
        $this->assertSame(Channels::GOOGLE_ADS, $source->channel);
        $this->assertSame('Google Ads campaign 555', $source->campaign);
        $this->assertSame('/en/pricing', $source->landing_path);
        $this->assertSame('google.com', $source->referrer_host);
        $this->assertSame(Channels::AI, $source->first_channel);
        $this->assertSame('ChatGPT', $source->first_detail);
        $this->assertSame('/ar', $source->first_landing_path);
        $this->assertNotNull($source->first_came_at);
        // The cookie has done its work.
        $this->assertTrue($response->getCookie(VisitSources::COOKIE, false)->isCleared());
    }

    public function test_a_sign_up_with_nothing_kept_is_still_recorded(): void
    {
        $this->signUp('no-cookie@example.test');
        $source = User::query()->where('email', 'no-cookie@example.test')->firstOrFail()->memberSource;
        $this->assertSame(Channels::UNKNOWN, $source->channel);
        $this->assertSame(MemberSource::SIGNED_UP_WEB, $source->signed_up_in);
    }

    public function test_nothing_is_kept_at_sign_up_where_the_consent_rules_apply(): void
    {
        SiteSetting::set('pricing_forced_country_code', 'FR', 'billing');
        $this->withCookie(VisitSources::COOKIE, json_encode(['f' => ['h' => 'google.fr', 'p' => '/en', 'u' => [], 'k' => [], 't' => 1]]))
            ->signUp('from-france@example.test');
        $source = User::query()->where('email', 'from-france@example.test')->firstOrFail()->memberSource;
        $this->assertSame(Channels::UNKNOWN, $source->channel, 'Nothing is kept for a visitor from where the consent rules apply.');
        $this->assertNull($source->visits);
    }

    public function test_an_account_made_in_the_app_says_so(): void
    {
        $this->withHeader('User-Agent', 'Mozilla/5.0 (Linux; Android 14) ListenApp/1.4.0 (Android)')->signUp('in-app@example.test');

        $source = User::query()->where('email', 'in-app@example.test')->firstOrFail()->memberSource;
        $this->assertSame(MemberSource::SIGNED_UP_ANDROID, $source->signed_up_in);
        $this->assertSame(Channels::APP, $source->channel);
        $this->assertSame('Android app', $source->detail);
    }

    public function test_accounts_not_made_by_the_visitor_get_no_record(): void
    {
        // Made by code (seeding, a command): no visitor's request.
        $made = User::factory()->create();
        $this->assertNull($made->memberSource);

        // Made by someone signed in (the admin's form): their cookie is not the member's.
        $admin = User::factory()->create(['is_admin' => true]);
        $request = Request::create('/admin/users/create', 'POST');
        $request->setRouteResolver(fn () => Route::getRoutes()->match(Request::create('/landed', 'POST')));
        $request->cookies->set(VisitSources::COOKIE, json_encode(['f' => ['h' => 'google.com', 'p' => '/', 'u' => [], 'k' => [], 't' => 1]]));
        Auth::login($admin);
        $member = User::factory()->create();
        app(VisitSources::class)->recordSignUp($member, $request);
        $this->assertFalse(MemberSource::query()->where('user_id', $member->id)->exists());
    }

    public function test_the_report_counts_members_payments_and_money_by_source(): void
    {
        $from = now()->subDays(10);
        $search = $this->member(Channels::SEARCH, 'Google', ['landing_path' => '/ar/arabic-dialects/gulf', 'referrer_host' => 'google.com', 'country_code' => 'SA'], now()->subDays(2));
        $searchUnpaid = $this->member(Channels::SEARCH, 'Bing', ['country_code' => 'EG'], now()->subDays(3));
        $ad = $this->member(Channels::GOOGLE_ADS, 'Google Ads', ['campaign' => 'Launch', 'first_channel' => Channels::AI, 'first_detail' => 'ChatGPT', 'country_code' => 'EG'], now()->subDays(4));
        $older = User::factory()->create(['created_at' => now()->subDays(5), 'ads_click_id' => 'gclid-1']);
        $olderNothing = User::factory()->create(['created_at' => now()->subDays(6)]);
        $beforePeriod = $this->member(Channels::SOCIAL, 'Facebook', [], now()->subDays(40));
        User::factory()->create(['created_at' => now()->subDay(), 'is_admin' => true]);

        $this->pay($search, 900, 'USD', $search->created_at->copy()->addHours(2));
        $this->pay($search, 900, 'USD', now()->subHour(), 900); // refunded in full
        $this->pay($ad, 24700, 'EGP', $ad->created_at->copy()->addDays(3));
        $this->pay($older, 200, 'USD', now()->subDays(3));
        $this->pay($beforePeriod, 900, 'USD', now()->subDays(2)); // a renewal in the period by an older member

        $report = app(SourceReport::class)->build($from, now()->addSecond());

        $this->assertSame(5, $report['totals']['members'], 'Admins are left out; the member from before the period too.');
        $this->assertSame(3, $report['totals']['paid']);
        $this->assertSame(1, $report['totals']['within_day']);
        $this->assertEquals(['EGP' => 24700, 'USD' => 1100], $report['totals']['money']);

        $channels = collect($report['channels'])->keyBy('key');
        $this->assertSame(2, $channels[Channels::SEARCH]['members']);
        $this->assertSame(1, $channels[Channels::SEARCH]['paid']);
        $this->assertSame(['Google', 'Bing'], array_column($channels[Channels::SEARCH]['details'], 'label'));
        $this->assertSame(2, $channels[Channels::GOOGLE_ADS]['members'], 'The older member\'s Google click seen at checkout counts too.');
        $this->assertSame(['Google Ads', 'Google Ads (seen at checkout)'], array_column($channels[Channels::GOOGLE_ADS]['details'], 'label'));
        $this->assertSame(1, $channels[Channels::UNKNOWN]['members']);
        $this->assertSame('Joined before this was recorded', $channels[Channels::UNKNOWN]['details'][0]['label']);

        $this->assertSame('Launch', $report['campaigns'][0]['label']);
        $this->assertSame('/ar/arabic-dialects/gulf', $report['landings'][0]['label']);
        $this->assertSame('google.com', $report['sites'][0]['label']);
        $this->assertSame(2, collect($report['countries'])->firstWhere('label', 'EG')['members']);
        $this->assertSame(1, collect($report['countries'])->firstWhere('label', 'SA')['paid']);

        // The period's payments, renewals included, by the payer's source.
        $payments = collect($report['payments']['rows'])->keyBy('key');
        $this->assertSame(5, $report['payments']['payments']);
        $this->assertSame(1, $payments[Channels::SOCIAL]['payments']);
        $this->assertSame(0, $payments[Channels::SOCIAL]['from_new']);
        $this->assertSame(['USD' => 900], $payments[Channels::SOCIAL]['money']);

        $this->assertSame($search->id, $report['latest'][0]['id'], 'The newest member comes first.');
        $this->assertSame($searchUnpaid->id, $report['latest'][1]['id']);

        // Counted by their first visit, the ad's member came from an AI assistant.
        $byFirst = collect(app(SourceReport::class)->build($from, now()->addSecond(), SourceReport::COUNT_FIRST)['channels'])->keyBy('key');
        $this->assertSame(1, $byFirst[Channels::AI]['members']);

        // Only the website's sign-ups: the older members (no record) are left out.
        $this->assertSame(3, app(SourceReport::class)->build($from, now()->addSecond(), SourceReport::COUNT_LAST, 'web')['totals']['members']);
    }

    public function test_the_admin_tab_and_the_member_page_show_the_sources(): void
    {
        $member = $this->member(Channels::AI, 'ChatGPT', ['landing_path' => '/ar/voices', 'campaign' => 'answers'], now()->subDay());
        $this->pay($member, 900, 'USD', now()->subHours(20));
        AdsPurchase::query()->create(['user_id' => User::factory()->create(['created_at' => now()->subDays(2)])->id, 'source' => AdsPurchase::SOURCE_CARD, 'reference' => 'in_1', 'gclid' => 'g', 'value_minor' => 200, 'currency' => 'USD', 'purchased_at' => now()]);

        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(MemberSources::class)
            ->assertOk()
            ->assertSee('Where they came from')
            ->assertSee('AI assistants')
            ->assertSee('Google Ads')
            ->assertSee('/ar/voices')
            ->assertSee('USD 9.00')
            ->assertSee($member->name)
            ->set('data.period', 'custom')
            ->set('data.from', now()->subDays(40)->toDateString())
            ->set('data.until', now()->toDateString())
            ->assertOk()
            ->set('data.count', SourceReport::COUNT_FIRST)
            ->assertOk();

        $this->get(MemberSources::getUrl())->assertOk()->assertSee('Where members come from');
        $this->get(UserResource::getUrl('view', ['record' => $member]))->assertOk()->assertSee('How they found us')->assertSee('AI assistants · ChatGPT');
    }

    public function test_the_page_tells_the_server_only_for_visitors_not_signed_in_and_never_in_the_app(): void
    {
        $this->publicPage()->assertOk()->assertSee("fetch('/landed'", false);
        $this->withHeader('User-Agent', 'Mozilla/5.0 ListenApp/1.4.0 (Android)')->publicPage()->assertDontSee("fetch('/landed'", false);
        $this->actingAs(User::factory()->create())->publicPage()->assertDontSee("fetch('/landed'", false);
    }

    public function test_the_privacy_and_cookie_policies_say_what_is_kept(): void
    {
        $this->getLocalizedRoute('legal.privacy')->assertOk()->assertSee(__('ui.legal.privacy.s1_16'));
        $this->getLocalizedRoute('legal.cookies')->assertOk()->assertSee(__('ui.legal.cookies.c6_body'));
        foreach (['en', 'ar'] as $locale) {
            $this->assertNotSame('ui.legal.privacy.s1_16', trans('ui.legal.privacy.s1_16', [], $locale));
            $this->assertNotSame('ui.legal.cookies.c6_body', trans('ui.legal.cookies.c6_body', [], $locale));
        }
    }

    private function landed(array $body): TestResponse
    {
        // As a browser's fetch() sends it: with the site's cookies.
        return $this->withCredentials()->postJson('/landed', $body + ['p' => '/ar']);
    }

    private function signUp(string $email): TestResponse
    {
        return $this->postLocalizedRoute('register.submit', [
            'name' => 'New Member',
            'email' => $email,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);
    }

    private function publicPage(): TestResponse
    {
        return $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class, LaravelLocalizationViewPath::class])
            ->get($this->localizedRoute('legal.privacy'));
    }

    /** A member who signed up at $joined, with the source kept then. */
    private function member(string $channel, string $detail, array $extra, Carbon $joined): User
    {
        $user = User::factory()->create(['created_at' => $joined, 'country_code' => $extra['country_code'] ?? null]);
        unset($extra['country_code']);
        MemberSource::query()->create(['user_id' => $user->id, 'signed_up_in' => MemberSource::SIGNED_UP_WEB, 'channel' => $channel, 'detail' => $detail] + $extra);

        return $user;
    }

    private function pay(User $user, int $amount, string $currency, Carbon $at, int $refunded = 0): void
    {
        Payment::query()->create([
            'user_id' => $user->id, 'reference' => 'test:'.uniqid('', true), 'method' => Payment::METHOD_CARD,
            'amount_minor' => $amount, 'refunded_minor' => $refunded, 'currency' => $currency, 'paid_at' => $at,
        ]);
    }
}
