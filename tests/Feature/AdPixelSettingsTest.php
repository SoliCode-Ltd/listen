<?php

namespace Tests\Feature;

use App\Filament\Pages\SeoSettings;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * SEO Settings → Ad pixels and tracking code: the page head code is printed on the site's
 * pages (never inside the Android app), and the "paid plan started" code runs on the billing
 * page when a card payment for a plan succeeds, with the plan, the amount and the currency.
 * The ChatGPT Ads pixel is put in the empty boxes by a migration.
 */
class AdPixelSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const HEAD = '<script>oaiq("init",{pixelId:"PIXEL_TEST"});</script>';

    private function setting(string $key, string $value): void
    {
        SiteSetting::set($key, $value, 'seo');
        Cache::flush();
    }

    private function billingPage(array $headers = []): string
    {
        config(['services.stripe.key' => 'pk_test_fake', 'services.stripe.secret' => 'sk_test_fake']);
        Plan::factory()->create(['slug' => 'free', 'name' => 'Free', 'price_cents' => 0, 'is_active' => true]);
        Plan::factory()->create(['slug' => 'starter', 'name' => 'Starter', 'price_cents' => 900, 'voice_tier' => 'hd', 'is_active' => true,
            'stripe_price_monthly' => 'price_starter_m', 'stripe_price_yearly' => 'price_starter_y']);
        app()->setLocale('en');

        return (string) $this->actingAs(User::factory()->create(['subscription_status_checked_at' => now()]))
            ->withHeaders($headers)->getLocalizedRoute('billing.page')->assertOk()->getContent();
    }

    public function test_the_head_code_is_on_the_site_but_never_in_the_android_app(): void
    {
        $this->setting('custom_head_code', self::HEAD);

        app()->setLocale('en');
        $this->assertStringContainsString(self::HEAD, (string) $this->getLocalizedRoute('home')->getContent());
        app()->setLocale('ar');
        $this->assertStringContainsString(self::HEAD, (string) $this->getLocalizedRoute('home')->getContent());
        $this->assertStringContainsString(self::HEAD, $this->billingPage());

        app()->setLocale('en');
        $app = (string) $this->withHeaders(['User-Agent' => 'Mozilla/5.0 (Linux; Android 14) ListenApp/1.0.0'])
            ->getLocalizedRoute('login')->getContent();
        $this->assertStringNotContainsString('PIXEL_TEST', $app);
    }

    public function test_the_plan_started_code_runs_with_the_plan_and_the_amount(): void
    {
        $this->setting('purchase_event_code', "<script>\noaiq(\"measure\", \"subscription_created\", { type: \"plan_enrollment\", plan_id: purchase.plan_id });\n</script>");

        $page = $this->billingPage();
        // Pasted script tags are dropped so the code sits inside the page's own script.
        $this->assertMatchesRegularExpression('#function runPurchaseEventCode\(purchase\) \{\s*try \{\s*oaiq\("measure", "subscription_created", \{ type: "plan_enrollment", plan_id: purchase.plan_id \}\);\s*\} catch#', $page);
        $this->assertStringContainsString('const hasPurchaseEventCode = true;', $page);
        $this->assertStringContainsString("plan_id: plan ? plan + '_' + cycle : '',", $page);
        $this->assertSame(2, substr_count($page, 'await trackPurchaseConversion(selected.meta, selected, subscribeBody.ads_event_id);'));
    }

    public function test_without_plan_started_code_the_billing_page_runs_nothing_and_waits_for_nothing(): void
    {
        $this->setting('purchase_event_code', '');
        $page = $this->billingPage();
        $this->assertStringContainsString('const hasPurchaseEventCode = false;', $page);
        $this->assertStringNotContainsString('subscription_created', $page);
    }

    public function test_the_migration_fills_only_empty_boxes(): void
    {
        $migration = require database_path('migrations/2026_12_06_090000_add_the_chatgpt_ads_pixel.php');
        SiteSetting::query()->whereIn('key', ['custom_head_code', 'purchase_event_code'])->delete();
        $this->setting('purchase_event_code', 'console.log("ours")');

        $migration->up();
        Cache::flush();

        $head = (string) SiteSetting::get('custom_head_code');
        $this->assertStringContainsString('pixelId:"X3wiXsHtetMpUTppXyHw5b"', $head);
        $this->assertStringContainsString('https://bzrcdn.openai.com/sdk/oaiq.min.js', $head);
        $this->assertStringNotContainsString('debug', $head);
        $this->assertSame('console.log("ours")', SiteSetting::get('purchase_event_code'));

        // On a site with nothing there, the event is put in too.
        SiteSetting::query()->where('key', 'purchase_event_code')->delete();
        Cache::flush();
        $migration->up();
        Cache::flush();
        $this->assertStringContainsString('oaiq("measure", "subscription_created", openAiPlan);', (string) SiteSetting::get('purchase_event_code'));
    }

    public function test_the_admin_saves_the_codes_in_seo_settings(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(SeoSettings::class)
            ->fillForm(['custom_head_code' => self::HEAD, 'purchase_event_code' => 'oaiq("measure","subscription_created",{type:"plan_enrollment"});'])
            ->call('save')
            ->assertHasNoFormErrors();

        Cache::flush();
        $this->assertSame(self::HEAD, SiteSetting::get('custom_head_code'));
        $this->assertSame('oaiq("measure","subscription_created",{type:"plan_enrollment"});', SiteSetting::get('purchase_event_code'));
    }
}
