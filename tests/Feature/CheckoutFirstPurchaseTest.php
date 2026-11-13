<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use App\Services\Billing\StripePackGateway;
use App\Services\PlanAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeStripePackGateway;
use Tests\TestCase;

/**
 * On a phone, a member buying for the first time sees the way to pay on the first screen: the
 * checkout marks itself as a first purchase, and there the boxes that only say "Free, Standard,
 * Not saved" and the two hint lines step aside. A member with a plan keeps the page as it was.
 * The floating help button steps aside while a way to pay is on screen.
 */
class CheckoutFirstPurchaseTest extends TestCase
{
    use RefreshDatabase;

    private Plan $starter;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.stripe.key' => 'pk_test_fake', 'services.stripe.secret' => 'sk_test_fake']);
        Plan::factory()->create(['slug' => 'free', 'name' => 'Free', 'price_cents' => 0, 'is_active' => true]);
        $this->starter = Plan::factory()->create([
            'slug' => 'starter', 'name' => 'Starter', 'price_cents' => 900, 'voice_tier' => 'hd', 'is_active' => true,
            'stripe_price_monthly' => 'price_starter_m', 'stripe_price_yearly' => 'price_starter_y',
        ]);
        Plan::factory()->create([
            'slug' => 'creator', 'name' => 'Creator', 'price_cents' => 2200, 'voice_tier' => 'hd', 'is_active' => true,
            'stripe_price_monthly' => 'price_creator_m', 'stripe_price_yearly' => 'price_creator_y',
        ]);
        $this->app->instance(StripePackGateway::class, new FakeStripePackGateway);
    }

    private function checkout(User $user): TestResponse
    {
        return $this->actingAs($user)->getLocalizedRoute('billing.page')->assertOk();
    }

    public function test_a_member_with_no_plan_gets_the_first_purchase_page(): void
    {
        $this->checkout(User::factory()->create(['subscription_status_checked_at' => now()]))
            ->assertSee('<main class="container main-content first-purchase">', false)
            ->assertSee('<p class="upgrade-hint">', false);
    }

    public function test_a_member_with_a_plan_keeps_the_page_as_it_was(): void
    {
        $user = User::factory()->create(['subscription_status_checked_at' => now()]);
        app(PlanAccessService::class)->assignPlan($user, $this->starter, 'active', now()->addMonth(), now());

        $this->checkout($user)
            ->assertSee('<main class="container main-content">', false)
            ->assertDontSee('main-content first-purchase', false);
    }

    public function test_only_a_phone_hides_the_boxes_and_the_help_button_steps_aside_for_a_way_to_pay(): void
    {
        $page = (string) $this->checkout(User::factory()->create(['subscription_status_checked_at' => now()]))->getContent();

        // The boxes and hints go only under the phone's width, and only on a first purchase.
        $this->assertMatchesRegularExpression(
            '/@media \(max-width: 640px\) \{\s*\.first-purchase \.status-grid,\s*\.first-purchase \.page-header p,\s*\.first-purchase \.upgrade-hint \{\s*display: none;/',
            $page,
        );
        // The account's three boxes are still in the page for every wider screen.
        $this->assertStringContainsString('<div class="status-grid"', $page);

        // The help button: aside while the wallet, the pay button or the trial button is on screen.
        $this->assertStringContainsString(".wa-widget.is-aside:not(.open) {", $page);
        $this->assertStringContainsString("['wallet', 'subscribeBtn', 'trialBtn'].forEach", $page);
        $this->assertStringContainsString("widget.classList.toggle('is-aside', phone.matches && onScreen.size > 0)", $page);
    }
}
