<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\Billing\StripePackGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeStripePackGateway;
use Tests\TestCase;

/**
 * The billing pages in the browser: without Stripe.js (blocked or failed) the
 * card form says so and InstaPay / Vodafone Cash keep working; the pay button
 * stays off while a payment is on its way; a card is shown the US dollar
 * amount it is charged, with the local price as "about"; and a pack payment
 * that is still processing, or declined, is told in the page's language.
 */
class BillingPageClientTest extends TestCase
{
    use RefreshDatabase;

    private FakeStripePackGateway $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.stripe.key' => 'pk_test_fake', 'services.stripe.secret' => 'sk_test_fake']);
        Plan::factory()->create(['slug' => 'free', 'name' => 'Free', 'price_cents' => 0, 'is_active' => true]);
        Plan::factory()->create([
            'slug' => 'starter', 'name' => 'Starter', 'price_cents' => 900, 'voice_tier' => 'hd', 'is_active' => true,
            'stripe_price_monthly' => 'price_starter_m', 'stripe_price_yearly' => 'price_starter_y',
        ]);
        $this->stripe = new FakeStripePackGateway;
        $this->app->instance(StripePackGateway::class, $this->stripe);
    }

    private function billingPage(string $locale): TestResponse
    {
        app()->setLocale($locale);

        return $this->actingAs(User::factory()->create(['subscription_status_checked_at' => now()]))
            ->getLocalizedRoute('billing.page')->assertOk();
    }

    private function pack(): Plan
    {
        return Plan::factory()->create([
            'slug' => 'pack', 'name' => 'Premium Pack', 'kind' => Plan::KIND_PACK, 'pack_days' => 30,
            'price_cents' => 200, 'monthly_chars' => 20000, 'monthly_requests' => 0,
            'max_chars_per_request' => 5000, 'voice_tier' => 'hd', 'is_active' => true,
        ]);
    }

    /** A string as @json writes it into the page's script. */
    private static function js(string $text): string
    {
        return json_encode($text, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    }

    public function test_the_billing_page_keeps_working_without_stripe_js(): void
    {
        $page = $this->billingPage('ar')
            ->assertSee("stripe = stripeKey && typeof Stripe === 'function' ? Stripe(stripeKey) : null;", false)
            ->assertSee('const cardFormMissing = Boolean(stripeKey) && !stripe;', false)
            ->assertSee('if (cardFormMissing && cardError) cardError.textContent = cardUnavailableText;', false)
            ->assertSee('if (!stripe || !card) throw new Error(cardUnavailableText);', false)
            ->assertDontSee('const stripe = stripeKey ? Stripe(stripeKey) : null;', false);

        $text = __('ui.billing.card_form_unavailable', [], 'ar');
        $this->assertMatchesRegularExpression('/\p{Arabic}/u', $text);
        $page->assertSee('"card_form_unavailable":'.self::js($text), false);
    }

    public function test_pay_stays_off_until_the_payment_is_answered(): void
    {
        $this->billingPage('en')
            ->assertSee('let paymentInFlight = false;', false)
            // Redrawing the form (another plan, cycle or method) leaves Pay alone meanwhile.
            ->assertSee('if (subscribeBtn && !paymentInFlight) {', false)
            ->assertSee("      paymentInFlight = Boolean(isBusy);\n", false)
            // A second submit or the trial button does nothing while one is on its way.
            ->assertSee("e.preventDefault();\n      if (paymentInFlight) return;", false)
            ->assertSee("trialBtn?.addEventListener('click', async () => {\n      if (paymentInFlight) return;", false)
            // After success the next page loads with Pay still off.
            ->assertSee('if (!leaving) setSubscribeButtonBusy(false);', false)
            // The server's 409 ("a payment is already in progress") is shown as it says.
            ->assertSee('throw new Error(response.data?.message || i18n.upgrade_failed);', false);
    }

    public function test_a_card_is_shown_the_price_in_the_visitors_own_currency_only(): void
    {
        SiteSetting::set('pricing_forced_country_code', 'EG', 'billing');
        SiteSetting::set('pricing_exchange_rate_EGP', '50', 'billing');

        // The page gets the local price; it is the amount shown, with no dollar price beside it.
        $page = $this->billingPage('en')
            ->assertViewHas('plans', function (array $plans): bool {
                $monthly = collect($plans)->firstWhere('slug', 'starter')['payment_methods']['stripe']['monthly'];

                return str_replace("\u{a0}", ' ', $monthly['amount_formatted']) === 'EGP 450';
            });

        $page->assertSee("return { amount: String(meta?.amount_formatted || '') };", false)
            ->assertSee('let amountText = cardCharge(selected.meta).amount || \'-\';', false)
            ->assertSee('const amount = cardCharge(cycle === \'yearly\' ? plan?.yearly : plan?.monthly).amount;', false)
            ->assertDontSee('quoteAmountAbout', false)
            ->assertDontSee('base_usd_formatted)', false)
            ->assertDontSee('US dollars');

        $this->billingPage('ar')->assertDontSee('بالدولار الأمريكي');
    }

    public function test_cards_are_typed_in_three_boxes_and_the_pack_is_priced_in_local_money(): void
    {
        config(['services.stripe.key' => 'pk_test_dummy', 'services.stripe.secret' => 'sk_test_dummy']);
        SiteSetting::set('pricing_forced_country_code', 'EG', 'billing');
        SiteSetting::set('pricing_exchange_rate_EGP', '50', 'billing');
        $this->pack();

        // The number, the expiry date and the code, each in its own labelled box.
        $boxes = fn (string $id) => ['id="'.$id.'-number"', 'id="'.$id.'-expiry"', 'id="'.$id.'-cvc"'];
        $billing = $this->billingPage('ar');
        foreach ($boxes('card') as $box) {
            $billing->assertSee($box, false);
        }
        $billing->assertSee(__('ui.billing.card_number', [], 'ar'))
            ->assertSee(__('ui.billing.card_expiry', [], 'ar'))
            ->assertSee(__('ui.billing.card_cvc', [], 'ar'))
            ->assertSee("window.ListenCardFields(elements, 'card', resolveCardStyle()", false)
            // Stripe's boxes are drawn in the site font, like the rest of the page.
            ->assertSee('const elements = stripe ? window.ListenCardElements(stripe) : null;', false)
            ->assertSee('window.ListenSiteFont = { cssSrc:', false)
            ->assertSee('family=Alexandria', false)
            ->assertDontSee("elements.create('card',", false);

        // The pack page too, with its price in the visitor's own money.
        $pack = $this->actingAs(User::factory()->create(['subscription_status_checked_at' => now()]))
            ->getLocalizedRoute('billing.pack')->assertOk()
            ->assertSee("window.ListenCardFields(window.ListenCardElements(stripe), 'pack-card', cardStyle()", false);
        foreach ($boxes('pack-card') as $box) {
            $pack->assertSee($box, false);
        }
        preg_match('/class="pack-amount"[^>]*>([^<]*)</u', (string) $pack->getContent(), $price);
        $this->assertStringContainsString('ج.م', $price[1]);
        $this->assertStringNotContainsString('$', $price[1]);
    }

    public function test_a_new_plan_and_a_pack_can_be_paid_with_a_phone_wallet(): void
    {
        // Stripe's wallet button above the card boxes, in the page's language.
        $billing = $this->billingPage('ar')
            ->assertViewHas('walletPay', true)
            ->assertSee('<div class="wallet-pay" id="wallet" hidden>', false)
            ->assertSee(__('ui.billing.wallet_or_card', [], 'ar'))
            ->assertSee("elements.create('expressCheckout', {", false)
            ->assertSee("paymentMethods: { link: 'never', paypal: 'never', amazonPay: 'never', klarna: 'never' },", false)
            // It is shown the US dollar amount charged today, never on a trial.
            ->assertSee("if (!selected || selected.method !== 'stripe' || isTrialCheckout()) return null;", false)
            ->assertSee("if (String(selected.meta?.charge_currency || '').toUpperCase() !== 'USD' || !(amount > 0)) return null;", false)
            // Its card pays through the same steps as the card boxes, purchase conversion included.
            ->assertSee('leaving = await subscribeByCard(charge.selected, paymentMethod, true);', false)
            ->assertSee('leaving = await subscribeByCard(selected, paymentMethod, saveCardCheckbox ? saveCardCheckbox.checked : true);', false)
            ->assertSee("if (paymentInFlight || !charge || !paymentMethod) return;", false)
            // Made even when the page opens on a trial or on InstaPay, so it can show once card is picked.
            ->assertSee("const walletStartCents = Number(currentPlan()?.payment_methods?.stripe?.[String(cycleSelect?.value || 'monthly')]?.base_usd_cents || 0);", false);
        $this->assertMatchesRegularExpression('/\p{Arabic}/u', __('ui.billing.wallet_or_card', [], 'ar'));
        // The page's own words never name the wallets.
        $this->assertDoesNotMatchRegularExpression('/apple pay|google pay/i', strip_tags((string) $billing->getContent()));

        $this->pack();
        $this->actingAs(User::factory()->create(['subscription_status_checked_at' => now()]))
            ->getLocalizedRoute('billing.pack')->assertOk()
            ->assertSee('<div class="wallet-pay" id="pack-wallet" hidden>', false)
            ->assertSee("window.ListenWalletButton(stripe, 'pack-wallet', {", false)
            ->assertSee("mode: 'payment',\n        amount: 200,", false)
            ->assertSee('await payWithCard(paymentMethodId);', false)
            ->assertSee('await payWithCard(method.paymentMethod.id);', false);
    }

    public function test_the_pack_page_keeps_pay_off_while_the_bank_processes_and_without_stripe_js(): void
    {
        $this->pack();
        app()->setLocale('ar');

        $page = $this->actingAs(User::factory()->create(['subscription_status_checked_at' => now()]))
            ->getLocalizedRoute('billing.pack')->assertOk()
            ->assertSee("stripe = typeof Stripe === 'function' ? Stripe(", false)
            ->assertSee('setNotice(i18n.formMissing, \'\');', false)
            ->assertSee('if (response.status === 202 && response.data.processing) {', false)
            ->assertSee('button.textContent = i18n.processingButton;', false)
            ->assertSee('failed(firstError(response.data), response.data && response.data.reason);', false)
            ->assertSee('return isArabicPage && !/[؀-ۿ]/.test(raw) ? [fallback, raw] : [raw || fallback, \'\'];', false)
            ->assertDontSee('errorBox.textContent = event.error ? event.error.message', false);

        foreach (['processingButton' => 'ui.pack.card_processing_button', 'formMissing' => 'ui.billing.card_form_unavailable', 'processing' => 'ui.pack.card_processing'] as $name => $key) {
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', __($key, [], 'ar'));
            $page->assertSee($name.': '.self::js(__($key, [], 'ar')), false);
        }
    }

    public function test_a_declined_pack_card_is_told_in_the_page_language_with_the_reason_aside(): void
    {
        $this->pack();
        $this->stripe->nextOutcome = 'declined';
        app()->setLocale('ar');

        $response = $this->actingAs(User::factory()->create(['subscription_status_checked_at' => now()]))
            ->postJsonLocalizedRoute('billing.pack.card', ['payment_method' => 'pm_card_declined'])
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('message', 'رُفضت بطاقتك، لذلك لم يُخصم أي مبلغ. جرّب بطاقة أخرى.')
            ->assertJsonPath('reason', 'Your card was declined.');

        $this->assertStringNotContainsString('Your card was declined.', (string) $response->json('message'));
    }

    public function test_a_pack_payment_the_bank_is_still_processing_says_so(): void
    {
        $this->pack();
        $this->stripe->nextOutcome = 'processing';
        app()->setLocale('ar');

        $this->actingAs(User::factory()->create(['subscription_status_checked_at' => now()]))
            ->postJsonLocalizedRoute('billing.pack.card', ['payment_method' => 'pm_card_slow'])
            ->assertStatus(202)
            ->assertJsonPath('processing', true)
            ->assertJsonPath('message', __('ui.pack.card_processing', [], 'ar'))
            ->assertJsonMissingPath('reason');
    }

    public function test_a_declined_card_is_told_why_and_offered_what_to_do_next(): void
    {
        $page = $this->billingPage('ar')
            // The decline codes come from one list (CardDecline), in the page and on the server.
            ->assertSee('"insufficient_funds":"no_funds"', false)
            ->assertSee('<div class="decline-help" id="declineHelp" hidden>', false)
            // The server's answer, and Stripe.js after the bank's check or a trial's card check.
            ->assertSee('if (response.data?.decline) {', false)
            ->assertSee("if (confirmation.error.type === 'card_error') throw stripeDeclineError(confirmation.error);", false)
            ->assertSee("if (confirmed.error.type === 'card_error') throw stripeDeclineError(confirmed.error);", false)
            ->assertSee('showPaymentError(error, i18n.upgrade_failed);', false)
            // A refusal is never "invalid card details".
            ->assertSee("if (lowered.includes('insufficient funds')) return i18n.decline_no_funds;", false)
            ->assertDontSee("lowered.includes('declined') ||", false);

        foreach (['no_funds', 'card_details', 'try_again', 'refused'] as $kind) {
            $text = __('ui.billing.decline_'.$kind, [], 'ar');
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', $text);
            $page->assertSee('"decline_'.$kind.'":'.self::js($text), false);
        }
    }
}
