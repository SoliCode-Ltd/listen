<?php

namespace Tests\Feature;

use App\Filament\Resources\PurchaseFollowUpResource;
use App\Filament\Resources\PurchaseFollowUpResource\Pages\ListPurchaseFollowUps;
use App\Filament\Resources\PurchaseFollowUpResource\Pages\PurchaseFollowUpEmails;
use App\Models\BillingSubscription;
use App\Models\Plan;
use App\Models\PurchaseFollowUp;
use App\Models\SiteSetting;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\PurchaseFollowUps;
use App\Services\Billing\RenewalGrace;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Stripe\ApiRequestor;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Tests\Support\FakeStripeHttpClient;
use Tests\TestCase;

/**
 * Members who nearly bought a plan get one email that brings them back: after a first
 * payment the bank declined, or after opening the checkout and leaving without paying.
 * Both are off until the owner turns them on, go out from the live site only, at most once
 * a week for a member and never once they have a paid plan. Every case is kept for the
 * admin with its email and whether the member bought a plan afterwards.
 * Emails go to the array mailer: nothing leaves the machine.
 */
class PurchaseFollowUpTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    private Plan $starter;

    protected function setUp(): void
    {
        parent::setUp();

        Plan::query()->update(['is_active' => false]);
        Plan::factory()->free()->create(['monthly_chars' => 10000, 'voice_tier' => 'standard']);
        $this->starter = Plan::query()->firstOrNew(['slug' => 'starter']);
        $this->starter->forceFill(array_merge(Plan::factory()->raw(), [
            'slug' => 'starter', 'name' => 'Starter', 'name_ar' => 'مبتدئ', 'kind' => Plan::KIND_SUBSCRIPTION, 'is_active' => true,
            'price_cents' => 900, 'monthly_chars' => 90000,
        ]))->save();
        $this->member = User::factory()->create(['name' => 'Sara Ahmed', 'email' => 'sara@example.com', 'stripe_id' => 'cus_sara']);
        config(['app.url' => 'https://listenai.studio']);
    }

    protected function tearDown(): void
    {
        app()->setLocale('en');

        parent::tearDown();
    }

    public function test_opening_a_plans_checkout_without_a_paid_plan_is_noted_once(): void
    {
        app()->setLocale('ar');
        $this->openCheckout($this->member, 'plan=starter&billing_cycle=yearly');

        $row = PurchaseFollowUp::query()->sole();
        $this->assertSame($this->member->id, $row->user_id);
        $this->assertSame(PurchaseFollowUp::KIND_CHECKOUT_LEFT, $row->kind);
        $this->assertSame('starter', $row->plan_slug);
        $this->assertSame('yearly', $row->billing_cycle);
        $this->assertSame('ar', $row->locale);
        $this->assertSame(PurchaseFollowUp::WAITING, $row->email_status);
        $this->assertTrue($row->email_due_at->between(now()->addHours(3)->subMinute(), now()->addHours(3)->addMinute()));

        // Opening it again is the same case, with its reminder moved later.
        $this->travel(2)->hours();
        $this->openCheckout($this->member, 'plan=starter');
        $row = PurchaseFollowUp::query()->sole();
        $this->assertSame(2, $row->opens);
        $this->assertSame('monthly', $row->billing_cycle);
        $this->assertTrue($row->email_due_at->gt(now()->addHours(2)));
    }

    public function test_a_page_the_browser_only_fetched_ahead_of_time_is_not_an_opened_checkout(): void
    {
        app()->setLocale('ar');
        $this->openCheckout($this->member, 'plan=starter');

        // The site prefetches the page's other language: that must not count, nor change the language.
        app()->setLocale('en');
        foreach ([['Sec-Purpose' => 'prefetch'], ['Purpose' => 'prefetch'], ['Sec-Purpose' => 'prefetch;prerender'], ['X-Moz' => 'prefetch']] as $headers) {
            $this->withHeaders($headers)->openCheckout($this->member, 'plan=starter');
            $this->flushHeaders();
        }

        $row = PurchaseFollowUp::query()->sole();
        $this->assertSame(1, $row->opens);
        $this->assertSame('ar', $row->locale);
    }

    public function test_a_checkout_with_no_plan_chosen_a_paid_member_and_an_admin_are_not_noted(): void
    {
        $this->openCheckout($this->member, '');
        $this->openCheckout($this->member, 'plan=no-such-plan');
        $this->openCheckout($this->paidMember(), 'plan=starter');
        $this->openCheckout(User::factory()->create(['is_admin' => true]), 'plan=starter');

        $this->assertSame(0, PurchaseFollowUp::query()->count());
    }

    public function test_the_reminder_goes_out_after_the_wait_in_both_languages_with_a_button_back_to_the_checkout(): void
    {
        $this->turnOn();
        $this->followUps()->checkoutOpened($this->member, $this->starter, 'monthly', 'ar');

        // Not yet.
        $this->travel(2)->hours();
        $this->assertSame(0, $this->followUps()->run()['sent']);
        $this->assertCount(0, $this->sentEmails());

        $this->travel(61)->minutes();
        $this->assertSame(1, $this->followUps()->run()['sent']);

        $email = $this->onlyEmail();
        $this->assertSame('sara@example.com', $email->getTo()[0]->getAddress());
        $this->assertStringContainsString('باقة مبتدئ في إسمع بانتظارك', (string) $email->getSubject());
        $this->assertStringContainsString('Your Listen Starter plan is waiting for you', (string) $email->getSubject());
        $text = (string) $email->getTextBody();
        $this->assertStringContainsString('أهلاً Sara، كنت على بُعد خطوة واحدة من باقة مبتدئ.', $text);
        $this->assertStringContainsString('Hi Sara, you were one step away from the Starter plan.', $text);
        $this->assertStringContainsString('https://listenai.studio/ar/billing?plan=starter&billing_cycle=monthly', $text);
        $this->assertStringContainsString('https://listenai.studio/en/billing?plan=starter&billing_cycle=monthly', $text);
        // About their own purchase, in the site's email design: no unsubscribe link.
        $this->assertStringNotContainsString('Unsubscribe', (string) $email->getHtmlBody());
        $this->assertSame('help@listenai.studio', $email->getReplyTo()[0]->getAddress());

        $row = PurchaseFollowUp::query()->sole();
        $this->assertSame(PurchaseFollowUp::SENT, $row->email_status);
        $this->assertNotNull($row->email_sent_at);

        // Sent once.
        $this->travel(1)->days();
        $this->followUps()->run();
        $this->assertCount(1, $this->sentEmails());
    }

    public function test_a_declined_first_payment_gets_its_own_email_with_the_banks_reason_and_no_checkout_reminder(): void
    {
        $this->turnOn();
        $this->followUps()->checkoutOpened($this->member, $this->starter, 'monthly', 'en');
        $this->followUps()->paymentFailed($this->invoice());
        $this->followUps()->declineReason((object) ['customer' => 'cus_sara', 'last_payment_error' => (object) ['code' => 'card_declined', 'decline_code' => 'insufficient_funds']]);

        $declined = PurchaseFollowUp::query()->where('kind', PurchaseFollowUp::KIND_PAYMENT_FAILED)->sole();
        $this->assertSame('starter', $declined->plan_slug);
        $this->assertSame('insufficient_funds', $declined->reason);
        $this->assertSame('Not enough money on the card', $declined->reasonLabel());
        $this->assertSame(900, $declined->amount_cents);
        $this->assertSame('in_1', $declined->stripe_invoice_id);
        $checkout = PurchaseFollowUp::query()->where('kind', PurchaseFollowUp::KIND_CHECKOUT_LEFT)->sole();
        $this->assertSame(PurchaseFollowUp::SKIPPED, $checkout->email_status);

        // A second try on the same invoice is the same case.
        $this->followUps()->paymentFailed($this->invoice());
        $this->assertSame(2, PurchaseFollowUp::query()->count());

        $this->travel(31)->minutes();
        $this->assertSame(1, $this->followUps()->run()['sent']);
        $email = $this->onlyEmail();
        $this->assertStringContainsString('Your payment for Listen Starter did not go through', (string) $email->getSubject());
        $text = (string) $email->getTextBody();
        $this->assertStringContainsString('but your bank declined the payment because the card did not have enough balance. Nothing was charged', $text);
        $this->assertStringContainsString('لكن البنك رفض عملية الدفع لعدم كفاية الرصيد في البطاقة. لم يُخصم منك أي مبلغ', $text);
        // The button is the member's own signed link, in each language, that leads to the payment.
        $this->assertMatchesRegularExpression('#Complete my payment: https?://\S+/billing/pay/'.$declined->id.'\?expires=\d+&lang=en&signature=[0-9a-f]+#', $text);
        $this->assertMatchesRegularExpression('#إكمال الدفع: https?://\S+/billing/pay/'.$declined->id.'\?expires=\d+&lang=ar&signature=[0-9a-f]+#u', $text);
        $this->assertStringNotContainsString('/en/billing?plan=starter', $text);

        // The checkout's reminder never follows it.
        $this->travel(1)->days();
        $this->followUps()->run();
        $this->assertCount(1, $this->sentEmails());
    }

    public function test_the_declined_payments_button_opens_the_payment_page_while_it_is_open_and_the_checkout_after(): void
    {
        $this->turnOn();
        $this->followUps()->checkoutOpened($this->member, $this->starter, 'monthly', 'ar');
        $this->followUps()->paymentFailed($this->invoice());
        $this->travel(31)->minutes();
        $this->followUps()->run();
        preg_match('#https?://\S+/billing/pay/\d+\?expires=\d+&lang=ar&signature=[0-9a-f]+#', (string) $this->onlyEmail()->getTextBody(), $link);
        $link = (string) ($link[0] ?? '');
        $this->assertNotSame('', $link);

        // The payment is still open: straight to its own payment page, signed in or not.
        $this->invoiceIsOpenAt('https://invoice.stripe.com/i/acct_1/live_abc');
        $this->get($link)->assertRedirect('https://invoice.stripe.com/i/acct_1/live_abc');

        // A day later it has closed: the checkout for the same plan, in the email's language.
        $this->invoiceIsOpenAt(null);
        $this->get($link)->assertRedirect('https://listenai.studio/ar/billing?plan=starter&billing_cycle=monthly');

        // A link that was changed opens the plans, never someone's payment.
        $this->invoiceIsOpenAt('https://invoice.stripe.com/i/acct_1/live_abc');
        $this->get(preg_replace('#/pay/\d+#', '/pay/999', $link))->assertRedirect('https://listenai.studio/ar/pricing');
        $this->get(strtok($link, '?').'?lang=ar')->assertRedirect('https://listenai.studio/ar/pricing');
    }

    public function test_the_banks_reason_is_kept_when_it_arrives_before_the_invoice_and_a_renewal_is_left_alone(): void
    {
        $this->followUps()->declineReason((object) ['customer' => 'cus_sara', 'failure_code' => 'card_declined', 'outcome' => (object) ['reason' => 'expired_card']]);
        $this->followUps()->paymentFailed($this->invoice());
        $this->assertSame('expired_card', PurchaseFollowUp::query()->sole()->reason);

        // A renewal that fails has its own grace days and warnings.
        $this->followUps()->paymentFailed($this->invoice('in_2', 'subscription_cycle'));
        $this->assertSame(1, PurchaseFollowUp::query()->count());
    }

    public function test_both_emails_are_off_until_turned_on(): void
    {
        $this->followUps()->checkoutOpened($this->member, $this->starter, 'monthly', 'ar');
        $this->travel(4)->hours();

        $this->assertSame(0, $this->followUps()->run()['sent']);
        $this->assertCount(0, $this->sentEmails());
        $row = PurchaseFollowUp::query()->sole();
        $this->assertSame(PurchaseFollowUp::OFF, $row->email_status);
        $this->assertSame('Emails were off', $row->emailState()['label']);

        // Turning it on later does not send to old cases.
        $this->turnOn();
        $this->followUps()->run();
        $this->assertCount(0, $this->sentEmails());
    }

    public function test_a_member_who_bought_first_gets_no_email_and_the_case_says_so(): void
    {
        $this->turnOn();
        $this->followUps()->checkoutOpened($this->member, $this->starter, 'monthly', 'ar');
        $this->givePaidPlan($this->member);
        $this->travel(4)->hours();

        $done = $this->followUps()->run();
        $this->assertSame(1, $done['paid']);
        $this->assertSame(0, $done['sent']);
        $this->assertCount(0, $this->sentEmails());
        $row = PurchaseFollowUp::query()->sole();
        $this->assertNotNull($row->paid_at);
        $this->assertSame('starter', $row->paid_plan_slug);
        $this->assertSame(PurchaseFollowUp::SKIPPED, $row->email_status);
    }

    public function test_a_purchase_after_the_email_is_marked_on_the_case(): void
    {
        $this->turnOn();
        $this->followUps()->checkoutOpened($this->member, $this->starter, 'monthly', 'ar');
        $this->travel(4)->hours();
        $this->followUps()->run();

        $this->givePaidPlan($this->member);
        $this->travel(1)->hours();
        $this->assertSame(1, $this->followUps()->run()['paid']);

        $row = PurchaseFollowUp::query()->sole();
        $this->assertSame(PurchaseFollowUp::SENT, $row->email_status);
        $this->assertNotNull($row->paid_at);
    }

    public function test_at_most_one_email_a_week_for_a_member(): void
    {
        $this->turnOn();
        $this->followUps()->checkoutOpened($this->member, $this->starter, 'monthly', 'ar');
        $this->travel(4)->hours();
        $this->followUps()->run();

        // Two days later they look again and leave again.
        $this->travel(2)->days();
        $this->followUps()->checkoutOpened($this->member, $this->starter, 'monthly', 'ar');
        $this->travel(4)->hours();
        $this->followUps()->run();

        $this->assertCount(1, $this->sentEmails());
        $second = PurchaseFollowUp::query()->latest('id')->first();
        $this->assertSame(PurchaseFollowUp::SKIPPED, $second->email_status);
        $this->assertStringContainsString('last 7 days', (string) $second->email_note);
    }

    public function test_only_the_live_site_emails_members_and_phone_sign_ups_have_no_mailbox(): void
    {
        $this->turnOn();
        $phone = User::factory()->create(['email' => '966500000000@users.listen.local']);
        $this->followUps()->checkoutOpened($phone, $this->starter, 'monthly', 'ar');
        $this->followUps()->checkoutOpened($this->member, $this->starter, 'monthly', 'ar');
        $this->travel(4)->hours();

        // Staging: a copy of live members, so nothing goes out.
        config(['listen.live_actions' => false]);
        $this->assertSame(0, $this->followUps()->run()['sent']);
        $this->assertCount(0, $this->sentEmails());
        $notes = PurchaseFollowUp::query()->orderBy('id')->pluck('email_note')->all();
        $this->assertStringContainsString('phone number', (string) $notes[0]);
        $this->assertStringContainsString('Not the live site', (string) $notes[1]);
    }

    public function test_nothing_is_recorded_or_sent_before_the_migration_is_run(): void
    {
        Schema::drop('purchase_follow_ups');

        $this->openCheckout($this->member, 'plan=starter');
        $this->followUps()->paymentFailed($this->invoice());
        $this->assertSame(['paid' => 0, 'sent' => 0, 'skipped' => 0, 'failed' => 0], $this->followUps()->run());
        $this->artisan('listen:purchase-follow-ups')->assertSuccessful();
        $this->assertFalse(PurchaseFollowUpResource::shouldRegisterNavigation());
    }

    public function test_the_admin_sees_every_case_and_edits_the_emails(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = User::factory()->create(['is_admin' => true]);
        $this->turnOn();
        $this->followUps()->checkoutOpened($this->member, $this->starter, 'monthly', 'ar');
        $other = User::factory()->create(['email' => 'omar@example.com', 'stripe_id' => 'cus_omar']);
        $this->followUps()->paymentFailed($this->invoice('in_9', 'subscription_create', 'cus_omar'));
        $this->followUps()->declineReason((object) ['customer' => 'cus_omar', 'last_payment_error' => (object) ['decline_code' => 'insufficient_funds']]);
        $this->travel(4)->hours();
        $this->followUps()->run();

        $this->assertSame('Newsletter', PurchaseFollowUpResource::getNavigationGroup());
        Livewire::actingAs($admin)->test(ListPurchaseFollowUps::class)
            ->assertCanSeeTableRecords(PurchaseFollowUp::query()->get())
            ->assertSee('sara@example.com')
            ->assertSee('Left the checkout')
            ->assertSee('omar@example.com')
            ->assertSee('First payment declined')
            ->assertSee('Not enough money on the card')
            ->assertSee('Sent')
            ->assertSee('First payments declined: 1 (1 emailed, 0 bought after; email on)')
            ->filterTable('kind', PurchaseFollowUp::KIND_PAYMENT_FAILED)
            ->assertCanSeeTableRecords(PurchaseFollowUp::query()->where('kind', PurchaseFollowUp::KIND_PAYMENT_FAILED)->get())
            ->assertCanNotSeeTableRecords(PurchaseFollowUp::query()->where('kind', PurchaseFollowUp::KIND_CHECKOUT_LEFT)->get());

        // The words and switches.
        Livewire::actingAs($admin)->test(PurchaseFollowUpEmails::class)
            ->assertFormSet(['failed_enabled' => true, 'left_after_hours' => '3'])
            ->fillForm(['left_enabled' => false, 'left_after_hours' => 6, 'left_subject_en' => 'Still thinking about {plan}?'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertFalse($this->followUps()->enabled(PurchaseFollowUp::KIND_CHECKOUT_LEFT));
        $this->assertTrue($this->followUps()->enabled(PurchaseFollowUp::KIND_PAYMENT_FAILED));
        $this->assertSame('6', $this->followUps()->settings()['left_after_hours']);
        $this->assertStringContainsString('Still thinking about', $this->followUps()->settings()['left_subject_en']);
        $this->assertStringContainsString('Finish your Starter subscription', $this->followUps()->previewHtml(PurchaseFollowUp::KIND_CHECKOUT_LEFT));

        auth()->logout();
        $this->actingAs($this->member)->get(PurchaseFollowUpResource::getUrl('index'))->assertForbidden();
    }

    /* ------------------------------------------------------------ */
    /*  A paying member's renewal declined */
    /* ------------------------------------------------------------ */

    public function test_a_declined_renewal_is_listed_with_its_warning_in_both_languages_and_a_button_to_pay_it(): void
    {
        $plan = $this->inGrace($this->member);
        $this->followUps()->declineReason((object) ['customer' => 'cus_sara', 'last_payment_error' => (object) ['decline_code' => 'insufficient_funds']]);

        // No switch to turn on: a paying member is always told, at once.
        $this->assertSame(PurchaseFollowUps::RENEWAL_SENT, $this->followUps()->renewalFailed($this->member, $plan, $this->invoice('in_r1', 'subscription_cycle')));

        $case = PurchaseFollowUp::query()->sole();
        $this->assertSame(PurchaseFollowUp::KIND_RENEWAL_FAILED, $case->kind);
        $this->assertSame('Renewal declined', $case->what());
        $this->assertSame(PurchaseFollowUp::SENT, $case->email_status);
        $this->assertNotNull($case->email_sent_at);
        $this->assertSame(['starter', 900, 'usd', 'in_r1', 'insufficient_funds'], [$case->plan_slug, $case->amount_cents, $case->currency, $case->stripe_invoice_id, $case->reason]);
        $this->assertEquals($plan->billing_grace_ends_at, $case->planKeptUntil());

        $email = $this->onlyEmail();
        $this->assertSame(['sara@example.com'], array_map(fn ($to) => $to->getAddress(), $email->getTo()));
        $this->assertSame('Payment failed - action required · لم يكتمل الدفع - مطلوب إجراء منك', (string) $email->getSubject());
        $text = (string) $email->getTextBody();
        $until = now()->addDays(7);
        $this->assertStringContainsString('Hi Sara, we tried to renew your Starter plan, but your bank declined the payment because the card did not have enough balance. Nothing was charged.', $text);
        $this->assertStringContainsString('Your plan stays on until '.$until->format('j F Y').', with all its paid features.', $text);
        $this->assertStringContainsString('أهلاً Sara، حاولنا تجديد باقة مبتدئ الخاصة بك، لكن البنك رفض عملية الدفع لعدم كفاية الرصيد في البطاقة.', $text);
        $this->assertStringContainsString('باقتك مستمرة حتى '.$until->locale('ar')->translatedFormat('j F Y'), $text);
        // The button is the member's own signed link to that renewal's payment, in each language.
        $this->assertMatchesRegularExpression('#Pay now: https?://\S+/billing/pay/'.$case->id.'\?expires=\d+&lang=en&signature=[0-9a-f]+#', $text);
        $this->assertMatchesRegularExpression('#ادفع الآن: https?://\S+/billing/pay/'.$case->id.'\?expires=\d+&lang=ar&signature=[0-9a-f]+#u', $text);
        foreach (['{name}', '{plan}', '{date}', '{reason}'] as $placeholder) {
            $this->assertStringNotContainsString($placeholder, $text.(string) $email->getHtmlBody());
        }

        // The card tried again on the same renewal: the same case, and no second email.
        $this->assertSame(PurchaseFollowUps::RENEWAL_ALREADY, $this->followUps()->renewalFailed($this->member, $plan, $this->invoice('in_r1', 'subscription_cycle')));
        $this->assertSame(1, PurchaseFollowUp::query()->count());
        $this->assertCount(1, $this->sentEmails());

        // They have their plan all along: that is not "paid after", and no follow-up email is theirs.
        $this->turnOn();
        $this->travel(2)->days();
        $this->assertSame(['paid' => 0, 'sent' => 0, 'skipped' => 0, 'failed' => 0], $this->followUps()->run());
        $this->assertNull($case->fresh()->paid_at);

        // The renewal is paid: the case says so, and the next renewal to fail is a new case with its own email.
        $plan->forceFill(['billing_payment_state' => 'past_due'])->save();
        app(RenewalGrace::class)->settle($this->member);
        $this->assertNotNull($case->fresh()->paid_at);
        $this->assertSame('starter', $case->fresh()->paid_plan_slug);
        $this->travel(1)->months();
        $plan = $this->inGrace($this->member);
        $this->assertSame(PurchaseFollowUps::RENEWAL_SENT, $this->followUps()->renewalFailed($this->member, $plan, $this->invoice('in_r2', 'subscription_cycle')));
        $this->assertSame(2, PurchaseFollowUp::query()->count());
        $this->assertCount(2, $this->sentEmails());
    }

    public function test_a_declined_renewals_button_opens_its_payment_page_while_open_and_the_members_subscription_after(): void
    {
        $plan = $this->inGrace($this->member);
        $this->followUps()->renewalFailed($this->member, $plan, $this->invoice('in_r1', 'subscription_cycle'));
        preg_match('#https?://\S+/billing/pay/\d+\?expires=\d+&lang=en&signature=[0-9a-f]+#', (string) $this->onlyEmail()->getTextBody(), $link);
        $link = (string) ($link[0] ?? '');
        $this->assertNotSame('', $link);

        // Still unpaid: straight to that renewal's own payment page.
        $this->invoiceIsOpenAt('https://invoice.stripe.com/i/acct_1/live_renewal');
        $this->get($link)->assertRedirect('https://invoice.stripe.com/i/acct_1/live_renewal');

        // Paid or closed since: their own subscription page, never a checkout for a plan they have.
        $this->invoiceIsOpenAt(null);
        $this->get($link)->assertRedirect('https://listenai.studio/en/manage-subscription');
    }

    public function test_a_renewal_with_no_grace_gets_the_paused_words_and_one_with_no_invoice_a_button_to_the_subscription(): void
    {
        // The first charge after a free trial: no grace days, the paid features pause at once.
        $plan = $this->inGrace($this->member, null);

        $this->assertSame(PurchaseFollowUps::RENEWAL_SENT, $this->followUps()->renewalFailed($this->member, $plan));

        $text = (string) $this->onlyEmail()->getTextBody();
        $this->assertStringContainsString('but your bank declined it, so your paid features are paused. Nothing was charged.', $text);
        $this->assertStringContainsString('لذلك توقفت مزاياك المدفوعة مؤقتاً', $text);
        $this->assertStringNotContainsString('stays on until', $text);
        // No invoice was named: the button opens their subscription page.
        $this->assertStringContainsString('Pay now: https://listenai.studio/en/manage-subscription', $text);
        $this->assertStringContainsString('ادفع الآن: https://listenai.studio/ar/manage-subscription', $text);
        $this->assertNull(PurchaseFollowUp::query()->sole()->planKeptUntil());
    }

    public function test_a_renewal_warning_that_cannot_go_out_in_the_design_goes_as_the_plain_email_and_the_case_says_so(): void
    {
        $plan = $this->inGrace($this->member);
        // The newsletter mailbox is down.
        $this->app->bind(PurchaseFollowUps::class, fn () => new class extends PurchaseFollowUps
        {
            public function send(PurchaseFollowUp $row, ?string $testTo = null): void
            {
                throw new \RuntimeException('The mailbox refused the password.');
            }
        });

        app(RenewalGrace::class)->warn($this->member, $plan, $this->invoice('in_r1', 'subscription_cycle'));

        // The member is still told, in the plain email.
        $email = $this->onlyEmail();
        $this->assertSame('Payment failed - action required', (string) $email->getSubject());
        $this->assertStringContainsString('Your plan stays on until', (string) $email->getTextBody());
        $this->assertNotNull($plan->fresh()->billing_failed_notice_sent_at);
        $case = PurchaseFollowUp::query()->sole();
        $this->assertSame(PurchaseFollowUp::SENT, $case->email_status);
        $this->assertSame('Sent as a plain email', $case->email_note);
    }

    public function test_a_declined_renewal_on_staging_or_with_no_mailbox_is_listed_and_not_emailed(): void
    {
        $phone = User::factory()->create(['email' => '966500000000@users.listen.local']);
        $this->assertSame(PurchaseFollowUps::RENEWAL_SKIPPED, $this->followUps()->renewalFailed($phone, $this->inGrace($phone)));

        // Staging: a copy of live members, so nothing goes out.
        config(['listen.live_actions' => false]);
        $plan = $this->inGrace($this->member);
        app(RenewalGrace::class)->warn($this->member, $plan, $this->invoice('in_r1', 'subscription_cycle'));

        $this->assertCount(0, $this->sentEmails());
        $this->assertNull($plan->fresh()->billing_failed_notice_sent_at);
        $notes = PurchaseFollowUp::query()->orderBy('id')->pluck('email_note', 'email_status')->all();
        $this->assertSame([PurchaseFollowUp::SKIPPED], array_keys($notes));
        $this->assertSame(2, PurchaseFollowUp::query()->where('email_status', PurchaseFollowUp::SKIPPED)->count());
        $this->assertStringContainsString('phone number', (string) PurchaseFollowUp::query()->where('user_id', $phone->id)->value('email_note'));
        $this->assertStringContainsString('Not the live site', (string) PurchaseFollowUp::query()->where('user_id', $this->member->id)->value('email_note'));
    }

    public function test_the_admin_sees_declined_renewals_and_edits_their_email(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = User::factory()->create(['is_admin' => true]);
        $plan = $this->inGrace($this->member);
        $this->followUps()->renewalFailed($this->member, $plan, $this->invoice('in_r1', 'subscription_cycle'));
        $this->followUps()->declineReason((object) ['customer' => 'cus_sara', 'last_payment_error' => (object) ['decline_code' => 'insufficient_funds']]);

        Livewire::actingAs($admin)->test(ListPurchaseFollowUps::class)
            ->assertCanSeeTableRecords(PurchaseFollowUp::query()->get())
            ->assertSee('sara@example.com')
            ->assertSee('Renewal declined')
            ->assertSee('Not enough money on the card')
            ->assertSee('Plan stays on until '.$plan->billing_grace_ends_at->format('j M'))
            ->assertSee('Renewals declined: 1 (1 emailed, 0 paid after)')
            ->filterTable('kind', PurchaseFollowUp::KIND_PAYMENT_FAILED)
            ->assertCanNotSeeTableRecords(PurchaseFollowUp::query()->get());

        Livewire::actingAs($admin)->test(PurchaseFollowUpEmails::class)
            ->assertFormSet(['renewal_subject_en' => 'Payment failed - action required', 'renewal_cta_label_ar' => 'ادفع الآن'])
            ->fillForm(['renewal_headline_en' => 'We could not renew {plan}', 'renewal_cta_label_en' => 'Pay the renewal'])
            ->call('save')
            ->assertHasNoFormErrors();
        $preview = $this->followUps()->previewHtml(PurchaseFollowUp::KIND_RENEWAL_FAILED);
        $this->assertStringContainsString('We could not renew Starter', $preview);
        $this->assertStringContainsString('Pay the renewal', $preview);
        $this->assertStringContainsString('Your plan stays on until '.now()->addDays(7)->format('j F Y'), $preview);
        $this->assertStringNotContainsString('{date}', $preview);
    }

    /* ------------------------------------------------------------ */
    /*  By hand: a discount, and sending an email now */
    /* ------------------------------------------------------------ */

    public function test_a_discount_lowers_that_renewal_and_its_email_becomes_the_offer_with_the_lower_price(): void
    {
        $plan = $this->inGrace($this->member);
        $this->followUps()->renewalFailed($this->member, $plan, $this->invoice('in_r1', 'subscription_cycle'));
        $case = PurchaseFollowUp::query()->sole();
        $this->assertFalse($case->hasDiscount());
        $this->assertSame(900, $case->priceCents());
        $stripe = $this->stripeOwes(900);

        $this->followUps()->giveDiscount($case, 20);

        // The unpaid payment itself is lowered, once, by the discount.
        $this->assertSame([['in_r1', 180, '20% off this renewal']], $stripe->lowered);
        $this->assertStringStartsWith('listen-renewal-discount-'.$case->id.'-', $stripe->keys[0]);
        $case->refresh();
        $this->assertSame([20, 180, 900, 720], [$case->discount_percent, $case->discount_cents, $case->amount_cents, $case->priceCents()]);
        $this->assertNotNull($case->discounted_at);
        // This renewal only: nothing is put on their later renewals.
        $this->assertSame([], $stripe->renewals);
        $this->assertNull($case->discount_months);
        $this->assertNull($case->discount_coupon_id);

        // From now on this member's email is the offer, not the warning.
        app('mailer')->getSymfonyTransport()->flush();
        $this->followUps()->sendNow($case);
        $email = $this->onlyEmail();
        $this->assertSame('Your Starter renewal was declined: pay 20% less to keep your plan · تعذّر تجديد باقة مبتدئ: ادفع أقل بـ20% واحتفظ بها', (string) $email->getSubject());
        $text = (string) $email->getTextBody();
        // Why they are written to, the day the plan ends, the lower price, and what happens if it stays unpaid.
        $until = now()->addDays(7)->translatedFormat('j F Y');
        $this->assertStringContainsString('Renew your Starter plan for $7.20 instead of $9.00', $text);
        $this->assertStringContainsString('we could not renew your Starter plan: your bank declined the payment. Nothing was charged, and your plan stays on until '.$until.'.', $text);
        $this->assertStringContainsString('we have taken 20% off this renewal: you pay $7.20 instead of $9.00.', $text);
        $this->assertStringContainsString('Press the button below to pay before '.$until.'. If the renewal is still unpaid on that day, your subscription will be cancelled, your account will move to the free plan and this offer will end.', $text);
        $this->assertStringContainsString('تعذّر تجديد باقة مبتدئ الخاصة بك: رفض البنك عملية الدفع. لم يُخصم منك أي مبلغ، وباقتك مستمرة حتى', $text);
        $this->assertStringContainsString('خصمنا لك 20% من هذا التجديد: تدفع $7.20 بدلاً من $9.00.', $text);
        $this->assertStringContainsString('سيُلغى اشتراكك وينتقل حسابك إلى الباقة المجانية وينتهي هذا العرض.', $text);
        $this->assertMatchesRegularExpression('#Pay \$7\.20 now: https?://\S+/billing/pay/'.$case->id.'\?expires=\d+&lang=en&signature=[0-9a-f]+#', $text);
        $this->assertStringNotContainsString('Payment failed', (string) $email->getSubject());
        $this->assertStringNotContainsString('next renewal', $text);
        foreach (['{discount}', '{price}', '{old_price}', '{date}', '{next}', '{reason}', '{name}', '{plan}'] as $placeholder) {
            $this->assertStringNotContainsString($placeholder, $text.(string) $email->getHtmlBody());
        }
        $this->assertSame('Sent by hand', $case->fresh()->email_note);

        // One discount for a renewal.
        try {
            $this->followUps()->giveDiscount($case->fresh(), 10);
            $this->fail('A second discount was given.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('already has 20% off', $e->getMessage());
        }
        $this->assertCount(1, $stripe->lowered);

        // A member with no discount still gets the warning.
        $other = User::factory()->create(['name' => 'Omar', 'email' => 'omar@example.com', 'stripe_id' => 'cus_omar']);
        $this->followUps()->renewalFailed($other, $this->inGrace($other), $this->invoice('in_r9', 'subscription_cycle', 'cus_omar'));
        $warning = (string) $this->sentEmails()->last()->getOriginalMessage()->getTextBody();
        $this->assertStringContainsString('we tried to renew your Starter plan, but your bank declined the payment', $warning);
        $this->assertStringNotContainsString('% off', $warning);
    }

    public function test_a_discount_can_also_cover_that_members_next_renewals_and_the_email_says_so(): void
    {
        $plan = $this->inGrace($this->member);
        $this->followUps()->renewalFailed($this->member, $plan, $this->invoice('in_r1', 'subscription_cycle'));
        $case = PurchaseFollowUp::query()->sole();
        $stripe = $this->stripeOwes(900);

        $this->followUps()->giveDiscount($case, 20, 3);

        // On their own subscription, for three renewals, and this payment lowered too.
        $this->assertSame([['sub_1', 20, 3]], $stripe->renewals);
        $this->assertSame([['in_r1', 180, '20% off this renewal']], $stripe->lowered);
        $this->assertSame($stripe->keys[0], $stripe->keys[1], 'one key for the two halves of one discount');
        $this->assertSame([], $stripe->undone);
        $case->refresh();
        $this->assertSame([20, 180, 3, 'coupon_1'], [$case->discount_percent, $case->discount_cents, $case->discount_months, $case->discount_coupon_id]);

        app('mailer')->getSymfonyTransport()->flush();
        $this->followUps()->sendNow($case);
        $text = (string) $this->onlyEmail()->getTextBody();
        $this->assertStringContainsString('you pay $7.20 instead of $9.00. The same discount also comes off your next 3 renewals.', $text);
        $this->assertStringContainsString('تدفع $7.20 بدلاً من $9.00. ويُطبَّق الخصم نفسه على تجديداتك الـ3 القادمة أيضاً.', $text);
        $this->assertStringNotContainsString('{next}', $text);

        $this->assertSame(' The same discount also comes off your next renewal.', PurchaseFollowUps::nextRenewalsWords(1, 'en'));
        $this->assertSame(' ويُطبَّق الخصم نفسه على تجديدك القادم أيضاً.', PurchaseFollowUps::nextRenewalsWords(1, 'ar'));
        $this->assertStringContainsString('تجديدَيك القادمَين', PurchaseFollowUps::nextRenewalsWords(2, 'ar'));
        $this->assertSame('', PurchaseFollowUps::nextRenewalsWords(0, 'en'));
        $this->assertSame('', PurchaseFollowUps::nextRenewalsWords(null, 'ar'));
    }

    public function test_later_renewals_are_discounted_only_where_that_is_right_and_this_renewal_can_still_be(): void
    {
        $plan = $this->inGrace($this->member);
        $this->followUps()->renewalFailed($this->member, $plan, $this->invoice('in_r1', 'subscription_cycle'));
        $case = PurchaseFollowUp::query()->sole();
        $refused = function (int $months, string $why) use ($case): void {
            try {
                $this->followUps()->giveDiscount($case->fresh(), 20, $months);
                $this->fail('The discount was given: '.$why);
            } catch (\RuntimeException|\InvalidArgumentException $e) {
                $this->assertStringContainsString($why, $e->getMessage());
            }
        };

        // A yearly plan, a subscription that has ended, one with a discount already on it.
        $yearly = $this->stripeOwes(900, ['monthly' => false]);
        $refused(3, 'renews every month');
        $ended = $this->stripeOwes(900, ['open' => false]);
        $refused(3, 'has ended');
        $discounted = $this->stripeOwes(900, ['discounted' => true]);
        $refused(3, 'already has a discount running');
        $refused(13, 'up to 12 renewals');
        $refused(-1, 'up to 12 renewals');
        foreach ([$yearly, $ended, $discounted] as $stripe) {
            $this->assertSame([[], []], [$stripe->renewals, $stripe->lowered], 'nothing was changed');
        }
        $this->assertFalse($case->fresh()->hasDiscount());

        // This renewal only is still given on each of them.
        $this->stripeOwes(900, ['monthly' => false, 'discounted' => true]);
        $this->followUps()->giveDiscount($case->fresh(), 20);
        $this->assertSame([20, null], [$case->fresh()->discount_percent, $case->fresh()->discount_months]);
    }

    public function test_a_discount_is_both_or_neither_when_the_payment_cannot_be_lowered(): void
    {
        $plan = $this->inGrace($this->member);
        $this->followUps()->renewalFailed($this->member, $plan, $this->invoice('in_r1', 'subscription_cycle'));
        $case = PurchaseFollowUp::query()->sole();
        $stripe = $this->stripeOwes(900);
        $stripe->lowerFails = 'The invoice was paid a moment ago.';

        try {
            $this->followUps()->giveDiscount($case, 20, 3);
            $this->fail('The discount was given.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Nothing was changed', $e->getMessage());
            $this->assertStringContainsString('The invoice was paid a moment ago.', $e->getMessage());
        }
        // The months that were put on their renewals are taken back, and the case has no discount.
        $this->assertSame([['sub_1', 'coupon_1']], $stripe->undone);
        $this->assertFalse($case->fresh()->hasDiscount());
        $this->assertNull($case->fresh()->discount_coupon_id);

        // If even that cannot be done, the team is told exactly what is left to remove by hand.
        $stripe->undoFails = true;
        try {
            $this->followUps()->giveDiscount($case->fresh(), 20, 3);
            $this->fail('The discount was given.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('20% off their next 3 renewals was added and could not be taken back', $e->getMessage());
        }
        $this->assertFalse($case->fresh()->hasDiscount());
    }

    public function test_a_discount_is_taken_back_while_the_member_has_not_been_emailed_the_offer(): void
    {
        $plan = $this->inGrace($this->member);
        $this->followUps()->renewalFailed($this->member, $plan, $this->invoice('in_r1', 'subscription_cycle'));
        $case = PurchaseFollowUp::query()->sole();
        $stripe = $this->stripeOwes(900);
        // The warning went out when the renewal failed; the discount came later and was not emailed.
        $this->travel(2)->hours();
        $this->followUps()->giveDiscount($case, 20, 3);
        $this->assertTrue($case->hasDiscount());
        $this->assertFalse($case->offerEmailed());

        $this->assertTrue($this->followUps()->takeBackDiscount($case));

        // The payment is back at the full price and the months are off their subscription.
        $this->assertSame([['in_r1', 180]], $stripe->raised);
        $this->assertSame([['sub_1', 'coupon_1']], $stripe->undone);
        $case->refresh();
        $this->assertFalse($case->hasDiscount());
        $this->assertSame([null, null, null, null, null], [$case->discount_percent, $case->discount_cents, $case->discount_months, $case->discount_coupon_id, $case->discounted_at]);
        $this->assertSame([900, 900], [$case->amount_cents, $case->priceCents()]);

        // Their email is the warning again, at the full price.
        app('mailer')->getSymfonyTransport()->flush();
        $shown = $this->followUps()->emailFor($case);
        $this->assertStringStartsWith('Payment failed - action required', $shown['subject']);
        $this->assertStringNotContainsString('$7.20', $shown['text']);

        // Nothing is left to take back; and another discount can be given, as a change of its own.
        try {
            $this->followUps()->takeBackDiscount($case);
            $this->fail('A discount was taken back twice.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('has no discount', $e->getMessage());
        }
        $this->followUps()->giveDiscount($case, 10);
        $this->assertSame(['in_r1', 90, '10% off this renewal'], $stripe->lowered[1]);
        $this->assertNotSame($stripe->keys[0], end($stripe->keys), 'the second discount is not mistaken for the first');
        $this->assertSame([10, 810], [$case->fresh()->discount_percent, $case->fresh()->priceCents()]);
    }

    public function test_a_discount_stays_once_the_offer_was_emailed_or_the_renewal_paid(): void
    {
        $plan = $this->inGrace($this->member);
        $this->followUps()->renewalFailed($this->member, $plan, $this->invoice('in_r1', 'subscription_cycle'));
        $case = PurchaseFollowUp::query()->sole();
        $stripe = $this->stripeOwes(900);
        $this->travel(2)->hours();
        $this->followUps()->giveDiscount($case, 20);

        // Not on a server that is not the live site: it changes a real payment.
        config(['listen.live_actions' => false]);
        try {
            $this->followUps()->takeBackDiscount($case);
            $this->fail('A discount was taken back off the live site.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not the live site', $e->getMessage());
        }
        config(['listen.live_actions' => true]);

        // The provider refuses: nothing is changed, and the case keeps its discount.
        $stripe->raiseFails = 'The credit note cannot be voided.';
        try {
            $this->followUps()->takeBackDiscount($case);
            $this->fail('The discount was taken back.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('The credit note cannot be voided.', $e->getMessage());
            $this->assertStringContainsString('Nothing was changed', $e->getMessage());
        }
        $stripe->raiseFails = null;
        $this->assertTrue($case->fresh()->hasDiscount());

        // The member is told the lower price: from then on it is theirs.
        $this->travel(5)->minutes();
        $this->followUps()->sendNow($case);
        $this->assertTrue($case->fresh()->offerEmailed());
        try {
            $this->followUps()->takeBackDiscount($case);
            $this->fail('A discount the member was told of was taken back.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('the offer was emailed to the member', $e->getMessage());
        }
        $this->assertSame([], $stripe->raised);
        $this->assertSame([20, 720], [$case->fresh()->discount_percent, $case->fresh()->priceCents()]);

        // Paid at the lower price: nothing to take back.
        $other = User::factory()->create(['email' => 'omar@example.com', 'stripe_id' => 'cus_omar']);
        $this->followUps()->renewalFailed($other, $this->inGrace($other), $this->invoice('in_r2', 'subscription_cycle', 'cus_omar'));
        $second = PurchaseFollowUp::query()->where('user_id', $other->id)->sole();
        $this->travel(1)->hours();
        $this->followUps()->giveDiscount($second, 20);
        $second->forceFill(['paid_at' => now()])->save();
        try {
            $this->followUps()->takeBackDiscount($second);
            $this->fail('A paid renewal lost its discount.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('has been paid at the lower price', $e->getMessage());
        }
        $this->assertSame([], $stripe->raised);
    }

    public function test_the_team_is_told_when_the_months_could_not_be_taken_back_with_the_payment(): void
    {
        $plan = $this->inGrace($this->member);
        $this->followUps()->renewalFailed($this->member, $plan, $this->invoice('in_r1', 'subscription_cycle'));
        $case = PurchaseFollowUp::query()->sole();
        $stripe = $this->stripeOwes(900);
        $this->travel(2)->hours();
        $this->followUps()->giveDiscount($case, 20, 3);
        $stripe->undoFails = true;

        $this->assertFalse($this->followUps()->takeBackDiscount($case), 'the months are still on their subscription');

        // The payment is at the full price again, and the case no longer shows a discount.
        $this->assertSame([['in_r1', 180]], $stripe->raised);
        $this->assertFalse($case->fresh()->hasDiscount());
    }

    public function test_the_offer_for_a_plan_with_no_grace_days_says_the_paid_features_are_paused(): void
    {
        $plan = $this->inGrace($this->member, null);
        $this->followUps()->renewalFailed($this->member, $plan, $this->invoice('in_r1', 'subscription_cycle'));
        $case = PurchaseFollowUp::query()->sole();
        $this->stripeOwes(900);
        $this->followUps()->giveDiscount($case, 20);

        $text = $this->followUps()->emailFor($case->fresh())['text'];

        $this->assertStringContainsString('we could not take the payment for your Starter plan: your bank declined it, so your paid features are paused.', $text);
        $this->assertStringContainsString('we have taken 20% off this payment: you pay $7.20 instead of $9.00.', $text);
        $this->assertStringContainsString('لذلك توقفت مزاياك المدفوعة مؤقتاً', $text);
        $this->assertStringNotContainsString('stays on until', $text);
        $this->assertStringNotContainsString('{date}', $text);
    }

    public function test_saved_first_words_of_the_offer_move_to_the_new_ones_and_edited_words_stay(): void
    {
        $migration = require database_path('migrations/2026_11_30_120000_reword_the_renewal_discount_email.php');
        // A site that saved "Emails and settings" holds the first words (here with a browser's line endings).
        $first = "Hi {name}, we would like you to stay. We have taken {discount} off the renewal of your {plan} plan: you pay {price} instead of {old_price}.{next}\r\n\r\nPress the button below to pay the lower price and keep your plan, with every voice, dialect and feature in it.";
        SiteSetting::set('purchase_followup_renewal_offer_body_en', $first, 'purchase_followup');
        SiteSetting::set('purchase_followup_renewal_offer_cta_label_en', 'Pay {price}', 'purchase_followup');
        // One the team rewrote.
        SiteSetting::set('purchase_followup_renewal_offer_subject_en', 'A gift for you: {discount} off', 'purchase_followup');

        $migration->up();

        $settings = $this->followUps()->settings();
        $now = PurchaseFollowUps::defaults();
        $this->assertSame($now['renewal_offer_body_en'], $settings['renewal_offer_body_en']);
        $this->assertStringContainsString('your plan stays on until {date}', $settings['renewal_offer_body_en']);
        $this->assertSame('Pay {price} now', $settings['renewal_offer_cta_label_en']);
        $this->assertSame('A gift for you: {discount} off', $settings['renewal_offer_subject_en']);
        // Never saved: the new words apply by themselves.
        $this->assertSame($now['renewal_offer_headline_ar'], $settings['renewal_offer_headline_ar']);
    }

    public function test_the_discount_asks_the_payment_provider_for_exactly_these_changes(): void
    {
        // The real requests, against a stand-in for the provider's API: nothing leaves the machine.
        config(['cashier.secret' => 'sk_test_fake']);
        $stripe = new FakeStripeHttpClient;
        ApiRequestor::setHttpClient($stripe);
        try {
            $stripe->answer('get', '/v1/invoices/in_r1', [
                'id' => 'in_r1', 'object' => 'invoice', 'status' => 'open', 'currency' => 'usd', 'amount_due' => 900, 'amount_remaining' => 900,
                'parent' => ['type' => 'subscription_details', 'subscription_details' => ['subscription' => 'sub_1']],
            ]);
            $stripe->answer('get', '/v1/subscriptions/sub_1', [
                'id' => 'sub_1', 'object' => 'subscription', 'status' => 'past_due', 'discounts' => [],
                'items' => ['object' => 'list', 'data' => [[
                    'id' => 'si_1', 'object' => 'subscription_item',
                    'price' => ['id' => 'price_1', 'object' => 'price', 'recurring' => ['interval' => 'month', 'interval_count' => 1]],
                ]]],
            ]);
            $stripe->answer('post', '/v1/coupons', ['id' => 'co_1', 'object' => 'coupon']);
            $stripe->answer('post', '/v1/subscriptions/sub_1', ['id' => 'sub_1', 'object' => 'subscription', 'status' => 'past_due']);
            $stripe->answer('post', '/v1/credit_notes', ['id' => 'cn_1', 'object' => 'credit_note']);
            $stripe->answer('delete', '/v1/coupons/co_1', ['id' => 'co_1', 'object' => 'coupon', 'deleted' => true]);
            $plan = $this->inGrace($this->member);
            $this->followUps()->renewalFailed($this->member, $plan, $this->invoice('in_r1', 'subscription_cycle'));
            $this->followUps()->save(['discounts_enabled' => true]);
            $case = PurchaseFollowUp::query()->sole();
            $writes = fn (): array => array_values(array_map(
                fn (array $request): array => [$request['method'].' '.$request['path'], $request['params']],
                array_filter($stripe->requests, fn (array $request): bool => $request['method'] !== 'get'),
            ));

            // (The warning went out when the renewal failed; the discount is given later.)
            $this->travel(1)->hours();
            $this->followUps()->giveDiscount($case, 20, 3);

            $sent = $writes();
            $this->assertSame(['post /v1/coupons', 'post /v1/subscriptions/sub_1', 'post /v1/credit_notes'], array_column($sent, 0));
            // A coupon for this one case: 20% for three months, usable once.
            $this->assertSame([20, 'repeating', 3, 1], [$sent[0][1]['percent_off'], $sent[0][1]['duration'], $sent[0][1]['duration_in_months'], $sent[0][1]['max_redemptions']]);
            // On this member's own subscription, with nothing charged or credited for the change.
            $this->assertSame(['discounts' => [['coupon' => 'co_1']], 'proration_behavior' => 'none'], $sent[1][1]);
            // And the unpaid payment itself lowered by 20%, without the provider's own email to the member.
            $this->assertSame(['invoice' => 'in_r1', 'amount' => 180, 'memo' => '20% off this renewal', 'email_type' => 'none'], $sent[2][1]);
            $this->assertSame([20, 180, 3, 'co_1'], [$case->fresh()->discount_percent, $case->fresh()->discount_cents, $case->fresh()->discount_months, $case->fresh()->discount_coupon_id]);

            // Taken back before the member is told: the credit note on that payment is voided,
            // the discount comes off the subscription and its coupon is removed.
            $stripe->answer('get', '/v1/credit_notes', ['object' => 'list', 'has_more' => false, 'data' => [
                ['id' => 'cn_0', 'object' => 'credit_note', 'status' => 'void', 'amount' => 180],
                ['id' => 'cn_1', 'object' => 'credit_note', 'status' => 'issued', 'amount' => 180],
            ]]);
            $stripe->answer('post', '/v1/credit_notes/cn_1/void', ['id' => 'cn_1', 'object' => 'credit_note', 'status' => 'void']);
            $stripe->requests = [];
            $this->travel(1)->hours();
            $this->assertTrue($this->followUps()->takeBackDiscount($case));
            $asked = array_values(array_map(fn (array $request): array => [$request['method'].' '.$request['path'], $request['params']], $stripe->requests));
            $this->assertContains(['get /v1/credit_notes', ['invoice' => 'in_r1', 'limit' => 100]], $asked);
            $this->assertSame(['post /v1/credit_notes/cn_1/void', 'post /v1/subscriptions/sub_1', 'delete /v1/coupons/co_1'], array_column($writes(), 0));
            $this->assertSame(['discounts' => '', 'proration_behavior' => 'none'], $writes()[1][1]);
            $this->assertFalse($case->fresh()->hasDiscount());

            // The payment cannot be lowered (here: the provider refuses): the months are taken back.
            $other = User::factory()->create(['email' => 'omar@example.com', 'stripe_id' => 'cus_omar']);
            $this->followUps()->renewalFailed($other, $this->inGrace($other), $this->invoice('in_r2', 'subscription_cycle', 'cus_omar'));
            $second = PurchaseFollowUp::query()->where('user_id', $other->id)->sole();
            $stripe->answer('get', '/v1/invoices/in_r2', [
                'id' => 'in_r2', 'object' => 'invoice', 'status' => 'open', 'currency' => 'usd', 'amount_due' => 900, 'amount_remaining' => 900,
                'parent' => ['type' => 'subscription_details', 'subscription_details' => ['subscription' => 'sub_1']],
            ]);
            $stripe->answer('post', '/v1/credit_notes', ['error' => ['type' => 'invalid_request_error', 'message' => 'This invoice has a pending payment.']], 400);
            $stripe->requests = [];
            try {
                $this->followUps()->giveDiscount($second, 20, 3);
                $this->fail('The discount was given.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('This invoice has a pending payment.', $e->getMessage());
                $this->assertStringContainsString('Nothing was changed', $e->getMessage());
            }
            $sent = $writes();
            $this->assertSame(['post /v1/coupons', 'post /v1/subscriptions/sub_1', 'post /v1/credit_notes', 'post /v1/subscriptions/sub_1', 'delete /v1/coupons/co_1'], array_column($sent, 0));
            $this->assertSame(['discounts' => '', 'proration_behavior' => 'none'], $sent[3][1]);
            $this->assertFalse($second->fresh()->hasDiscount());
        } finally {
            ApiRequestor::setHttpClient(null);
        }
    }

    public function test_the_admin_sees_the_exact_email_a_member_gets_with_its_link_before_sending(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = User::factory()->create(['is_admin' => true]);
        $plan = $this->inGrace($this->member);
        $this->followUps()->renewalFailed($this->member, $plan, $this->invoice('in_r1', 'subscription_cycle'));
        $case = PurchaseFollowUp::query()->sole();
        $stripe = $this->stripeOwes(900);
        $this->followUps()->giveDiscount($case, 20, 3);
        $case->refresh();
        app('mailer')->getSymfonyTransport()->flush();

        // What the list shows is the very email that is then sent.
        $shown = $this->followUps()->emailFor($case);
        $this->assertSame('Your Starter renewal was declined: pay 20% less to keep your plan · تعذّر تجديد باقة مبتدئ: ادفع أقل بـ20% واحتفظ بها', $shown['subject']);
        $this->assertStringContainsString('you pay $7.20 instead of $9.00. The same discount also comes off your next 3 renewals.', $shown['text']);
        $this->assertMatchesRegularExpression('#^https?://\S+/billing/pay/'.$case->id.'\?expires=\d+&lang=en&signature=[0-9a-f]+$#', $shown['button_en']);
        $this->assertMatchesRegularExpression('#^https?://\S+/billing/pay/'.$case->id.'\?expires=\d+&lang=ar&signature=[0-9a-f]+$#', $shown['button_ar']);
        $this->assertStringContainsString(e($shown['button_en']), $shown['html']);
        $this->followUps()->sendNow($case);
        $sent = $this->onlyEmail();
        $this->assertSame($shown['subject'], (string) $sent->getSubject());
        // (The mailer ends a body with a line break of its own.)
        $this->assertSame(trim($shown['text']), trim((string) $sent->getTextBody()));
        $this->assertSame(trim($shown['html']), trim((string) $sent->getHtmlBody()));

        // The button's link works, and the list says where it leads at this moment.
        $this->invoiceIsOpenAt('https://invoice.stripe.com/i/acct_1/live_renewal');
        $this->get($shown['button_en'])->assertRedirect('https://invoice.stripe.com/i/acct_1/live_renewal');
        $this->assertSame('https://invoice.stripe.com/i/acct_1/live_renewal', $this->followUps()->buttonLeadsTo($case)['url']);
        $this->invoiceIsOpenAt(null);
        $this->assertSame('https://listenai.studio/en/manage-subscription', $this->followUps()->buttonLeadsTo($case)['url']);

        // In the list: one click, nothing sent.
        app('mailer')->getSymfonyTransport()->flush();
        Livewire::actingAs($admin)->test(ListPurchaseFollowUps::class)
            ->assertTableActionVisible('seeEmail', $case)
            ->mountTableAction('seeEmail', $case);
        $popup = PurchaseFollowUpResource::emailPreview($case)->toHtml();
        $this->assertStringContainsString('sara@example.com', $popup);
        $this->assertStringContainsString('Your Starter renewal was declined: pay 20% less to keep your plan', $popup);
        $this->assertStringContainsString('20% off: $7.20 instead of $9.00, and off the next 3 renewals', $popup);
        $this->assertStringContainsString('/billing/pay/'.$case->id.'?expires=', $popup);
        $this->assertStringContainsString('Their own subscription page', $popup);
        $this->assertStringContainsString('https://listenai.studio/en/manage-subscription', $popup);
        // The email itself is in it, as its member reads it.
        $this->assertStringContainsString('you pay $7.20 instead of $9.00', html_entity_decode($popup));
        $this->assertCount(0, $this->sentEmails()->filter(fn ($sent): bool => str_contains((string) $sent->getOriginalMessage()->getSubject(), 'renewal')));
    }

    public function test_discounts_are_off_until_the_owner_switches_them_on(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = User::factory()->create(['is_admin' => true]);
        $plan = $this->inGrace($this->member);
        $this->followUps()->renewalFailed($this->member, $plan, $this->invoice('in_r1', 'subscription_cycle'));
        $case = PurchaseFollowUp::query()->sole();
        $stripe = $this->stripeOwes(900);
        $this->followUps()->save(['discounts_enabled' => false]);

        $this->assertFalse($this->followUps()->discountsOn());
        Livewire::actingAs($admin)->test(ListPurchaseFollowUps::class)
            ->assertTableActionHidden('discount', $case)
            ->assertTableActionVisible('emailNow', $case);
        try {
            $this->followUps()->giveDiscount($case, 20);
            $this->fail('A discount was given while they are switched off.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('switched off', $e->getMessage());
        }
        $this->assertSame([[], []], [$stripe->lowered, $stripe->renewals]);

        // Switched on from the emails' settings page.
        Livewire::actingAs($admin)->test(PurchaseFollowUpEmails::class)
            ->assertFormSet(['discounts_enabled' => false])
            ->fillForm(['discounts_enabled' => true])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertTrue($this->followUps()->discountsOn());
        Livewire::actingAs($admin)->test(ListPurchaseFollowUps::class)->assertTableActionVisible('discount', $case);
    }

    public function test_two_events_for_the_same_declined_renewal_warn_the_member_once(): void
    {
        $plan = $this->inGrace($this->member);
        // The other event is sending the warning this very moment.
        $this->app->bind(PurchaseFollowUps::class, fn () => new class extends PurchaseFollowUps
        {
            protected const RENEWAL_LOCK_SECONDS = 0;
        });
        $busy = Cache::lock('purchase_followup:renewal_warning:'.$this->member->id, 60);
        $this->assertTrue($busy->get());

        app(RenewalGrace::class)->warn($this->member, $plan, $this->invoice('in_r1', 'subscription_cycle'));

        // This one sends nothing, neither the designed email nor the plain one, and marks nothing.
        $this->assertCount(0, $this->sentEmails());
        $this->assertSame(0, PurchaseFollowUp::query()->count());
        $this->assertNull($plan->fresh()->billing_failed_notice_sent_at);

        // The first one is done (here: it never sent): the next event warns, once.
        $busy->release();
        app(RenewalGrace::class)->warn($this->member, $plan->fresh(), $this->invoice('in_r1', 'subscription_cycle'));
        app(RenewalGrace::class)->warn($this->member, $plan->fresh(), $this->invoice('in_r1', 'subscription_cycle'));
        $this->assertCount(1, $this->sentEmails());
        $this->assertSame(1, PurchaseFollowUp::query()->count());
        $this->assertNotNull($plan->fresh()->billing_failed_notice_sent_at);
    }

    public function test_a_discount_is_refused_when_it_cannot_be_given_and_nothing_changes(): void
    {
        $plan = $this->inGrace($this->member);
        $this->followUps()->renewalFailed($this->member, $plan, $this->invoice('in_r1', 'subscription_cycle'));
        $case = PurchaseFollowUp::query()->sole();
        $refused = function (PurchaseFollowUp $case, int $percent, string $why): void {
            try {
                $this->followUps()->giveDiscount($case, $percent);
                $this->fail('The discount was given: '.$why);
            } catch (\RuntimeException|\InvalidArgumentException $e) {
                $this->assertStringContainsString($why, $e->getMessage());
            }
        };

        // The payment was paid or closed at Stripe meanwhile.
        $stripe = $this->stripeOwes(null);
        $refused($case, 20, 'no longer waiting to be paid');

        $stripe = $this->stripeOwes(900);
        $refused($case, 0, 'between 1 and 90');
        $refused($case, 95, 'between 1 and 90');

        // Staging holds a copy of live members and live's Stripe keys: never there.
        config(['listen.live_actions' => false]);
        $refused($case, 20, 'not the live site');
        config(['listen.live_actions' => true]);

        // A first payment is not a renewal, and a paid renewal needs no discount.
        $first = PurchaseFollowUp::query()->create(['user_id' => $this->member->id, 'kind' => PurchaseFollowUp::KIND_PAYMENT_FAILED, 'stripe_invoice_id' => 'in_1', 'amount_cents' => 900]);
        $refused($first, 20, 'for a declined renewal');
        $case->forceFill(['paid_at' => now()])->save();
        $refused($case, 20, 'has been paid');

        $this->assertSame([], $stripe->lowered);
        $this->assertFalse($case->fresh()->hasDiscount());
    }

    public function test_the_admin_gives_a_discount_and_sends_an_email_by_hand_from_the_list(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = User::factory()->create(['is_admin' => true]);
        $plan = $this->inGrace($this->member);
        // On staging the warning did not go out; the case is listed all the same.
        config(['listen.live_actions' => false]);
        app(RenewalGrace::class)->warn($this->member, $plan, $this->invoice('in_r1', 'subscription_cycle'));
        config(['listen.live_actions' => true]);
        $case = PurchaseFollowUp::query()->sole();
        $this->assertSame(PurchaseFollowUp::SKIPPED, $case->email_status);
        $stripe = $this->stripeOwes(900);

        // Sent by hand: the warning, and the automatic one is not owed any more.
        Livewire::actingAs($admin)->test(ListPurchaseFollowUps::class)
            ->assertTableActionVisible('emailNow', $case)
            ->assertTableActionVisible('discount', $case)
            ->callTableAction('emailNow', $case)
            ->assertNotified('Email sent to sara@example.com');
        // (An admin page's own request also lets the paid plan's welcome email go out: not this test's.)
        $warnings = $this->sentEmails()->filter(fn ($sent): bool => str_starts_with((string) $sent->getOriginalMessage()->getSubject(), 'Payment failed'));
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('we tried to renew your Starter plan', (string) $warnings->first()->getOriginalMessage()->getTextBody());
        $this->assertSame(PurchaseFollowUp::SENT, $case->fresh()->email_status);
        $this->assertNotNull($plan->fresh()->billing_failed_notice_sent_at);

        // A discount, emailed at once.
        Livewire::actingAs($admin)->test(ListPurchaseFollowUps::class)
            ->callTableAction('discount', $case, ['percent' => 25, 'scope' => 'months', 'months' => 2, 'email' => true])
            ->assertHasNoTableActionErrors()
            ->assertNotified('Discount given and emailed')
            ->assertSee('25% off: $6.75 instead of $9.00, and off the next 2 renewals')
            ->assertTableActionHidden('discount', $case->fresh());
        $this->assertSame([['in_r1', 225, '25% off this renewal']], $stripe->lowered);
        $this->assertSame([['sub_1', 25, 2]], $stripe->renewals);
        $offers = $this->sentEmails()->filter(fn ($sent): bool => str_starts_with((string) $sent->getOriginalMessage()->getSubject(), 'Your Starter renewal was declined: pay 25% less'));
        $this->assertCount(1, $offers);
        $this->assertStringContainsString('you pay $6.75 instead of $9.00. The same discount also comes off your next 2 renewals.', (string) $offers->first()->getOriginalMessage()->getTextBody());

        // Emailed: the lower price is the member's, so the list does not take it back.
        Livewire::actingAs($admin)->test(ListPurchaseFollowUps::class)
            ->assertTableActionVisible('takeBackDiscount', $case->fresh())
            ->assertTableActionDisabled('takeBackDiscount', $case->fresh());

        // A discount given without an email can be taken back from the list, and given again.
        $other = User::factory()->create(['email' => 'omar@example.com', 'stripe_id' => 'cus_omar']);
        $this->followUps()->renewalFailed($other, $this->inGrace($other), $this->invoice('in_r2', 'subscription_cycle', 'cus_omar'));
        $second = PurchaseFollowUp::query()->where('user_id', $other->id)->sole();
        $this->travel(1)->hours();
        Livewire::actingAs($admin)->test(ListPurchaseFollowUps::class)
            ->assertTableActionHidden('takeBackDiscount', $second)
            ->callTableAction('discount', $second, ['percent' => 20, 'scope' => 'once', 'email' => false])
            ->assertNotified('Discount given')
            ->assertTableActionEnabled('takeBackDiscount', $second->fresh())
            ->callTableAction('takeBackDiscount', $second->fresh())
            ->assertNotified('Discount taken back')
            ->assertTableActionHidden('takeBackDiscount', $second->fresh())
            ->assertTableActionVisible('discount', $second->fresh());
        $this->assertSame([['in_r2', 180]], $stripe->raised);
        $this->assertFalse($second->fresh()->hasDiscount());

        // The offer's words are edited with the others, and previewed with a sample discount.
        Livewire::actingAs($admin)->test(PurchaseFollowUpEmails::class)
            ->assertFormSet(['renewal_offer_cta_label_en' => 'Pay {price} now'])
            ->fillForm(['renewal_offer_headline_en' => 'A gift: {discount} off {plan}'])
            ->call('save')
            ->assertHasNoFormErrors();
        $preview = $this->followUps()->previewHtml(PurchaseFollowUp::KIND_RENEWAL_FAILED, null, true);
        $this->assertStringContainsString('A gift: 20% off Starter', $preview);
        $this->assertStringContainsString('you pay $7.20 instead of $9.00. The same discount also comes off your next 3 renewals.', $preview);

        // Paid: nothing more to do on the case.
        $case->forceFill(['paid_at' => now()])->save();
        Livewire::actingAs($admin)->test(ListPurchaseFollowUps::class)
            ->assertTableActionHidden('emailNow', $case->fresh())
            ->assertTableActionHidden('discount', $case->fresh());
    }

    public function test_members_warned_before_the_list_existed_are_added_to_it_once(): void
    {
        $plan = $this->inGrace($this->member);
        $plan->forceFill(['billing_failed_notice_sent_at' => now()->subHours(3)])->save();
        $ok = $this->paidMember();
        $migration = require database_path('migrations/2026_11_29_120000_list_renewals_already_warned.php');

        $migration->up();
        $migration->up();

        $case = PurchaseFollowUp::query()->sole();
        $this->assertSame([$this->member->id, PurchaseFollowUp::KIND_RENEWAL_FAILED, 'starter', PurchaseFollowUp::SENT], [$case->user_id, $case->kind, $case->plan_slug, $case->email_status]);
        $this->assertEquals($plan->fresh()->billing_failed_notice_sent_at, $case->email_sent_at);
        $this->assertSame(0, PurchaseFollowUp::query()->where('user_id', $ok->id)->count());
    }

    public function test_a_renewal_listed_from_before_the_list_is_given_its_unpaid_payment_when_the_list_opens(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = User::factory()->create(['is_admin' => true]);
        $plan = $this->inGrace($this->member);
        $plan->forceFill(['billing_failed_notice_sent_at' => now()->subHours(3)])->save();
        (require database_path('migrations/2026_11_29_120000_list_renewals_already_warned.php'))->up();
        $case = PurchaseFollowUp::query()->sole();
        $this->assertNull($case->stripe_invoice_id);
        BillingSubscription::query()->create([
            'user_id' => $this->member->id, 'type' => 'default', 'stripe_id' => 'sub_sara',
            'stripe_status' => 'past_due', 'stripe_price' => 'price_starter_m', 'quantity' => 1,
        ]);
        // A member whose subscription is waiting on nothing (it was paid since) is left as it is.
        $paid = User::factory()->create(['stripe_id' => 'cus_paid']);
        $other = PurchaseFollowUp::query()->create(['user_id' => $paid->id, 'kind' => PurchaseFollowUp::KIND_RENEWAL_FAILED, 'happened_at' => now(), 'email_status' => PurchaseFollowUp::SENT]);
        $this->app->bind(PurchaseFollowUps::class, fn () => new class extends PurchaseFollowUps
        {
            protected function unpaidPaymentOf(string $subscriptionId): ?array
            {
                return $subscriptionId === 'sub_sara' ? ['invoice' => 'in_waiting', 'amount' => 900, 'currency' => 'usd'] : null;
            }
        });

        Livewire::actingAs($admin)->test(ListPurchaseFollowUps::class)->assertCanSeeTableRecords([$case, $other]);

        $case->refresh();
        $this->assertSame(['in_waiting', 900, 'usd'], [$case->stripe_invoice_id, $case->amount_cents, $case->currency]);
        $this->assertNull($other->fresh()->stripe_invoice_id);
        // Its button now opens that payment, by the member's own signed link.
        $this->assertStringContainsString('/billing/pay/'.$case->id.'?expires=', $this->followUps()->emailFor($case)['button_en']);
        $this->assertSame(0, $this->followUps()->nameUnpaidPayments(), 'named once');
    }

    public function test_the_emails_name_no_other_company(): void
    {
        foreach (PurchaseFollowUps::defaults() as $value) {
            $this->assertDoesNotMatchRegularExpression('/stripe|google|gemini|visa|mastercard|paypal/i', $value);
        }
    }

    // ──────────────────────────────────────────────────────────────────

    /** The checkout page as a member opens it, with the plan and cycle in the address. */
    private function openCheckout(User $user, string $query): void
    {
        $this->actingAs($user)->withoutMiddleware([
            LocaleSessionRedirect::class,
            LaravelLocalizationRedirectFilter::class,
            LaravelLocalizationViewPath::class,
        ])->get($this->localizedRoute('billing.page').($query !== '' ? '?'.$query : ''))->assertOk();
    }

    private function followUps(): PurchaseFollowUps
    {
        return app(PurchaseFollowUps::class);
    }

    /** What Stripe answers about the declined payment's invoice: its payment page while open, or null once closed. */
    private function invoiceIsOpenAt(?string $url): void
    {
        $this->app->bind(PurchaseFollowUps::class, fn () => new class($url) extends PurchaseFollowUps
        {
            public function __construct(private ?string $openAt) {}

            protected function openInvoiceUrl(string $invoiceId): ?string
            {
                return $this->openAt;
            }
        });
    }

    /**
     * Stripe for giveDiscount(): what is still owed on the payment (null once it is paid or
     * closed) and the state of its subscription. Everything asked of it is kept on the
     * returned object: $lowered (the payment), $renewals (a discount on later renewals) and
     * $undone (one taken back), $raised (a payment put back to the full price) and $keys.
     * $lowerFails / $undoFails / $raiseFails make those calls fail.
     */
    private function stripeOwes(?int $cents, array $subscription = []): object
    {
        $stripe = new class
        {
            /** @var list<array{0: string, 1: int, 2: string}> */
            public array $lowered = [];

            /** @var list<array{0: string, 1: int, 2: int}> */
            public array $renewals = [];

            /** @var list<string> the key each change was sent with */
            public array $keys = [];

            /** @var list<array{0: string, 1: int}> payments put back to the full price */
            public array $raised = [];

            public ?string $raiseFails = null;

            /** @var list<array{0: string, 1: string}> */
            public array $undone = [];

            public ?string $lowerFails = null;

            public bool $undoFails = false;

            /** @var array{open: bool, monthly: bool, discounted: bool}|null */
            public ?array $subscription = null;
        };
        $stripe->subscription = $subscription + ['open' => true, 'monthly' => true, 'discounted' => false];
        // The owner has switched discounts on.
        app(PurchaseFollowUps::class)->save(['discounts_enabled' => true]);
        $this->app->bind(PurchaseFollowUps::class, fn () => new class($cents, $stripe) extends PurchaseFollowUps
        {
            public function __construct(private ?int $owed, private object $stripe) {}

            protected function unpaidInvoice(string $invoiceId): ?array
            {
                return $this->owed !== null ? ['amount' => $this->owed, 'currency' => 'usd', 'subscription' => 'sub_1'] : null;
            }

            protected function subscriptionState(string $subscriptionId): ?array
            {
                return $this->stripe->subscription;
            }

            protected function lowerInvoice(string $invoiceId, int $offCents, string $memo, string $idempotencyKey): void
            {
                if ($this->stripe->lowerFails !== null) {
                    throw new \RuntimeException($this->stripe->lowerFails);
                }
                $this->stripe->keys[] = $idempotencyKey;
                $this->stripe->lowered[] = [$invoiceId, $offCents, $memo];
            }

            protected function discountRenewals(string $subscriptionId, int $percent, int $months, string $idempotencyKey): string
            {
                $this->stripe->keys[] = $idempotencyKey;
                $this->stripe->renewals[] = [$subscriptionId, $percent, $months];

                return 'coupon_'.count($this->stripe->renewals);
            }

            protected function raiseInvoice(string $invoiceId, int $offCents): bool
            {
                if ($this->stripe->raiseFails !== null) {
                    throw new \RuntimeException($this->stripe->raiseFails);
                }
                $this->stripe->raised[] = [$invoiceId, $offCents];

                return true;
            }

            protected function undiscountRenewals(string $subscriptionId, string $couponId): bool
            {
                if ($this->stripe->undoFails) {
                    throw new \RuntimeException('The subscription could not be changed.');
                }
                $this->stripe->undone[] = [$subscriptionId, $couponId];

                return true;
            }
        });

        return $stripe;
    }

    private function turnOn(): void
    {
        $this->followUps()->save(['failed_enabled' => true, 'left_enabled' => true]);
    }

    private function invoice(string $id = 'in_1', string $reason = 'subscription_create', string $customer = 'cus_sara'): object
    {
        return (object) ['id' => $id, 'customer' => $customer, 'billing_reason' => $reason, 'amount_due' => 900, 'currency' => 'usd'];
    }

    /**
     * The member's paid plan as RenewalGrace leaves it when a renewal is declined: behind on
     * payment, kept on for the grace days (or, with $days null, with none).
     */
    private function inGrace(User $user, ?int $days = 7): Subscription
    {
        $this->givePaidPlan($user);
        $plan = Subscription::query()->where('user_id', $user->id)->sole();
        $plan->forceFill([
            'billing_payment_state' => 'past_due',
            'billing_grace_ends_at' => $days !== null ? now()->addDays($days) : null,
            'billing_failed_notice_sent_at' => null,
        ])->save();

        return $plan;
    }

    private function paidMember(): User
    {
        $user = User::factory()->create();
        $this->givePaidPlan($user);

        return $user;
    }

    private function givePaidPlan(User $user): void
    {
        Subscription::query()->where('user_id', $user->id)->delete();
        Subscription::query()->create([
            'user_id' => $user->id,
            'plan_id' => $this->starter->id,
            'status' => 'active',
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);
    }

    /**
     * @return Collection<int, SentMessage>
     */
    private function sentEmails(): Collection
    {
        return app('mailer')->getSymfonyTransport()->messages();
    }

    private function onlyEmail(): Email
    {
        $this->assertCount(1, $this->sentEmails());

        return $this->sentEmails()->first()->getOriginalMessage();
    }
}
