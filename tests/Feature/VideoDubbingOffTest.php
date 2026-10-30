<?php

namespace Tests\Feature;

use App\Filament\Pages\VideoDubbingSettings;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\Subscription;
use App\Models\User;
use App\Models\VideoDub;
use App\Support\PlanPricingLines;
use App\Support\SeoHelper;
use App\Support\StudioFacts;
use App\Support\VideoDubbing;
use App\Support\VoiceCloningAccess;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Tests\TestCase;

/**
 * Video Dubbing → Settings → "Off for everyone": dubbing is gone from the whole
 * site, the admin preview account included, until it is switched back on.
 */
class VideoDubbingOffTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Routes have no locale prefix in tests (TestCase::localizedRoute).
        $this->withoutMiddleware([
            LocaleSessionRedirect::class,
            LaravelLocalizationRedirectFilter::class,
            LaravelLocalizationViewPath::class,
        ]);
    }

    public function test_off_hides_dubbing_from_everyone_the_admin_preview_included(): void
    {
        $this->switchTo(VideoDubbing::AUDIENCE_OFF);
        $preview = User::factory()->create(['email' => VoiceCloningAccess::PREVIEW_EMAIL, 'email_verified_at' => now(), 'subscription_status_checked_at' => now()]);

        $this->assertTrue(VideoDubbing::off());
        $this->assertFalse(VideoDubbing::openTo($preview));
        $this->get(route('dub.page'))->assertNotFound();
        $this->actingAs($preview)->get(route('dub.page'))->assertNotFound();
        // The switch above the demo may still offer Voice Design: never a dubbing tab.
        $this->actingAs($preview)->get(route('demo.page'))->assertOk()->assertDontSee('data-dub-studio', false)->assertDontSee('data-studio-tab="dub"', false);
        $this->actingAs($preview)->get(route('dashboard'))->assertOk()->assertDontSee('id="dubVideoSection"', false)->assertDontSee('href="#dubVideoSection"', false);
        $this->actingAs($preview)->postJson(route('dub.upload.start'), ['name' => 'a.mp4', 'size' => 1000])->assertNotFound();
    }

    public function test_off_keeps_past_dubs_from_being_watched_or_downloaded(): void
    {
        $member = $this->member();
        $dub = VideoDub::query()->create(['user_id' => $member->id, 'status' => VideoDub::READY, 'dialect' => 'ar-SA', 'voice' => 'Puck', 'video_seconds' => 5, 'has_clean' => true]);

        $this->switchTo(VideoDubbing::AUDIENCE_OFF);

        $this->actingAs($member)->getJson(route('dub.status', ['code' => $dub->code]))->assertNotFound();
        $this->actingAs($member)->get(route('dub.watch', ['code' => $dub->code]))->assertNotFound();
        $this->actingAs($member)->get(route('dub.download', ['code' => $dub->code]))->assertNotFound();
        $this->assertNotNull($dub->fresh(), 'nothing is deleted');

        // Back on: the member follows it again.
        $this->switchTo(VideoDubbing::AUDIENCE_EVERYONE);
        $this->actingAs($member)->getJson(route('dub.status', ['code' => $dub->code]))->assertOk();
    }

    public function test_off_leaves_dubbing_out_of_plan_cards_and_what_search_engines_read(): void
    {
        $plan = Plan::factory()->create(['price_cents' => 900, 'video_dubbing_enabled' => true, 'video_dubs_per_month' => 20, 'video_dub_max_seconds' => 90]);

        $this->switchTo(VideoDubbing::AUDIENCE_EVERYONE);
        $this->assertNotNull(PlanPricingLines::value($plan, 'video_dubbing', 'en'));
        $this->assertStringContainsString('voice cloning and video dubbing in 140+', SeoHelper::description('home', 'en'));
        $this->assertStringContainsString('واستنساخ الصوت، ودبلجة الفيديو، بـ140', SeoHelper::description('home', 'ar'));

        $this->switchTo(VideoDubbing::AUDIENCE_OFF);
        $this->assertFalse(StudioFacts::dubbing());
        $this->assertNull(PlanPricingLines::value($plan, 'video_dubbing', 'en'));
        $this->assertStringContainsString('voice cloning in 140+', SeoHelper::description('home', 'en'));
        $this->assertStringNotContainsString('dubbing', SeoHelper::description('home', 'en'));
        $this->assertStringNotContainsString('دبلجة', SeoHelper::description('home', 'ar'));
        $this->assertStringContainsString('noindex', SeoHelper::robots('dub'));
        $this->assertStringNotContainsString('dub-my-video', (string) $this->get('/sitemap.xml')->getContent());
        $this->assertStringNotContainsString('Video dubbing', (string) $this->get('/llms.txt')->getContent());
    }

    public function test_lines_the_admin_typed_about_dubbing_leave_the_pricing_page_too(): void
    {
        $typed = Plan::factory()->create(['name' => 'Typed', 'slug' => 'typed', 'price_cents' => 1900, 'is_active' => true, 'video_dubbing_enabled' => true,
            'pricing_use_custom_text' => true,
            'features_en' => [['label' => 'Voice minutes', 'value' => '500 minutes', 'icon' => 'clock'], ['label' => 'Video dubbing', 'value' => '20 videos a month', 'icon' => 'mic']],
            'features_ar' => [['label' => 'دقائق الصوت', 'value' => '500 دقيقة', 'icon' => 'clock'], ['label' => 'دبلجة الفيديو', 'value' => '20 فيديو شهرياً', 'icon' => 'mic']]]);
        $extra = Plan::factory()->create(['name' => 'Extra', 'slug' => 'extra', 'price_cents' => 2900, 'is_active' => true,
            'pricing_extra_lines_en' => [['label' => 'Dub my video', 'value' => 'Included'], ['label' => 'Priority support', 'value' => 'Included']]]);

        $this->switchTo(VideoDubbing::AUDIENCE_EVERYONE);
        $this->assertContains('Video dubbing', array_column(PlanPricingLines::forPlan($typed, 'en'), 'label'));
        $this->assertContains('دبلجة الفيديو', array_column(PlanPricingLines::forPlan($typed, 'ar'), 'label'));
        $this->assertContains('Dub my video', array_column(PlanPricingLines::forComparison($extra, 'en'), 'label'));

        foreach ([VideoDubbing::AUDIENCE_OFF, VideoDubbing::AUDIENCE_PREVIEW] as $audience) {
            $this->switchTo($audience);
            $this->assertSame(['Voice minutes'], array_column(PlanPricingLines::forPlan($typed, 'en'), 'label'));
            $this->assertSame(['دقائق الصوت'], array_column(PlanPricingLines::forPlan($typed, 'ar'), 'label'));
            $this->assertNotContains('Dub my video', array_column(PlanPricingLines::forPlan($extra, 'en'), 'label'));
            $this->assertNotContains('Dub my video', array_column(PlanPricingLines::forComparison($extra, 'en'), 'label'));
            $this->assertContains('Priority support', array_column(PlanPricingLines::forComparison($extra, 'en'), 'label'));
        }

        app()->setLocale('en');
        $this->switchTo(VideoDubbing::AUDIENCE_EVERYONE);
        $this->get(route('pricing'))->assertOk()->assertSee('Video dubbing')->assertSee('Dub my video');

        $this->switchTo(VideoDubbing::AUDIENCE_OFF);
        $page = $this->get(route('pricing'))->assertOk()->assertSee('Priority support');
        foreach (['Video dubbing', 'Dub my video', '20 videos a month'] as $text) {
            $page->assertDontSee($text);
        }
    }

    public function test_the_admin_can_switch_it_off_and_back_on(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(VideoDubbingSettings::class)
            ->assertSee('Off for everyone')
            ->set('data.video_dubbing_audience', VideoDubbing::AUDIENCE_OFF)
            ->call('save')
            ->assertHasNoErrors();
        $this->assertSame(VideoDubbing::AUDIENCE_OFF, VideoDubbing::audience());

        Livewire::test(VideoDubbingSettings::class)
            ->assertSet('data.video_dubbing_audience', VideoDubbing::AUDIENCE_OFF)
            ->set('data.video_dubbing_audience', VideoDubbing::AUDIENCE_EVERYONE)
            ->call('save');
        $this->assertSame(VideoDubbing::AUDIENCE_EVERYONE, VideoDubbing::audience());
    }

    private function switchTo(string $audience): void
    {
        SiteSetting::set('video_dubbing_audience', $audience, 'video_dubbing');
        SiteSetting::flushGroupCache('video_dubbing');
    }

    private function member(): User
    {
        $plan = Plan::factory()->create(['monthly_chars' => 100000, 'monthly_requests' => 100, 'price_cents' => 2200, 'video_dubbing_enabled' => true]);
        $user = User::factory()->create(['email_verified_at' => now(), 'subscription_status_checked_at' => now()]);
        Subscription::query()->create(['user_id' => $user->id, 'plan_id' => $plan->id, 'status' => 'active',
            'current_period_start' => now()->startOfMonth(), 'current_period_end' => now()->endOfMonth()]);

        return $user;
    }
}
