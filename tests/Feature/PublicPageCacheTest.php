<?php

namespace Tests\Feature;

use App\Http\Middleware\CaptureAdClickId;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\User;
use App\Support\HomeRedirect;
use App\Support\InApp\InApp;
use App\Support\PublicPageCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Tests\TestCase;

/**
 * The public marketing pages are kept by Cloudflare for visitors nobody knows yet, and only
 * for them: never a page with someone's details, a form token, prices in a visitor's own
 * currency, the app's page or an AI agent's Markdown.
 */
class PublicPageCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Plan::query()->create([
            'slug' => 'free', 'name' => 'FREE', 'price_cents' => 0, 'monthly_minutes' => 300, 'monthly_chars' => 30000,
            'monthly_requests' => 60, 'max_chars_per_request' => 600, 'is_active' => true,
        ]);
        Plan::query()->create([
            'slug' => 'creator', 'name' => 'Creator', 'price_cents' => 1900, 'monthly_minutes' => 5000, 'monthly_chars' => 500000,
            'monthly_requests' => 0, 'max_chars_per_request' => 5000, 'is_active' => true,
        ]);
    }

    private function page(string $route, array $parameters = [], array $headers = [], array $cookies = [], string $query = ''): TestResponse
    {
        $test = $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class, LaravelLocalizationViewPath::class]);
        foreach ($cookies as $name => $value) {
            $test = $test->withUnencryptedCookie($name, $value);
        }

        return $test->withHeaders($headers)->get($this->localizedRoute($route, $parameters).($query !== '' ? '?'.$query : ''));
    }

    private function assertKept(TestResponse $response, string $what): void
    {
        $response->assertOk();
        // Kept by Cloudflare alone, which reads its own header; private for every other cache
        // (the server's Varnish cannot tell the app from a browser) and for the browser.
        $this->assertSame('max-age='.PublicPageCache::SECONDS.', stale-while-revalidate=60', $response->headers->get(PublicPageCache::CLOUDFLARE_HEADER), $what);
        $cache = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cache, $what);
        $this->assertStringNotContainsString('public', $cache, $what);
        $this->assertSame([], $response->headers->getCookies(), $what.' sets no cookie');
        $this->assertSame('public', $response->headers->get(PublicPageCache::HEADER), $what);
    }

    private function assertNotKept(TestResponse $response, string $what): void
    {
        $this->assertNull($response->headers->get(PublicPageCache::CLOUDFLARE_HEADER), $what);
        $cache = (string) $response->headers->get('Cache-Control');
        $this->assertStringNotContainsString('public', $cache, $what);
        $this->assertStringNotContainsString('s-maxage', $cache, $what);
        $this->assertNotSame('public', $response->headers->get(PublicPageCache::HEADER), $what);
    }

    private function cookieNames(TestResponse $response): array
    {
        return array_map(fn ($cookie) => $cookie->getName(), $response->headers->getCookies());
    }

    public function test_the_public_pages_are_kept_for_a_visitor_nobody_knows_yet(): void
    {
        foreach ([
            'home' => [], 'samples' => [], 'legal.privacy' => [], 'legal.terms' => [], 'legal.cookies' => [],
            'developers' => [], 'seo.text-to-speech-arabic' => [], 'seo.arabic-dialects' => [], 'seo.english-accents' => [],
        ] as $route => $parameters) {
            $response = $this->page($route, $parameters);
            $this->assertKept($response, $route);
            // Signed-out header: a sign-in link, nobody's name or menu.
            $response->assertSee(route('login'), false);
            $response->assertDontSee(route('logout'), false);
            $response->assertDontSee('name="_token"', false);
            $response->assertDontSee('csrf-token', false);
        }
    }

    public function test_pages_with_prices_in_the_visitors_currency_or_a_form_are_not_kept(): void
    {
        // Pricing: in the visitor's own currency, from their country.
        $pricing = $this->page('pricing');
        $pricing->assertOk();
        $this->assertNotKept($pricing, 'pricing');
        $this->assertContains((string) config('session.cookie'), $this->cookieNames($pricing));

        // Contact: its form needs the visitor's form token.
        $contact = $this->page('contact');
        $contact->assertOk();
        $this->assertNotKept($contact, 'contact');
        $this->assertContains('XSRF-TOKEN', $this->cookieNames($contact));
        $contact->assertSee('name="_token"', false);

        $this->assertNotKept($this->page('login'), 'sign in');
        $this->assertNotKept($this->page('register'), 'sign up');
    }

    public function test_a_signed_in_member_always_gets_a_fresh_page_of_their_own(): void
    {
        $member = User::factory()->create(['name' => 'Mona Member', 'subscription_status_checked_at' => now()]);

        $response = $this->actingAs($member)->page('home');
        $response->assertOk();
        $this->assertNotKept($response, 'signed in');
        $response->assertSee(route('logout'), false);
    }

    public function test_a_visitor_with_a_session_remember_me_or_the_app_is_not_given_a_kept_page(): void
    {
        foreach ([
            'session' => [[], [(string) config('session.cookie') => 'abc']],
            'remember me' => [[], ['remember_web_59ba36addc2b2f9401580f014c7f58ea4e30989d' => 'x']],
            'app cookie' => [[], [InApp::COOKIE => InApp::COOKIE_ANDROID]],
            'app name' => [['User-Agent' => 'Mozilla/5.0 (Linux; Android 14) ListenApp/1.4 (Android)'], []],
        ] as $what => [$headers, $cookies]) {
            $response = $this->page('home', [], $headers, $cookies);
            // The app may send its visitor on to its own home page; nothing is kept either way.
            if (! str_starts_with($what, 'app')) {
                $response->assertOk();
            }
            $this->assertNotKept($response, $what);
        }
    }

    public function test_an_ad_click_or_any_query_reaches_the_server_and_keeps_its_cookie(): void
    {
        SiteSetting::set('pricing_forced_country_code', 'EG', 'billing');

        $response = $this->page('home', [], [], [], 'gclid=Cj0-cache_test&utm_source=google');
        $response->assertOk();
        $this->assertNotKept($response, 'ad click');
        // The click is kept for the sale to come; no session is started for it.
        $this->assertSame([CaptureAdClickId::COOKIE], $this->cookieNames($response));
        $this->assertSame('anonymous', $response->headers->get(PublicPageCache::HEADER));
    }

    public function test_an_ai_agents_markdown_is_never_kept(): void
    {
        $response = $this->page('home', [], ['Accept' => 'text/markdown, text/html;q=0.5']);
        $response->assertOk();
        $this->assertStringStartsWith('text/markdown', (string) $response->headers->get('Content-Type'));
        $this->assertNotKept($response, 'markdown');
    }

    public function test_a_missing_page_is_not_kept(): void
    {
        $response = $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class])->get('/legal-nothing-here');
        $response->assertNotFound();
        $this->assertNotKept($response, '404');
    }

    public function test_the_page_remembers_its_language_in_a_cookie(): void
    {
        $this->page('home')->assertSee("document.cookie='listen_locale='+ll", false);
    }

    public function test_the_plain_address_sends_a_visitor_back_to_the_language_they_last_read(): void
    {
        // A visitor whose session has no language yet: the cookie's is used.
        $this->withUnencryptedCookie(HomeRedirect::LOCALE_COOKIE, 'ar')->get('/')
            ->assertRedirect(url('/ar'));
        $this->flushSession();
        $this->withUnencryptedCookie(HomeRedirect::LOCALE_COOKIE, 'en')->get('/')
            ->assertRedirect(url('/en'));
        // Anything else in it is ignored: the site's main language.
        $this->flushSession();
        $this->withUnencryptedCookie(HomeRedirect::LOCALE_COOKIE, 'fr')->get('/')
            ->assertRedirect(url('/'.config('app.locale')));
    }

    public function test_the_language_last_read_wins_over_one_a_background_fetch_left_in_the_session(): void
    {
        // Reading an English page fetched the Arabic one ahead, which left Arabic in the session.
        $this->withSession(['locale' => 'ar'])->withUnencryptedCookie(HomeRedirect::LOCALE_COOKIE, 'en')->get('/')
            ->assertRedirect(url('/en'));
    }

    public function test_without_the_cookie_the_session_decides_as_before(): void
    {
        // A browser that runs no script.
        $this->withSession(['locale' => 'ar'])->get('/')->assertRedirect(url('/ar'));
    }

    public function test_the_app_keeps_its_own_language_rules(): void
    {
        // The phone's language says nothing here, so the app's own rule picks English; the page cookie is not asked.
        $this->withUnencryptedCookie(HomeRedirect::LOCALE_COOKIE, 'ar')
            ->withHeaders(['User-Agent' => 'Mozilla/5.0 (Linux; Android 14) ListenApp/1.4 (Android)'])
            ->get('/');

        $this->assertSame(InApp::language(request()), session('locale'));
        $this->assertNotSame('ar', session('locale'));
    }
}
