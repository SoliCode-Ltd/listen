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
        $this->assertStringContainsString('Complete my payment: https://listenai.studio/en/billing?plan=starter&billing_cycle=monthly', $text);

        // The checkout's reminder never follows it.
        $this->travel(1)->days();
        $this->followUps()->run();
        $this->assertCount(1, $this->sentEmails());
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
            ->assertSee('Payment declined')
            ->assertSee('Not enough money on the card')
            ->assertSee('Sent')
            ->assertSee('Payments declined: 1 (1 emailed, 0 bought after; email on)')
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

    private function turnOn(): void
    {
        $this->followUps()->save(['failed_enabled' => true, 'left_enabled' => true]);
    }

    private function invoice(string $id = 'in_1', string $reason = 'subscription_create', string $customer = 'cus_sara'): object
    {
        return (object) ['id' => $id, 'customer' => $customer, 'billing_reason' => $reason, 'amount_due' => 900, 'currency' => 'usd'];
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
