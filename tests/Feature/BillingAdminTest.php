<?php

namespace Tests\Feature;

use App\Filament\Pages\BillingSettings;
use App\Filament\Resources\PlanResource\Pages\EditPlan;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Stripe\ApiRequestor;
use Tests\Support\FakeStripePlanSyncClient;
use Tests\TestCase;

/**
 * Billing → Plans and Billing → Billing Settings: what the admin is told
 * about Stripe is what really happened, and plan numbers stay sensible.
 */
class BillingAdminTest extends TestCase
{
    use RefreshDatabase;

    private FakeStripePlanSyncClient $stripe;

    private Plan $creator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stripe = new FakeStripePlanSyncClient;
        ApiRequestor::setHttpClient($this->stripe);

        Plan::factory()->create(['slug' => 'free', 'name' => 'Free', 'price_cents' => 0, 'monthly_chars' => 4500]);
        Plan::factory()->create(['slug' => 'starter', 'name' => 'Starter', 'price_cents' => 900, 'monthly_chars' => 225000]);
        $this->creator = Plan::factory()->create([
            'slug' => 'creator', 'name' => 'Creator', 'price_cents' => 1900, 'monthly_chars' => 900000,
            'monthly_requests' => 0, 'max_chars_per_request' => 5000, 'recent_voices_days' => 30,
            'stripe_product_id' => 'prod_creator', 'stripe_price_monthly' => 'price_month_1900', 'stripe_price_yearly' => 'price_year_18240',
        ]);
        // What Stripe holds now: the monthly price, and the yearly one at the 20% discount.
        $this->stripe->prices = [
            'price_month_1900' => ['unit_amount' => 1900, 'active' => true],
            'price_year_18240' => ['unit_amount' => 18240, 'active' => true],
        ];

        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);

        parent::tearDown();
    }

    public function test_saving_a_plan_says_when_the_stripe_sync_failed(): void
    {
        $this->stripeConfigured();
        $this->stripe->failWith = 'Stripe is having trouble.';

        $this->editCreator()
            ->fillForm(['monthly_chars' => 950000])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Stripe Sync Failed');

        // The plan itself was saved.
        $this->assertSame(950000, (int) $this->creator->fresh()->monthly_chars);
    }

    public function test_saving_a_plan_says_stripe_synced_only_when_it_did(): void
    {
        $this->stripeConfigured();

        $this->editCreator()
            ->fillForm(['monthly_chars' => 950000])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Stripe Synced');
    }

    public function test_saving_a_plan_off_the_live_site_leaves_stripe_alone_and_says_so(): void
    {
        $this->stripeConfigured();
        config(['listen.live_actions' => false]);

        $this->editCreator()
            ->fillForm(['monthly_chars' => 950000])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Stripe not changed');

        $this->assertSame([], $this->stripe->requests);
    }

    public function test_plan_numbers_cannot_be_negative_and_a_paid_plan_needs_credits(): void
    {
        $this->editCreator()
            ->fillForm([
                'price_dollars' => '-5',
                'monthly_requests' => -1,
                'recent_voices_days' => -3,
                'topup_char_price_dollars' => '-1',
                'topup_minute_price_dollars' => '-1',
            ])
            ->call('save')
            ->assertHasFormErrors([
                'price_dollars' => 'min',
                'monthly_requests' => 'min',
                'recent_voices_days' => 'min',
                'topup_char_price_dollars' => 'min',
                'topup_minute_price_dollars' => 'min',
            ]);

        $this->editCreator()
            ->fillForm(['monthly_chars' => 0])
            ->call('save')
            ->assertHasFormErrors(['monthly_chars' => 'min']);
        $this->assertSame(900000, (int) $this->creator->fresh()->monthly_chars);
    }

    public function test_plans_with_the_values_live_holds_still_save(): void
    {
        // A free plan with no monthly credits, unlimited requests and retention.
        $free = Plan::query()->where('slug', 'free')->firstOrFail();
        Livewire::test(EditPlan::class, ['record' => $free->getRouteKey()])
            ->fillForm(['monthly_chars' => 0, 'monthly_requests' => 0, 'recent_voices_days' => 0])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame(0, (int) $free->fresh()->monthly_chars);

        // A paid plan above the studio's own per-request cap, with no limits of its own.
        $this->editCreator()
            ->fillForm(['max_chars_per_request' => 120000, 'daily_requests' => 0, 'recent_voices_days' => 0, 'monthly_requests' => 0])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame(120000, (int) $this->creator->fresh()->max_chars_per_request);
    }

    public function test_changing_the_yearly_discount_gives_every_plan_its_new_yearly_price_in_stripe(): void
    {
        $this->stripeConfigured();

        $this->billingSettings()
            ->fillForm(['yearly_discount_percentage' => 30])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Yearly prices synced to Stripe');

        // 12 months at 30% off; the old yearly price is archived, the monthly one kept.
        $this->creator->refresh();
        $this->assertSame('price_year_15960', $this->creator->stripe_price_yearly);
        $this->assertSame('price_month_1900', $this->creator->stripe_price_monthly);
        $this->assertFalse($this->stripe->prices['price_year_18240']['active']);
    }

    public function test_a_failed_yearly_price_sync_is_reported(): void
    {
        $this->stripeConfigured();
        $this->stripe->failWith = 'Stripe is having trouble.';

        $this->billingSettings()
            ->fillForm(['yearly_discount_percentage' => 30])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Yearly prices: the Stripe sync failed');

        // The discount itself is saved.
        $this->assertSame(30, SiteSetting::yearlyDiscountPercent());
    }

    public function test_the_yearly_prices_are_left_alone_when_the_discount_is_not_changed_or_off_the_live_site(): void
    {
        $this->stripeConfigured();

        $this->billingSettings()->call('save')->assertHasNoFormErrors();
        $this->assertSame([], $this->stripe->requests);

        config(['listen.live_actions' => false]);
        $this->billingSettings()
            ->fillForm(['yearly_discount_percentage' => 30])
            ->call('save')
            ->assertNotified('Yearly prices not changed in Stripe');
        $this->assertSame([], $this->stripe->requests);
    }

    public function test_grace_days_start_at_one_and_the_hint_says_how_long_they_may_be(): void
    {
        // 0 is refused: it used to be saved and then quietly mean one day.
        $this->billingSettings()
            ->fillForm(['grace_days' => 0])
            ->call('save')
            ->assertHasFormErrors(['grace_days' => 'min']);
        $this->assertNull(SiteSetting::query()->where('key', 'billing_grace_days')->value('value'));

        $this->billingSettings()
            ->fillForm(['grace_days' => 1])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('1', (string) SiteSetting::get('billing_grace_days'));

        $this->billingSettings()
            ->assertSee('shorter than Stripe&#039;s own retry period for failed payments', false)
            ->assertSee('shorter than a billing month', false);
    }

    private function stripeConfigured(): void
    {
        config(['services.stripe.secret' => 'sk_test_fake', 'cashier.secret' => 'sk_test_fake']);
    }

    private function editCreator(): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(EditPlan::class, ['record' => $this->creator->getRouteKey()]);
    }

    private function billingSettings(): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(BillingSettings::class);
    }
}
