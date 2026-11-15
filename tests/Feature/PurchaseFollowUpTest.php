<?php

namespace Tests\Feature;

use App\Filament\Resources\PurchaseFollowUpResource;
use App\Filament\Resources\PurchaseFollowUpResource\Pages\ListPurchaseFollowUps;
use App\Filament\Resources\PurchaseFollowUpResource\Pages\PurchaseFollowUpEmails;
use App\Models\Plan;
use App\Models\PurchaseFollowUp;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\PurchaseFollowUps;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
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
        app(\App\Services\Billing\RenewalGrace::class)->settle($this->member);
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

        app(\App\Services\Billing\RenewalGrace::class)->warn($this->member, $plan, $this->invoice('in_r1', 'subscription_cycle'));

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
        app(\App\Services\Billing\RenewalGrace::class)->warn($this->member, $plan, $this->invoice('in_r1', 'subscription_cycle'));

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
            \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath::class,
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
