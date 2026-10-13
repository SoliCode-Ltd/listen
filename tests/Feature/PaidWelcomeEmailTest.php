<?php

namespace Tests\Feature;

use App\Filament\Pages\PaidWelcomeEmail as WelcomePage;
use App\Filament\Resources\SubscriptionResource\Pages\ListSubscriptions;
use App\Mail\PaidWelcomeMail as NewsletterMail;
use App\Models\BillingTrial;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Newsletter\PaidWelcomeEmail;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class PaidWelcomeEmailTest extends TestCase
{
    use RefreshDatabase;

    private Plan $free;

    private Plan $paid;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->free = Plan::factory()->create(['slug' => 'free', 'price_cents' => 0]);
        $this->paid = Plan::factory()->create(['slug' => 'creator', 'price_cents' => 1900, 'name_en' => null, 'name_ar' => null]);
    }

    public function test_a_new_paid_member_gets_the_welcome_email_once(): void
    {
        $user = User::factory()->create(['name' => 'Sara Ali', 'email' => 'sara@example.test']);

        $subscription = Subscription::create(['user_id' => $user->id, 'plan_id' => $this->paid->id, 'status' => 'active']);
        $this->app->terminate();

        Mail::assertSent(NewsletterMail::class, function (NewsletterMail $mail): bool {
            return $mail->hasTo('sara@example.test')
                && str_contains($mail->htmlBody, PaidWelcomeEmail::REVIEW_URL)
                && str_contains($mail->htmlBody, 'Sara')
                && str_contains($mail->htmlBody, 'أهلاً بك')
                // The member's own plan, named in each language.
                && str_contains($mail->htmlBody, 'Welcome to Creator, Sara!')
                && str_contains($mail->htmlBody, 'باقة مُبدع')
                && str_contains($mail->subjectLine, 'Listen Creator')
                && ! str_contains($mail->htmlBody, '{plan}')
                && $mail->unsubscribeUrl === null;
        });
        $this->assertNotNull($user->fresh()->paid_welcome_sent_at);

        // Saving the subscription again, or renewing, does not send it again.
        $subscription->update(['current_period_end' => now()->addMonth()]);
        $this->app->terminate();
        Mail::assertSent(NewsletterMail::class, 1);
    }

    public function test_free_plans_and_unpaid_trials_get_nothing_until_the_trial_is_paid(): void
    {
        $freeUser = User::factory()->create();
        Subscription::create(['user_id' => $freeUser->id, 'plan_id' => $this->free->id, 'status' => 'active']);

        $trialUser = User::factory()->create(['email' => 'trial@example.test']);
        Subscription::create(['user_id' => $trialUser->id, 'plan_id' => $this->paid->id, 'status' => 'active']);
        $trial = BillingTrial::query()->create([
            'user_id' => $trialUser->id,
            'plan_id' => $this->paid->id,
            'status' => BillingTrial::STATUS_TRIALING,
            'stripe_subscription_id' => 'sub_trial',
            'amount_cents' => 1900,
            'started_at' => now()->subDay(),
            'trial_ends_at' => now()->addDays(6),
        ]);
        $this->app->terminate();
        Mail::assertNothingSent();

        $trial->forceFill(['status' => BillingTrial::STATUS_PAID, 'paid_at' => now()])->save();
        $this->app->terminate();
        Mail::assertSent(NewsletterMail::class, fn (NewsletterMail $mail): bool => $mail->hasTo('trial@example.test'));
    }

    public function test_existing_paid_members_are_not_emailed_when_their_subscription_renews(): void
    {
        $user = User::factory()->create();
        $subscription = Subscription::withoutEvents(fn () => Subscription::create(['user_id' => $user->id, 'plan_id' => $this->paid->id, 'status' => 'active']))->fresh();

        $subscription->update(['current_period_end' => now()->addMonth(), 'billing_payment_state' => 'paid']);
        $this->app->terminate();
        Mail::assertNothingSent();

        // Moving them to another paid plan by hand does count as starting a paid plan.
        $other = Plan::factory()->create(['slug' => 'studio', 'price_cents' => 2900]);
        $subscription->update(['plan_id' => $other->id]);
        $this->app->terminate();
        Mail::assertSent(NewsletterMail::class, 1);
    }

    public function test_switching_it_off_stops_automatic_sends(): void
    {
        app(PaidWelcomeEmail::class)->save(['enabled' => false]);
        $user = User::factory()->create();

        Subscription::create(['user_id' => $user->id, 'plan_id' => $this->paid->id, 'status' => 'active']);
        $this->app->terminate();

        Mail::assertNothingSent();
    }

    public function test_the_admin_edits_the_words_and_sends_it_by_hand(): void
    {
        $member = User::factory()->create(['email' => 'member@example.test']);
        $member->forceFill(['paid_welcome_sent_at' => now()->subWeek()])->saveQuietly();
        Subscription::create(['user_id' => $member->id, 'plan_id' => $this->paid->id, 'status' => 'active']);
        $this->app->terminate();
        Mail::assertNothingSent();

        $this->actingAs(User::factory()->create(['is_admin' => true, 'email' => 'owner@example.test']));
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(WelcomePage::class)
            ->set('data.subject_en', 'Thanks for joining us')
            ->set('data.body_en', 'Your new plan is ready.')
            ->call('save')
            ->callAction('sendTest', ['email' => 'owner@example.test'])
            ->callAction('sendToMember', ['user_id' => $member->id]);

        Mail::assertSent(NewsletterMail::class, fn (NewsletterMail $mail): bool => $mail->hasTo('owner@example.test')
            && str_contains($mail->subjectLine, 'Thanks for joining us'));
        Mail::assertSent(NewsletterMail::class, fn (NewsletterMail $mail): bool => $mail->hasTo('member@example.test')
            && str_contains($mail->htmlBody, 'Your new plan is ready.'));
        $this->assertTrue($member->fresh()->paid_welcome_sent_at->isToday());

        // The subscriptions list shows the email and can send it from the row too.
        $subscription = Subscription::query()->where('user_id', $member->id)->where('plan_id', $this->paid->id)->first();
        Livewire::test(ListSubscriptions::class)
            ->assertTableColumnStateSet('user.email', 'member@example.test', $subscription)
            ->searchTable('member@example')
            ->assertCanSeeTableRecords([$subscription])
            ->callTableAction('sendWelcome', $subscription);
        Mail::assertSent(NewsletterMail::class, fn (NewsletterMail $mail): bool => $mail->hasTo('member@example.test'));
        Mail::assertSent(NewsletterMail::class, 3);
    }

    public function test_a_plan_name_set_in_the_admin_is_used_and_a_missing_one_leaves_no_gap(): void
    {
        $plan = new Plan(['slug' => 'gold', 'name' => 'Gold', 'name_en' => 'Gold Plus', 'name_ar' => 'الذهبية']);
        $this->assertSame('Welcome to Gold Plus!', PaidWelcomeEmail::withPlan('Welcome to {plan}!', $plan, 'en'));
        $this->assertSame('أهلاً بك في باقة الذهبية', PaidWelcomeEmail::withPlan('أهلاً بك في باقة {plan}', $plan, 'ar'));
        $this->assertSame('Thanks for choosing the plan.', PaidWelcomeEmail::withPlan('Thanks for choosing the {plan} plan.', null, 'en'));
    }

    public function test_the_page_sits_under_newsletter(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));

        $this->get(WelcomePage::getUrl(panel: 'admin'))->assertOk()->assertSee('Welcome email for paid members');
        $this->assertSame('Newsletter', WelcomePage::getNavigationGroup());
    }
}
