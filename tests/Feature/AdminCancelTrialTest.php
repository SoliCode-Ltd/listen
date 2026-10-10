<?php

namespace Tests\Feature;

use App\Filament\Resources\BillingTrialResource\Pages\ListBillingTrials;
use App\Filament\Resources\BillingTrialResource\Pages\ViewBillingTrial;
use App\Models\BillingTrial;
use App\Models\Plan;
use App\Models\User;
use App\Services\Billing\StripeTrialGateway;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\FakeStripeTrialGateway;
use Tests\TestCase;

/**
 * The admin "Cancel trial" button stops a free trial from being charged.
 * It only works where Stripe trial actions are switched on (live), because
 * staging runs the live Stripe keys. Stripe is faked.
 */
class AdminCancelTrialTest extends TestCase
{
    use RefreshDatabase;

    private FakeStripeTrialGateway $stripe;

    private Plan $creator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stripe = new FakeStripeTrialGateway();
        $this->app->instance(StripeTrialGateway::class, $this->stripe);
        config(['listen.billing.stripe_trial_actions' => true]);

        Plan::query()->firstOrCreate(['slug' => 'free'], [
            'name' => 'Free', 'price_cents' => 0, 'monthly_chars' => 10000, 'monthly_minutes' => 10,
            'monthly_requests' => 60, 'max_chars_per_request' => 1200, 'is_active' => true,
        ]);
        $this->creator = Plan::factory()->create(['slug' => 'creator-admin', 'price_cents' => 1900]);

        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_admin_cancels_a_trial_from_the_list(): void
    {
        $trial = $this->trial(['stripe_subscription_id' => 'sub_list_1']);

        Livewire::test(ListBillingTrials::class)
            ->callTableAction('cancelTrial', $trial)
            ->assertNotified('Trial cancelled');

        $this->assertSame(['sub_list_1'], $this->stripe->canceled);
        $trial->refresh();
        $this->assertSame(BillingTrial::STATUS_CANCELED, $trial->status);
        $this->assertSame('canceled_by_admin', $trial->blocked_reason);
        $this->assertNotNull($trial->canceled_at);
    }

    public function test_admin_cancels_a_trial_from_its_page(): void
    {
        $trial = $this->trial(['stripe_subscription_id' => 'sub_page_1']);

        Livewire::test(ViewBillingTrial::class, ['record' => $trial->getRouteKey()])
            ->callAction('cancelTrial')
            ->assertNotified('Trial cancelled');

        $this->assertSame(BillingTrial::STATUS_CANCELED, $trial->fresh()->status);
    }

    public function test_button_is_disabled_where_stripe_trial_actions_are_off(): void
    {
        config(['listen.billing.stripe_trial_actions' => false]);
        $trial = $this->trial(['stripe_subscription_id' => 'sub_staging']);

        Livewire::test(ListBillingTrials::class)
            ->assertTableActionVisible('cancelTrial', $trial)
            ->assertTableActionDisabled('cancelTrial', $trial)
            ->callTableAction('cancelTrial', $trial);

        $this->assertSame([], $this->stripe->canceled);
        $this->assertSame(BillingTrial::STATUS_TRIALING, $trial->fresh()->status);
    }

    public function test_switched_off_by_default(): void
    {
        $this->assertFalse((require base_path('config/listen.php'))['billing']['stripe_trial_actions']);
    }

    public function test_no_button_on_paid_or_closed_trials(): void
    {
        $paid = $this->trial(['status' => BillingTrial::STATUS_PAID, 'paid_at' => now()]);
        $canceled = $this->trial(['status' => BillingTrial::STATUS_CANCELED, 'canceled_at' => now()]);

        Livewire::test(ListBillingTrials::class)
            ->assertTableActionHidden('cancelTrial', $paid)
            ->assertTableActionHidden('cancelTrial', $canceled);
    }

    public function test_when_stripe_refuses_the_trial_stays_running_and_the_admin_is_told(): void
    {
        $this->app->instance(StripeTrialGateway::class, new class extends FakeStripeTrialGateway
        {
            public function cancelSubscription(string $subscriptionId): void
            {
                throw new \RuntimeException('Stripe is down');
            }
        });
        $trial = $this->trial(['stripe_subscription_id' => 'sub_down']);

        Livewire::test(ListBillingTrials::class)
            ->callTableAction('cancelTrial', $trial)
            ->assertNotified('The trial was not cancelled');

        $this->assertSame(BillingTrial::STATUS_TRIALING, $trial->fresh()->status);
    }

    public function test_member_keeps_the_trial_plan_until_its_end_date(): void
    {
        $member = User::factory()->create();
        $trial = $this->trial(['user_id' => $member->id, 'stripe_subscription_id' => 'sub_keep']);

        Livewire::test(ListBillingTrials::class)->callTableAction('cancelTrial', $trial);

        $access = app(\App\Services\PlanAccessService::class);
        $this->assertSame(BillingTrial::STATUS_CANCELED, $trial->fresh()->status);
        $this->assertTrue($access->userHasUnpaidTrialWindow($member->id), 'keeps the trial until its end date');

        $this->travel(5)->days();
        $this->assertFalse($access->userHasUnpaidTrialWindow($member->id), 'and loses it after');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function trial(array $overrides = []): BillingTrial
    {
        return BillingTrial::query()->create(array_merge([
            'user_id' => User::factory()->create()->id,
            'plan_id' => $this->creator->id,
            'status' => BillingTrial::STATUS_TRIALING,
            'stripe_subscription_id' => 'sub_'.uniqid(),
            'card_brand' => 'mastercard',
            'card_last4' => '7130',
            'amount_cents' => 1900,
            'started_at' => now()->subDays(3),
            'trial_ends_at' => now()->addDays(4),
        ], $overrides));
    }
}
