<?php

namespace Tests\Feature\InApp;

use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Tests of the site as the Listen Android app shows it (App\Support\InApp\InApp):
 * requests carry the app's user agent, and every section a member can reach is open.
 */
abstract class InAppTestCase extends TestCase
{
    use RefreshDatabase;

    /** A phone's web view with the token the app adds (mobile/capacitor.config.ts). */
    public const APP_UA = 'Mozilla/5.0 (Linux; Android 14; SM-A546E Build/UP1A; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/130.0.0.0 Mobile Safari/537.36 ListenApp/1.0.0 (Android)';

    /** The same phone's browser. */
    public const BROWSER_UA = 'Mozilla/5.0 (Linux; Android 14; SM-A546E) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.stripe.key' => '', 'services.stripe.secret' => '']);
        // Routes have no locale prefix in tests (TestCase::localizedRoute).
        $this->withoutMiddleware([
            \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath::class,
        ]);
    }

    protected function seedPlansWithEverythingOpen(): void
    {
        $this->seed(PlanSeeder::class);
        foreach (['voice_design_audience', 'video_dubbing_audience', 'voice_cloning_audience', 'agents_audience'] as $setting) {
            SiteSetting::set($setting, 'everyone');
        }
        // The top plan has every section, so each one is looked at.
        Plan::query()->where('slug', 'studio_max')->firstOrFail()->forceFill([
            'voice_cloning_enabled' => true,
            'voice_cloning_max_voices' => 5,
            'video_dubbing_enabled' => true,
            'voice_design_enabled' => true,
            'voice_design_max_voices' => 10,
            'agents_enabled' => true,
        ])->save();
    }

    protected function memberOn(string $planSlug): User
    {
        $user = User::factory()->create();
        Subscription::query()->where('user_id', $user->id)->delete();
        Subscription::create([
            'user_id' => $user->id,
            'plan_id' => Plan::query()->where('slug', $planSlug)->value('id'),
            'status' => 'active',
            'current_period_start' => now()->subDay(),
            'current_period_end' => now()->addMonth(),
        ]);

        return $user;
    }

    /** A request as the app's web view makes it, as this member (or signed out), in this language. */
    protected function inApp(?User $user = null, string $locale = 'en'): static
    {
        return $this->as($user, $locale, self::APP_UA);
    }

    /** The same from a browser. */
    protected function inBrowser(?User $user = null, string $locale = 'en'): static
    {
        return $this->as($user, $locale, self::BROWSER_UA);
    }

    private function as(?User $user, string $locale, string $userAgent): static
    {
        app('auth')->forgetGuards();
        app()->setLocale($locale);
        $this->flushHeaders()->withHeader('User-Agent', $userAgent);

        return $user ? $this->actingAs($user) : $this;
    }

    protected function page(string $route, array $parameters = []): TestResponse
    {
        return $this->get($this->localizedRoute($route, $parameters));
    }

    /** The page as a member reads it: no scripts, styles or tags. */
    protected function visibleText(string $html): string
    {
        $html = (string) preg_replace('#<(script|style|template)\b.*?</\1>#si', ' ', $html);
        $html = (string) preg_replace('#<[^>]+\bhidden\b[^>]*>[^<]*</[a-z]+>#i', ' ', $html);

        return html_entity_decode(trim((string) preg_replace('/\s+/u', ' ', strip_tags($html))), ENT_QUOTES);
    }
}
