<?php

namespace Tests\Feature;

use App\Filament\Resources\SubscriptionResource;
use App\Filament\Resources\SubscriptionResource\Pages\ListSubscriptions;
use App\Filament\Resources\SubscriptionResource\Pages\ViewSubscription;
use App\Models\CharGift;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use App\Services\Billing\CharGiftService;
use App\Support\PlanUsageGuard;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Admins can gift characters to a subscription for the current billing
 * month, on top of the plan; the gift ends with the month.
 */
class CharGiftTest extends TestCase
{
    use RefreshDatabase;

    private Plan $plan;

    private User $member;

    private Subscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plan = Plan::factory()->create([
            'slug' => 'starter-gift',
            'name' => 'Starter',
            'price_cents' => 900,
            'monthly_chars' => 1000,
            'monthly_requests' => 100,
            'max_chars_per_request' => 5000,
            'allow_overage' => false,
        ]);
        $this->member = User::factory()->create(['email' => 'member@example.com', 'subscription_status_checked_at' => now()]);
        $this->subscription = Subscription::create([
            'user_id' => $this->member->id,
            'plan_id' => $this->plan->id,
            'status' => 'active',
            'current_period_start' => now()->startOfMonth(),
            'current_period_end' => now()->endOfMonth(),
        ]);
    }

    public function test_gift_raises_this_months_limit(): void
    {
        $this->usedThisMonth(990);
        $estimate = ['chars' => 50, 'minutes' => 1];

        $this->assertRefused($estimate);

        app(CharGiftService::class)->grant($this->subscription, 500, 'Sorry for the outage');

        $counter = $this->counter();
        $this->assertSame(500, $counter->bonus_chars);
        $this->assertSame(1500, PlanUsageGuard::charsLimit($this->plan, $counter));
        PlanUsageGuard::assertWithinLimits($this->plan, $counter, $estimate);
        $this->addToAssertionCount(1);
    }

    public function test_gifts_add_up_and_are_recorded(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        app(CharGiftService::class)->grant($this->subscription, 300, null, $admin);
        app(CharGiftService::class)->grant($this->subscription, 200, 'Second', $admin);

        $this->assertSame(500, $this->counter()->bonus_chars);
        $this->assertSame(2, CharGift::count());
        $gift = CharGift::latest('id')->first();
        $this->assertSame($admin->id, $gift->granted_by);
        $this->assertSame($this->subscription->id, $gift->subscription_id);
        $this->assertSame(now()->format('Y-m'), $gift->period_ym);
        $this->assertSame('Second', $gift->note);
    }

    public function test_gift_ends_with_the_month(): void
    {
        app(CharGiftService::class)->grant($this->subscription, 500);

        $this->travelTo(now()->addMonthNoOverflow()->startOfMonth()->addDay());
        $nextMonth = UsageCounter::firstOrCreate(
            ['user_id' => $this->member->id, 'period_ym' => now()->format('Y-m')],
            ['total_minutes' => 0, 'total_chars' => 0, 'total_requests' => 0]
        );

        $this->assertSame(1000, PlanUsageGuard::charsLimit($this->plan, $nextMonth));
        $this->assertSame(0, app(CharGiftService::class)->activeGiftChars($this->member->id));
    }

    public function test_gift_does_not_touch_used_characters(): void
    {
        $this->usedThisMonth(700);

        app(CharGiftService::class)->grant($this->subscription, 500);

        $this->assertSame(700, (int) $this->counter()->total_chars);
    }

    public function test_bad_amounts_are_refused(): void
    {
        foreach ([0, -5, CharGiftService::MAX_GIFT + 1] as $chars) {
            try {
                app(CharGiftService::class)->grant($this->subscription, $chars);
                $this->fail("{$chars} should be refused");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, CharGift::count());
    }

    public function test_a_gift_can_be_taken_back_this_month(): void
    {
        $service = app(CharGiftService::class);
        $keep = $service->grant($this->subscription, 300);
        $mistake = $service->grant($this->subscription, 5000);

        $service->revoke($mistake);
        $service->revoke($mistake); // twice does nothing more

        $this->assertSame(300, $this->counter()->bonus_chars);
        $this->assertNotNull($mistake->fresh()->revoked_at);
        $this->assertNull($keep->fresh()->revoked_at);
    }

    public function test_last_months_gift_cannot_be_taken_back(): void
    {
        $gift = app(CharGiftService::class)->grant($this->subscription, 300);
        $this->travelTo(now()->addMonthNoOverflow()->startOfMonth()->addDay());

        $this->expectException(\InvalidArgumentException::class);
        app(CharGiftService::class)->revoke($gift);
    }

    public function test_dashboard_shows_the_new_total_and_the_gift(): void
    {
        $this->withoutMiddleware([
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRoutes::class,
            \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
        ]);
        $this->usedThisMonth(200);
        app(CharGiftService::class)->grant($this->subscription, 500);

        $this->actingAs($this->member)->getLocalizedRoute('dashboard')
            ->assertOk()
            ->assertSee('1,300 / 1,500 chars remaining', false)
            ->assertSee('Includes 500 gift characters from Listen this month', false);
    }

    public function test_dashboard_shows_no_gift_note_without_a_gift(): void
    {
        $this->withoutMiddleware([
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRoutes::class,
            \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
        ]);

        $this->actingAs($this->member)->getLocalizedRoute('dashboard')
            ->assertOk()
            ->assertDontSee('gift characters from Listen', false);
    }

    public function test_extension_profile_counts_the_gift(): void
    {
        app(CharGiftService::class)->grant($this->subscription, 500);
        $apiKey = $this->member->issueApiKey();

        $response = $this->getJson('/api/reader/profile?api_key='.$apiKey);

        $response->assertOk();
        $this->assertSame(1500, data_get($response->json(), 'profile.usage.plan_chars'));
    }

    public function test_limit_works_before_the_migration_runs(): void
    {
        $counter = new UsageCounter(['total_chars' => 10]);

        $this->assertSame(1000, PlanUsageGuard::charsLimit($this->plan, $counter), 'no bonus column means no gift');
        $this->assertSame(1000, PlanUsageGuard::charsLimit($this->plan, null));
    }

    // ──────────────────────────────────────────────────────────────────
    //  Admin
    // ──────────────────────────────────────────────────────────────────

    public function test_admin_gifts_from_the_subscriptions_list(): void
    {
        $admin = $this->actingAsAdmin();

        Livewire::test(ListSubscriptions::class)
            ->callTableAction('giftChars', $this->subscription, data: ['chars' => 2500, 'note' => 'Loyal member'])
            ->assertHasNoTableActionErrors()
            ->assertNotified();

        $this->assertSame(2500, $this->counter()->bonus_chars);
        $gift = CharGift::first();
        $this->assertSame($admin->id, $gift->granted_by);
        $this->assertSame('Loyal member', $gift->note);
    }

    public function test_admin_form_refuses_a_bad_amount(): void
    {
        $this->actingAsAdmin();

        Livewire::test(ListSubscriptions::class)
            ->callTableAction('giftChars', $this->subscription, data: ['chars' => 0])
            ->assertHasTableActionErrors(['chars']);

        $this->assertSame(0, CharGift::count());
    }

    public function test_subscription_page_lists_gifts_and_can_take_one_back(): void
    {
        $this->actingAsAdmin();
        app(CharGiftService::class)->grant($this->subscription, 4000, 'Typo, meant 400');

        $this->get(SubscriptionResource::getUrl('view', ['record' => $this->subscription]))
            ->assertOk()
            ->assertSee('Gifts from Listen')
            ->assertSee('+4,000')
            ->assertSee('Typo, meant 400');

        $gift = CharGift::first();
        Livewire::test(ViewSubscription::class, ['record' => $this->subscription->getRouteKey()])
            ->callAction('revokeGift', data: ['gift_id' => $gift->id])
            ->assertNotified();

        $this->assertSame(0, $this->counter()->bonus_chars);
        $this->assertNotNull($gift->fresh()->revoked_at);
    }

    public function test_admin_sees_a_clear_message_before_the_migration_runs(): void
    {
        $this->actingAsAdmin();
        Schema::drop('char_gifts');

        Livewire::test(ListSubscriptions::class)
            ->callTableAction('giftChars', $this->subscription, data: ['chars' => 100])
            ->assertNotified('The gift was not added');

        $this->assertSame(0, (int) UsageCounter::where('user_id', $this->member->id)->value('bonus_chars'), 'nothing half-applied');
    }

    // ──────────────────────────────────────────────────────────────────

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return $admin;
    }

    private function usedThisMonth(int $chars): void
    {
        UsageCounter::updateOrCreate(
            ['user_id' => $this->member->id, 'period_ym' => now()->format('Y-m')],
            ['total_minutes' => 1, 'total_chars' => $chars, 'total_requests' => 1]
        );
    }

    private function counter(): UsageCounter
    {
        return UsageCounter::where('user_id', $this->member->id)->where('period_ym', now()->format('Y-m'))->firstOrFail();
    }

    /**
     * @param  array{chars:int,minutes:int}  $estimate
     */
    private function assertRefused(array $estimate): void
    {
        try {
            PlanUsageGuard::assertWithinLimits($this->plan, $this->counter(), $estimate);
            $this->fail('Expected the request to be over the limit.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
    }
}
