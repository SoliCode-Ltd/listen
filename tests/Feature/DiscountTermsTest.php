<?php

namespace Tests\Feature;

use App\Http\Controllers\BillingController;
use App\Models\BillingSubscription;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\CashierStripePackGateway;
use App\Services\BillingLifecycleService;
use App\Services\StripeProductSyncService;
use App\Support\DiscountTerms;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use ReflectionMethod;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\StripeClient;
use Tests\TestCase;

/**
 * A discount says how long it lasts and what the buyer pays after it, and
 * Stripe charges exactly that.
 */
class DiscountTermsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);

        parent::tearDown();
    }

    private function plan(?string $duration, ?int $months = null): Plan
    {
        return new Plan(['discount_duration' => $duration, 'discount_duration_months' => $months]);
    }

    private function plain(string $text): string
    {
        return str_replace(["\u{2066}", "\u{2069}"], '', $text);
    }

    public function test_the_terms_say_how_long_the_discount_lasts_and_the_price_after_it(): void
    {
        app()->setLocale('en');

        $this->assertSame('30% off your first month, then $9.00/month', $this->plain(DiscountTerms::line($this->plan(null), 'monthly', 30, '$9.00')));
        $this->assertSame('30% off your first 3 months, then $9.00/month', $this->plain(DiscountTerms::line($this->plan('repeating', 3), 'monthly', 30, '$9.00')));
        $this->assertSame('50% off your first year, then $86.40/year', $this->plain(DiscountTerms::line($this->plan('repeating', 3), 'yearly', 50, '$86.40')));
        $this->assertSame('30% off for as long as you stay subscribed', DiscountTerms::line($this->plan('forever'), 'monthly', 30, '$9.00'));

        app()->setLocale('ar');

        $this->assertSame('خصم 30% على أول شهر، ثم $9.00 شهرياً', $this->plain(DiscountTerms::line($this->plan('once'), 'monthly', 30, '$9.00')));
        $this->assertSame('خصم 30% على أول شهرين، ثم $9.00 شهرياً', $this->plain(DiscountTerms::line($this->plan('repeating', 2), 'monthly', 30, '$9.00')));
        $this->assertSame('خصم 30% على أول 3 أشهر، ثم $9.00 شهرياً', $this->plain(DiscountTerms::line($this->plan('repeating', 3), 'monthly', 30, '$9.00')));
        $this->assertSame('خصم 30% على أول 12 شهراً، ثم $9.00 شهرياً', $this->plain(DiscountTerms::line($this->plan('repeating', 12), 'monthly', 30, '$9.00')));
    }

    public function test_the_full_price_starts_after_the_discounted_payments(): void
    {
        $bought = Carbon::parse('2026-01-31 10:00:00');

        $this->assertSame('2026-02-28', DiscountTerms::fullPriceFrom($this->plan('once'), 'monthly', $bought)->toDateString());
        $this->assertSame('2026-04-30', DiscountTerms::fullPriceFrom($this->plan('repeating', 3), 'monthly', $bought)->toDateString());
        $this->assertSame('2027-01-31', DiscountTerms::fullPriceFrom($this->plan('repeating', 3), 'yearly', $bought)->toDateString());
        $this->assertNull(DiscountTerms::fullPriceFrom($this->plan('forever'), 'monthly', $bought));
    }

    public function test_the_stripe_coupon_lasts_as_long_as_the_plan_says(): void
    {
        $this->assertSame(['duration' => 'once'], $this->plan(null)->stripeCouponDuration());
        $this->assertSame(['duration' => 'repeating', 'duration_in_months' => 6], $this->plan('repeating', 6)->stripeCouponDuration());
        $this->assertSame(['duration' => 'forever'], $this->plan('forever')->stripeCouponDuration());
        // A few months without a valid number of months stays a first-payment discount.
        $this->assertSame(['duration' => 'once'], $this->plan('repeating', 40)->stripeCouponDuration());

        $stripe = new CouponStripeClient;
        ApiRequestor::setHttpClient($stripe);
        $plan = Plan::factory()->create([
            'slug' => 'lite', 'price_cents' => 900, 'discount_percentage' => 30,
            'discount_duration' => 'repeating', 'discount_duration_months' => 3,
        ]);

        $sync = new ReflectionMethod(StripeProductSyncService::class, 'syncCoupon');
        $sync->setAccessible(true);
        $couponId = $sync->invoke(app(StripeProductSyncService::class), new StripeClient('sk_test_fake'), $plan);

        $this->assertSame('listen_lite_discount_30_m3', $couponId);
        $this->assertSame('repeating', $stripe->created['duration'] ?? null);
        $this->assertSame('3', (string) ($stripe->created['duration_in_months'] ?? ''));
    }

    public function test_the_pricing_page_shows_the_offer_above_the_full_price(): void
    {
        Carbon::setTestNow('2026-10-20 12:00:00');
        Plan::factory()->create(['slug' => 'free', 'name' => 'Free', 'price_cents' => 0, 'is_active' => true]);
        Plan::factory()->create([
            'slug' => 'starter', 'name' => 'Starter', 'price_cents' => 900, 'is_active' => true,
            'discount_percentage' => 30, 'discount_ends_at' => now()->addWeek(),
        ]);
        Plan::factory()->create(['slug' => 'pro', 'name' => 'Pro', 'price_cents' => 4900, 'is_active' => true]);

        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class, LaravelLocalizationViewPath::class])
            ->get($this->localizedRoute('pricing'))
            ->assertOk()
            // The label above the price and the full price struck through beside it.
            ->assertSee('class="offer-label"', false)
            ->assertSee('30% off your first month')
            ->assertSee('<s class="price-original" dir="ltr">', false)
            // The button says what is off: the offer on monthly, the yearly saving on yearly.
            ->assertSee('data-monthly-label="Get 30% off"', false)
            ->assertSee('data-yearly-label="Get 20% off yearly"', false)
            ->assertDontSee('your first year')
            // On yearly, each plan's usual monthly price is struck through beside its yearly price.
            ->assertSee('class="price-original-row is-yearly"', false)
            ->assertSee('class="plan-pick-was"', false)
            ->assertSee('30% off')
            // The comparison table shows the offer under the plan's price too.
            ->assertSee('class="compare-plan-offer"', false)
            // A week left: one timer above the cards, days : hours : minutes : seconds, each with its unit.
            ->assertSee('<div class="plans-countdown"><span class="offer-ends"', false)
            ->assertSee('Offer ends in 7 days')
            ->assertSee('<b>07</b><small>days</small>', false)
            ->assertSee('<b>00</b><small>sec</small>', false);

        Carbon::setTestNow();
    }

    public function test_an_offer_is_for_monthly_billing_only(): void
    {
        SiteSetting::set('billing_yearly_discount_percentage', '20', 'billing');
        $plan = Plan::factory()->create([
            'slug' => 'starter', 'name' => 'Starter', 'price_cents' => 900, 'is_active' => true,
            'discount_percentage' => 50, 'discount_ends_at' => now()->addWeek(), 'stripe_coupon_id' => 'listen_starter_discount_50',
        ]);

        // A month is half price; a year keeps its usual 20% off, nothing more.
        $this->assertSame(450, $plan->getEffectivePriceCents());
        $this->assertSame(8640, $plan->getEffectiveYearlyPriceCents());

        // Only a monthly checkout gets the offer's coupon.
        $coupon = new ReflectionMethod(BillingController::class, 'resolveCheckoutCouponId');
        $coupon->setAccessible(true);
        $this->assertSame('listen_starter_discount_50', $coupon->invoke(app(BillingController::class), $plan, 'monthly'));
        $this->assertNull($coupon->invoke(app(BillingController::class), $plan, 'yearly'));
    }

    public function test_the_last_day_of_an_offer_counts_down(): void
    {
        Carbon::setTestNow('2026-10-20 12:00:00');
        Plan::factory()->create(['slug' => 'free', 'name' => 'Free', 'price_cents' => 0, 'is_active' => true]);
        Plan::factory()->create([
            'slug' => 'starter', 'name' => 'Starter', 'price_cents' => 900, 'is_active' => true,
            'discount_percentage' => 10, 'discount_ends_at' => now()->addHours(5),
        ]);

        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class, LaravelLocalizationViewPath::class])
            ->get($this->localizedRoute('pricing'))
            ->assertOk()
            ->assertSee('class="offer-ends"', false)
            // The last day: hours : minutes : seconds, no days.
            ->assertSee('<b>05</b><small>hrs</small>', false)
            ->assertDontSee('<small>days</small>', false)
            ->assertSee('Offer ends in 5 hours');

        Carbon::setTestNow();
    }

    public function test_offers_that_end_at_different_times_keep_a_timer_on_each_card(): void
    {
        Carbon::setTestNow('2026-10-20 12:00:00');
        Plan::factory()->create(['slug' => 'free', 'name' => 'Free', 'price_cents' => 0, 'is_active' => true]);
        Plan::factory()->create([
            'slug' => 'starter', 'name' => 'Starter', 'price_cents' => 900, 'is_active' => true,
            'discount_percentage' => 30, 'discount_ends_at' => now()->addDays(3),
        ]);
        Plan::factory()->create([
            'slug' => 'pro', 'name' => 'Pro', 'price_cents' => 4900, 'is_active' => true,
            'discount_percentage' => 20, 'discount_ends_at' => now()->addDays(5),
        ]);

        $html = $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class, LaravelLocalizationViewPath::class])
            ->get($this->localizedRoute('pricing'))
            ->assertOk()
            ->assertDontSee('class="plans-countdown"', false)
            ->getContent();

        $this->assertSame(2, substr_count($html, 'class="offer-ends"'));
        $this->assertStringContainsString('<b>03</b><small>days</small>', $html);
        $this->assertStringContainsString('<b>05</b><small>days</small>', $html);

        Carbon::setTestNow();
    }

    public function test_egyptian_visitors_paying_by_instapay_see_the_offer_too(): void
    {
        SiteSetting::set('pricing_forced_country_code', 'EG', 'billing');
        SiteSetting::set('manual_instapay_enabled', '1', 'billing');
        SiteSetting::set('manual_instapay_number', '0123456789', 'billing');
        Plan::factory()->create(['slug' => 'free', 'name' => 'Free', 'price_cents' => 0, 'is_active' => true]);
        Plan::factory()->create([
            'slug' => 'pro', 'name' => 'Pro', 'price_cents' => 4900, 'is_active' => true,
            'discount_percentage' => 10, 'discount_ends_at' => now()->addWeek(),
            'discount_duration' => 'repeating', 'discount_duration_months' => 2,
        ]);

        app()->setLocale('ar');
        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class, LaravelLocalizationViewPath::class])
            ->get($this->localizedRoute('pricing'))
            ->assertOk()
            ->assertSee('class="offer-label"', false)
            ->assertSee('خصم 10% على أول شهرين')
            // The button says what is off.
            ->assertSee('احصل على خصم 10%');
    }

    public function test_checkout_says_when_the_full_price_starts(): void
    {
        Carbon::setTestNow('2026-10-17 12:00:00');
        Plan::factory()->create(['slug' => 'free', 'name' => 'Free', 'price_cents' => 0, 'is_active' => true]);
        Plan::factory()->create([
            'slug' => 'starter', 'name' => 'Starter', 'price_cents' => 900, 'is_active' => true,
            'discount_percentage' => 30, 'discount_ends_at' => now()->addWeek(),
            'discount_duration' => 'repeating', 'discount_duration_months' => 3,
            'stripe_price_monthly' => 'price_starter_m', 'stripe_price_yearly' => 'price_starter_y',
        ]);

        $this->actingAs(User::factory()->create())
            ->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class, LaravelLocalizationViewPath::class])
            ->get(route('billing.page', ['plan' => 'starter']))
            ->assertOk()
            ->assertViewHas('plans', function (array $plans): bool {
                $starter = collect($plans)->firstWhere('slug', 'starter');
                $monthly = str_replace(["\u{2066}", "\u{2069}"], '', (string) ($starter['discount_terms']['monthly'] ?? ''));

                // The offer and the price after it, nothing more (no "full price from" date),
                // and nothing for yearly billing, which has no offer.
                return str_starts_with($monthly, '30% off your first 3 months, then')
                    && str_ends_with($monthly, '/month.')
                    && ! str_contains($monthly, 'Full price')
                    && ! array_key_exists('yearly', $starter['discount_terms'])
                    && ! array_key_exists('manual_yearly', $starter['discount_terms']);
            });

        Carbon::setTestNow();
    }

    public function test_card_members_get_stripes_renewal_reminder_instead_of_ours(): void
    {
        Mail::fake();
        $paid = Plan::factory()->create(['slug' => 'starter', 'price_cents' => 900, 'is_active' => true]);
        $renews = now()->addDays(7);

        $card = User::factory()->create();
        Subscription::query()->create(['user_id' => $card->id, 'plan_id' => $paid->id, 'status' => 'active', 'current_period_start' => now()->subMonth(), 'current_period_end' => $renews]);
        BillingSubscription::query()->create(['user_id' => $card->id, 'type' => 'default', 'stripe_id' => 'sub_card', 'stripe_status' => 'active', 'stripe_price' => 'price_x', 'quantity' => 1]);

        $other = User::factory()->create();
        Subscription::query()->create(['user_id' => $other->id, 'plan_id' => $paid->id, 'status' => 'active', 'current_period_start' => now()->subMonth(), 'current_period_end' => $renews]);

        $sent = app(BillingLifecycleService::class)->sendPreRenewalReminders();

        $this->assertSame(1, $sent);
        $this->assertNull(Subscription::query()->where('user_id', $card->id)->value('billing_reminder_7d_sent_at'));
        $this->assertNotNull(Subscription::query()->where('user_id', $other->id)->value('billing_reminder_7d_sent_at'));
    }

    public function test_stripe_shows_listen_on_receipts_and_card_statements(): void
    {
        $stripe = new CouponStripeClient;
        ApiRequestor::setHttpClient($stripe);
        $plan = Plan::factory()->create(['slug' => 'lite', 'name' => 'Starter', 'price_cents' => 900]);

        $sync = new ReflectionMethod(StripeProductSyncService::class, 'syncProduct');
        $sync->setAccessible(true);
        $sync->invoke(app(StripeProductSyncService::class), new StripeClient('sk_test_fake'), $plan);

        $this->assertSame('Listen Starter', $stripe->products['name'] ?? null);
        $this->assertSame('LISTEN BY SOLICODE', $stripe->products['statement_descriptor'] ?? null);
        $this->assertSame('Listen Pro', app(StripeProductSyncService::class)->productName(new Plan(['name' => 'Listen Pro'])));
    }

    public function test_a_pack_charge_ends_with_listen_on_the_card_statement(): void
    {
        config(['cashier.secret' => 'sk_test_fake', 'cashier.key' => 'pk_test_fake']);
        $stripe = new CouponStripeClient;
        ApiRequestor::setHttpClient($stripe);

        app(CashierStripePackGateway::class)->chargeCard('cus_x', 'pm_x', 500, 'usd', 'Listen pack', [], 'key_x');

        $this->assertSame('LISTEN', $stripe->intent['statement_descriptor_suffix'] ?? null);
    }

    public function test_the_site_still_names_the_plan_on_invoices_with_listen_in_the_product_name(): void
    {
        Plan::factory()->create(['slug' => 'starter', 'name' => 'Starter']);
        Plan::factory()->create(['slug' => 'pro', 'name' => 'Pro']);

        $guess = new ReflectionMethod(BillingController::class, 'guessSlugFromDescription');
        $guess->setAccessible(true);
        $controller = app(BillingController::class);

        $this->assertSame('starter', $guess->invoke($controller, '1 × Listen Starter (at $9.00 / month)'));
        $this->assertSame('pro', $guess->invoke($controller, '1 × Listen Pro (at $49.00 / month)'));
        $this->assertSame('starter', $guess->invoke($controller, '1 × Starter Plan (at $9.00 / month)'));
    }
}

class CouponStripeClient implements ClientInterface
{
    public array $created = [];

    public array $products = [];

    public array $intent = [];

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $path = (string) parse_url($absUrl, PHP_URL_PATH);
        if ($path === '/v1/products/search') {
            return [json_encode(['object' => 'search_result', 'data' => [], 'has_more' => false, 'url' => '/v1/products/search']), 200, []];
        }
        if (strtolower($method) === 'post' && $path === '/v1/payment_intents') {
            $this->intent = (array) $params;

            return [json_encode(['id' => 'pi_x', 'object' => 'payment_intent', 'status' => 'succeeded', 'client_secret' => null, 'amount' => 500, 'currency' => 'usd', 'customer' => 'cus_x', 'metadata' => []]), 200, []];
        }
        if (strtolower($method) === 'post' && $path === '/v1/products') {
            $this->products = (array) $params;

            return [json_encode(['id' => 'prod_new', 'object' => 'product'] + (array) $params), 200, []];
        }

        if (strtolower($method) === 'post' && parse_url($absUrl, PHP_URL_PATH) === '/v1/coupons') {
            $this->created = (array) $params;

            return [json_encode(['object' => 'coupon', 'valid' => true] + (array) $params), 200, []];
        }

        return [json_encode(['error' => ['type' => 'invalid_request_error', 'message' => 'No such coupon']]), 404, []];
    }
}
