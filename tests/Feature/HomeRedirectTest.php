<?php

namespace Tests\Feature;

use App\Support\HomeRedirect;
use App\Support\InApp\InApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The plain address answers a visitor the site does not know yet before the app starts
 * (public/index.php), with the same language and address the app gives them.
 */
class HomeRedirectTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{url: string, session_cookie: string, language: string} */
    private function settings(): array
    {
        return ['url' => (string) config('app.url'), 'session_cookie' => (string) config('session.cookie'), 'language' => (string) config('app.locale')];
    }

    private function server(string $uri = '/', array $extra = []): array
    {
        $query = (string) parse_url($uri, PHP_URL_QUERY);

        return $extra + [
            'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $uri, 'QUERY_STRING' => $query,
            'HTTP_HOST' => (string) parse_url((string) config('app.url'), PHP_URL_HOST), 'HTTP_USER_AGENT' => 'Mozilla/5.0',
        ];
    }

    public function test_it_sends_a_new_visitor_where_the_app_would_with_the_same_query(): void
    {
        // Without a query, against the app itself.
        $app = $this->get('/');
        $app->assertRedirect();
        $this->assertSame($app->headers->get('Location'), HomeRedirect::location($this->server('/'), [], $this->settings()));

        // With one: as the live site's own redirect wrote these on 19 November 2026 (the test app has
        // no language prefix in its routes, so it cannot show this): sorted, and encoded the same way.
        $settings = ['url' => 'https://listenai.studio', 'language' => 'ar'] + $this->settings();
        foreach ([
            '/?b=2&a=1' => 'https://listenai.studio/ar?a=1&b=2',
            '/?utm_source=chatgpt&utm_medium=paid&oppref=abc123' => 'https://listenai.studio/ar?oppref=abc123&utm_medium=paid&utm_source=chatgpt',
            '/?q=a%20b&x[]=1' => 'https://listenai.studio/ar?q=a%20b&x%5B0%5D=1',
            '/?q=a+b&z=%D8%B9' => 'https://listenai.studio/ar?q=a%20b&z=%D8%B9',
        ] as $uri => $live) {
            $this->assertSame($live, HomeRedirect::location($this->server($uri, ['HTTP_HOST' => 'listenai.studio']), [], $settings), $uri);
        }
    }

    public function test_the_language_is_the_last_one_read_then_the_sites_main_one(): void
    {
        $settings = ['language' => 'ar'] + $this->settings();
        $root = rtrim($settings['url'], '/');

        $this->assertSame($root.'/ar', HomeRedirect::location($this->server(), [], $settings));
        $this->assertSame($root.'/en', HomeRedirect::location($this->server(), [HomeRedirect::LOCALE_COOKIE => 'en'], $settings));
        $this->assertSame($root.'/ar', HomeRedirect::location($this->server(), [HomeRedirect::LOCALE_COOKIE => 'fr'], $settings));
        $this->assertSame($root.'/en', HomeRedirect::location($this->server(), [], ['language' => 'en'] + $settings));
        // Analytics or the ad networks' cookies change nothing.
        $this->assertSame($root.'/ar', HomeRedirect::location($this->server(), ['_ga' => 'GA1', '__oppref' => 'x', '__cf_bm' => 'y'], $settings));
    }

    public function test_everyone_else_is_left_to_the_app(): void
    {
        $settings = $this->settings();

        foreach ([
            'a session' => [$this->server(), [$settings['session_cookie'] => 'abc']],
            'remember me' => [$this->server(), ['remember_web_59ba36addc2b2f9401580f014c7f58ea4e30989d' => 'x']],
            'the app cookie' => [$this->server(), [InApp::COOKIE => InApp::COOKIE_ANDROID]],
            'the app language' => [$this->server(), [InApp::LANGUAGE_COOKIE => 'ar']],
            'the app name' => [$this->server('/', ['HTTP_USER_AGENT' => 'Mozilla/5.0 ListenApp/1.4 (Android)']), []],
            'www' => [$this->server('/', ['HTTP_HOST' => 'www.'.parse_url($settings['url'], PHP_URL_HOST)]), []],
            'another host' => [$this->server('/', ['HTTP_HOST' => 'example.com']), []],
            'another page' => [$this->server('/pricing'), []],
            'a post' => [$this->server('/', ['REQUEST_METHOD' => 'POST']), []],
        ] as $what => [$server, $cookies]) {
            $this->assertNull(HomeRedirect::location($server, $cookies, $settings), $what);
        }

        // No main language the check knows: the app decides.
        $this->assertNull(HomeRedirect::location($this->server(), [], ['language' => ''] + $settings));
    }

    public function test_the_answer_is_never_kept_and_has_the_usual_security_headers(): void
    {
        $headers = HomeRedirect::headers('https://listenai.studio/ar', ['HTTP_X_FORWARDED_PROTO' => 'https']);

        $this->assertSame('https://listenai.studio/ar', $headers['Location']);
        $this->assertSame('no-cache, private', $headers['Cache-Control']);
        $this->assertSame('SAMEORIGIN', $headers['X-Frame-Options']);
        $this->assertSame('nosniff', $headers['X-Content-Type-Options']);
        $this->assertSame('max-age=31536000', $headers['Strict-Transport-Security']);
        $this->assertArrayNotHasKey('Set-Cookie', $headers);
    }

    public function test_the_settings_file_is_written_only_when_it_changes_and_read_back(): void
    {
        $base = sys_get_temp_dir().'/home-redirect-'.uniqid();
        mkdir($base.'/storage/framework', 0777, true);

        $this->assertNull(HomeRedirect::settings($base), 'no file yet: the app decides');

        $settings = ['url' => 'https://listenai.studio', 'session_cookie' => 'listen-session', 'language' => 'ar'];
        HomeRedirect::rememberSettings($base, $settings);
        $this->assertSame($settings, HomeRedirect::settings($base));
        $written = filemtime(HomeRedirect::settingsFile($base));

        clearstatcache();
        HomeRedirect::rememberSettings($base, $settings);
        $this->assertSame($written, filemtime(HomeRedirect::settingsFile($base)));

        HomeRedirect::rememberSettings($base, ['language' => 'en'] + $settings);
        $this->assertSame('en', HomeRedirect::settings($base)['language']);

        file_put_contents(HomeRedirect::settingsFile($base), '{"url":5}');
        $this->assertNull(HomeRedirect::settings($base));
    }

    public function test_the_front_controller_asks_it_before_the_app_starts(): void
    {
        $index = (string) file_get_contents(public_path('index.php'));
        $check = strpos($index, 'HomeRedirect::handle(');
        $this->assertNotFalse($check);
        $this->assertGreaterThan(strpos($index, 'vendor/autoload.php'), $check);
        $this->assertLessThan(strpos($index, 'bootstrap/app.php'), $check);
        $this->assertGreaterThan(strpos($index, 'maintenance.php'), $check, 'after the maintenance page');
    }
}
