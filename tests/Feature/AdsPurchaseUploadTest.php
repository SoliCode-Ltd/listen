<?php

namespace Tests\Feature;

use App\Filament\Resources\ManualPaymentRequestResource\Pages\ListManualPaymentRequests;
use App\Http\Middleware\CaptureAdClickId;
use App\Models\AdsPurchase;
use App\Models\BillingTrial;
use App\Models\ManualPaymentRequest;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\Billing\AdsPurchases;
use App\Services\Billing\PurchaseAdsConversionExport;
use App\Services\ManualPaymentService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Stripe\ApiRequestor;
use Tests\Support\FakeStripeHttpClient;
use Tests\TestCase;

/**
 * Sales that came from a Google Ads click are uploaded once they are really paid:
 * a payment by hand when the team approves it (never when its proof is sent), a
 * card payment when Stripe says its invoice is paid, whichever way it was paid.
 */
class AdsPurchaseUploadTest extends TestCase
{
    use RefreshDatabase;

    private Plan $creator;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.stripe.key' => 'pk_test_fake', 'services.stripe.secret' => 'sk_test_fake', 'cashier.key' => 'pk_test_fake', 'cashier.secret' => 'sk_test_fake']);
        ApiRequestor::setHttpClient(new FakeStripeHttpClient);

        Plan::query()->create([
            'slug' => 'free', 'name' => 'FREE', 'price_cents' => 0, 'monthly_minutes' => 300, 'monthly_chars' => 30000,
            'monthly_requests' => 60, 'max_chars_per_request' => 600, 'is_active' => true,
        ]);
        $this->creator = Plan::query()->create([
            'slug' => 'creator', 'name' => 'Creator', 'price_cents' => 1900, 'monthly_minutes' => 5000, 'monthly_chars' => 500000,
            'monthly_requests' => 0, 'max_chars_per_request' => 5000, 'is_active' => true, 'stripe_price_monthly' => 'price_creator_m',
        ]);
        SiteSetting::set('manual_instapay_enabled', '1', 'billing');
        SiteSetting::set('manual_instapay_number', '0123456789', 'billing');
        SiteSetting::set('pricing_exchange_rate_EGP', '52.35', 'billing');
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);

        parent::tearDown();
    }

    private function web()
    {
        return $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class, LaravelLocalizationViewPath::class, ValidateCsrfToken::class]);
    }

    private function manualRequest(User $user, ?string $gclid): ManualPaymentRequest
    {
        return app(ManualPaymentService::class)->createRequest(
            $user, $this->creator, 'monthly', 'instapay',
            ['amount_minor' => 99500, 'currency' => 'EGP', 'country_code' => 'EG'],
            ['country_code' => 'EG', 'lines' => ['0123456789']],
            null,
            $gclid,
        );
    }

    /** @param array<string, mixed> $extra */
    private function invoice(string $id, string $reason, int $amountPaid = 2200, string $customer = 'cus_ads', array $extra = []): object
    {
        return json_decode(json_encode(array_merge([
            'id' => $id, 'object' => 'invoice', 'customer' => $customer, 'currency' => 'usd',
            'amount_paid' => $amountPaid, 'billing_reason' => $reason, 'subscription' => 'sub_ads',
            'status_transitions' => ['paid_at' => Carbon::parse('2026-10-28 09:30:00', 'UTC')->timestamp],
        ], $extra)));
    }

    public function test_a_payment_by_hand_is_sent_with_its_ad_click_and_counts_only_once_approved(): void
    {
        $user = User::factory()->create();

        $this->web()->actingAs($user)->withHeaders(['CF-IPCountry' => 'EG'])
            ->withCookie(CaptureAdClickId::COOKIE, 'Cj0-manual_click')
            ->post($this->localizedRoute('billing.manual.confirm'), ['plan' => 'creator', 'billing_cycle' => 'monthly', 'payment_method' => 'instapay'])
            ->assertRedirect();

        $request = ManualPaymentRequest::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertSame('Cj0-manual_click', $request->gclid);
        // The proof is only on its way: not a sale yet.
        $this->assertSame(0, AdsPurchase::query()->count());

        $reviewer = User::factory()->create(['is_admin' => true]);
        app(ManualPaymentService::class)->approve($request, $reviewer);

        $purchase = AdsPurchase::query()->sole();
        $this->assertSame(AdsPurchase::SOURCE_MANUAL, $purchase->source);
        $this->assertSame('manual:'.$request->id, $purchase->reference);
        $this->assertSame('Cj0-manual_click', $purchase->gclid);
        $this->assertSame((int) $request->fresh()->expected_amount_minor, $purchase->value_minor);
        $this->assertSame('EGP', $purchase->currency);
    }

    public function test_a_refused_payment_by_hand_or_one_without_an_ad_click_is_never_sent(): void
    {
        $refused = $this->manualRequest(User::factory()->create(), 'Cj0-refused');
        app(ManualPaymentService::class)->reject($refused, User::factory()->create(['is_admin' => true]), 'No payment found.');

        $noClick = $this->manualRequest(User::factory()->create(), null);
        app(ManualPaymentService::class)->approve($noClick, User::factory()->create(['is_admin' => true]));

        $this->assertSame(0, AdsPurchase::query()->count());

        // Sent without the click on the request, but the account kept a recent one.
        $member = User::factory()->create(['ads_click_id' => 'Cj0-account_click', 'ads_click_at' => now()->subDays(3)]);
        app(ManualPaymentService::class)->approve($this->manualRequest($member, null), User::factory()->create(['is_admin' => true]));
        $this->assertSame('Cj0-account_click', AdsPurchase::query()->sole()->gclid);
    }

    public function test_the_card_checkout_keeps_the_ad_click_on_the_account(): void
    {
        $user = User::factory()->create(['subscription_status_checked_at' => now()]);

        // Stripe (faked) refuses: the click is kept all the same, for the payment to come.
        $this->web()->actingAs($user)->withCredentials()->withCookie(CaptureAdClickId::COOKIE, 'Cj0-card_click')
            ->postJson($this->localizedRoute('billing.subscribe'), ['plan' => 'creator', 'billing_cycle' => 'monthly', 'payment_method' => 'pm_card']);

        $user->refresh();
        $this->assertSame('Cj0-card_click', $user->ads_click_id);
        $this->assertNotNull($user->ads_click_at);
    }

    public function test_a_paid_card_invoice_for_a_new_plan_or_a_plan_change_is_kept_once(): void
    {
        User::factory()->create(['stripe_id' => 'cus_ads', 'ads_click_id' => 'Cj0-card_click', 'ads_click_at' => now()->subDays(2)]);
        $ads = app(AdsPurchases::class);

        // Stripe sends invoice.paid and invoice.payment_succeeded for one payment.
        foreach (['invoice.paid', 'invoice.payment_succeeded'] as $type) {
            $ads->handleStripeEvent((object) ['type' => $type, 'data' => (object) ['object' => $this->invoice('in_new', 'subscription_create')]]);
        }
        $ads->recordPaidInvoice($this->invoice('in_change', 'subscription_update', 1300));

        // Never a renewal, a $0 invoice or another event.
        $ads->recordPaidInvoice($this->invoice('in_renewal', 'subscription_cycle'));
        $ads->recordPaidInvoice($this->invoice('in_zero', 'subscription_create', 0));
        $ads->handleStripeEvent((object) ['type' => 'invoice.payment_failed', 'data' => (object) ['object' => $this->invoice('in_failed', 'subscription_create')]]);

        $this->assertSame(['invoice:in_new', 'invoice:in_change'], AdsPurchase::query()->orderBy('id')->pluck('reference')->all());
        $first = AdsPurchase::query()->where('reference', 'invoice:in_new')->sole();
        $this->assertSame(AdsPurchase::SOURCE_CARD, $first->source);
        $this->assertSame(2200, $first->value_minor);
        $this->assertSame('USD', $first->currency);
        $this->assertSame('2026-10-28 09:30:00', $first->purchased_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_a_card_invoice_without_a_recent_ad_click_or_from_a_trial_is_not_kept(): void
    {
        User::factory()->create(['stripe_id' => 'cus_none']);
        User::factory()->create(['stripe_id' => 'cus_old', 'ads_click_id' => 'Cj0-old', 'ads_click_at' => now()->subDays(AdsPurchases::CLICK_DAYS + 1)]);
        $trialMember = User::factory()->create(['stripe_id' => 'cus_trial', 'ads_click_id' => 'Cj0-trial', 'ads_click_at' => now()]);
        BillingTrial::query()->create([
            'user_id' => $trialMember->id, 'plan_id' => $this->creator->id, 'status' => BillingTrial::STATUS_TRIALING,
            'stripe_subscription_id' => 'sub_trial', 'amount_cents' => 2200,
        ]);
        $ads = app(AdsPurchases::class);

        $ads->recordPaidInvoice($this->invoice('in_none', 'subscription_create', 2200, 'cus_none'));
        $ads->recordPaidInvoice($this->invoice('in_old', 'subscription_create', 2200, 'cus_old'));
        // A trial's payment goes to the trial upload instead.
        $ads->recordPaidInvoice($this->invoice('in_trial', 'subscription_update', 2200, 'cus_trial', ['subscription' => 'sub_trial']));
        $ads->recordPaidInvoice($this->invoice('in_stranger', 'subscription_create', 2200, 'cus_unknown'));

        $this->assertSame(0, AdsPurchase::query()->count());
    }

    public function test_the_upload_file_names_each_kind_of_sale_and_downloads_from_the_admin(): void
    {
        $user = User::factory()->create();
        AdsPurchase::query()->create(['user_id' => $user->id, 'source' => AdsPurchase::SOURCE_CARD, 'reference' => 'invoice:in_1', 'gclid' => 'Cj0-card',
            'value_minor' => 2200, 'currency' => 'USD', 'purchased_at' => Carbon::parse('2026-10-28 09:30:00', 'UTC')]);
        AdsPurchase::query()->create(['user_id' => $user->id, 'source' => AdsPurchase::SOURCE_MANUAL, 'reference' => 'manual:1', 'gclid' => 'Cj0-manual',
            'value_minor' => 99500, 'currency' => 'EGP', 'purchased_at' => Carbon::parse('2026-10-28 10:00:00', 'UTC')]);
        AdsPurchase::query()->create(['user_id' => $user->id, 'source' => AdsPurchase::SOURCE_MANUAL, 'reference' => 'manual:2', 'gclid' => 'Cj0-kwd',
            'value_minor' => 2805, 'currency' => 'KWD', 'purchased_at' => Carbon::parse('2026-10-28 11:00:00', 'UTC')]);
        // Older than Google takes.
        AdsPurchase::query()->create(['user_id' => $user->id, 'source' => AdsPurchase::SOURCE_CARD, 'reference' => 'invoice:in_old', 'gclid' => 'Cj0-old',
            'value_minor' => 900, 'currency' => 'USD', 'purchased_at' => now()->subDays(AdsPurchases::CLICK_DAYS + 5)]);

        $export = app(PurchaseAdsConversionExport::class);
        $this->assertSame([
            ['Cj0-card', 'Card purchase (server)', '2026-10-28 09:30:00+00:00', '22.00', 'USD'],
            ['Cj0-manual', 'Manual purchase', '2026-10-28 10:00:00+00:00', '995.00', 'EGP'],
            ['Cj0-kwd', 'Manual purchase', '2026-10-28 11:00:00+00:00', '2.81', 'KWD'],
        ], $export->rows());

        SiteSetting::set('google_ads_manual_purchase_conversion_name', 'InstaPay sale', 'seo');
        $this->assertSame('InstaPay sale', $export->rows()[1][1]);
        $this->assertStringStartsWith("\"Google Click ID\",\"Conversion Name\",\"Conversion Time\",\"Conversion Value\",\"Conversion Currency\"\n", $export->csv());

        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(ListManualPaymentRequests::class)
            ->callAction('exportGoogleAdsPurchases')
            ->assertFileDownloaded('google-ads-purchases-'.now()->format('Y-m-d').'.csv');
    }

    public function test_the_billing_page_no_longer_reports_a_payment_by_hand_when_its_proof_is_sent(): void
    {
        $billing = (string) file_get_contents(resource_path('views/billing.blade.php'));
        $manualBranch = substr($billing, (int) strpos($billing, "const response = await postForm('{{ route('billing.manual.confirm') }}', formData);"));
        $manualBranch = substr($manualBranch, 0, (int) strpos($manualBranch, 'proofInput.value = \'\';'));

        $this->assertStringNotContainsString('trackPurchaseConversion', $manualBranch);
        // Card payments still are.
        $this->assertStringContainsString('await trackPurchaseConversion(selected.meta);', $billing);
    }
}
