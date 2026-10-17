<?php

namespace Tests\Feature;

use App\Models\BillingSubscription;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
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

    public function test_the_pricing_page_says_what_buyers_pay_after_the_discount(): void
    {
        Plan::factory()->create(['slug' => 'free', 'name' => 'Free', 'price_cents' => 0, 'is_active' => true]);
        Plan::factory()->create([
            'slug' => 'starter', 'name' => 'Starter', 'price_cents' => 900, 'is_active' => true,
            'discount_percentage' => 30, 'discount_ends_at' => now()->addWeek(),
        ]);

        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class, LaravelLocalizationViewPath::class])
            ->get($this->localizedRoute('pricing'))
            ->assertOk()
            ->assertSee('class="discount-terms"', false)
            ->assertSee('30% off your first month, then')
            ->assertSee('data-terms-yearly', false)
            ->assertSee('50% off your first year, then');
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

                return str_starts_with($monthly, '30% off your first 3 months, then')
                    && str_contains($monthly, 'Full price from 17 Jan 2027');
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
}

class CouponStripeClient implements ClientInterface
{
    public array $created = [];

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        if (strtolower($method) === 'post' && parse_url($absUrl, PHP_URL_PATH) === '/v1/coupons') {
            $this->created = (array) $params;

            return [json_encode(['object' => 'coupon', 'valid' => true] + (array) $params), 200, []];
        }

        return [json_encode(['error' => ['type' => 'invalid_request_error', 'message' => 'No such coupon']]), 404, []];
    }
}
