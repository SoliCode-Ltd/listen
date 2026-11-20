<?php

namespace Tests\Feature;

use App\Filament\Pages\CreditsEndedEmailPage;
use App\Mail\CreditsEndedMail;
use App\Models\BillingSubscription;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Voice;
use App\Services\Billing\ExtraCredits;
use App\Services\Newsletter\CreditsEndedEmail;
use App\Services\PlanAccessService;
use App\Support\CreditPeriod;
use App\Support\VoiceCatalog;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Feature\Api\InteractsWithDeveloperApi;
use Tests\TestCase;

/**
 * A paid member whose credits run out is told by email, once for the credit
 * period, wherever they ran out (App\Services\Newsletter\CreditsEndedEmail): its
 * button opens the studio with the extra-credits dialog. Nobody who cannot buy
 * extra credits gets it.
 */
class CreditsEndedEmailTest extends TestCase
{
    use InteractsWithDeveloperApi;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDeveloperApi();
        SiteSetting::set(ExtraCredits::ENABLED_SETTING, '1', 'billing');
        Mail::fake();
    }

    public function test_a_refused_voice_with_no_credits_left_sends_it_once_for_the_period(): void
    {
        $user = $this->apiMember();
        CreditPeriod::counter($user->id)->update(['total_chars' => 90000]);

        $this->studio($user)->assertStatus(422)->assertJsonPath('out_of_credits', true);
        $this->studio($user)->assertStatus(422);

        Mail::assertSent(CreditsEndedMail::class, 1);
        Mail::assertSent(CreditsEndedMail::class, function (CreditsEndedMail $mail) use ($user): bool {
            $html = $mail->render();

            return $mail->hasTo($user->email)
                && str_contains($mail->subjectLine, 'Creator')
                && str_contains($html, '/en/dashboard?extra_credits=1')
                && str_contains($html, '/ar/dashboard?extra_credits=1')
                && str_contains($html, 'Add credits')
                && str_contains($html, 'أضف رصيداً')
                // The day the plan's credits renew.
                && str_contains($html, CreditPeriod::current($user->id)['ends_at']->format('j F'));
        });
        $this->assertNotNull(CreditPeriod::counter($user->id)->credits_ended_email_at);
    }

    public function test_spending_the_last_credits_sends_it_and_so_does_the_developer_api(): void
    {
        $this->audioSeconds = 4;
        $user = $this->apiMember();
        $plain = $this->issueKey($user)['plain'];
        CreditPeriod::counter($user->id)->update(['total_chars' => 90000 - 60]);

        $voice = $this->premiumVoice();

        // The last 60 credits are spent by this voice: less than a minute is left.
        $this->speak($plain, $voice)->assertOk();
        Mail::assertSent(CreditsEndedMail::class, 1);

        // Refused after that: not told twice.
        $this->assertApiError($this->speak($plain, $voice), 402, 'quota_exceeded');
        Mail::assertSent(CreditsEndedMail::class, 1);
    }

    public function test_it_is_sent_again_only_after_extra_credits_were_added_and_used_up(): void
    {
        $user = $this->apiMember();
        CreditPeriod::counter($user->id)->update(['total_chars' => 90000]);
        $this->studio($user)->assertStatus(422);
        Mail::assertSent(CreditsEndedMail::class, 1);

        $extra = app(ExtraCredits::class);
        $extra->markPaid($extra->startPurchase($user, 10, 'card', 200, 'USD'));
        $this->assertNull(CreditPeriod::counter($user->id)->credits_ended_email_at);

        CreditPeriod::counter($user->id)->update(['total_chars' => 90000 + 9000]);
        $this->studio($user)->assertStatus(422);
        Mail::assertSent(CreditsEndedMail::class, 2);
    }

    public function test_nobody_who_cannot_buy_extra_credits_gets_it(): void
    {
        $plan = $this->apiPlan();

        // Plenty left, only not enough for this text: the credits have not run out.
        $short = $this->apiMember($plan);
        CreditPeriod::counter($short->id)->update(['total_chars' => 90000 - 1000]);
        $this->studio($short, str_repeat('a', 1500))->assertStatus(422);

        // A free member.
        $free = User::factory()->create(['subscription_status_checked_at' => now()]);
        app(PlanAccessService::class)->assignPlan($free, Plan::factory()->create(['slug' => 'free', 'price_cents' => 0, 'monthly_chars' => 3000, 'monthly_requests' => 0, 'voice_tier' => 'hd']));
        CreditPeriod::counter($free->id)->update(['total_chars' => 3000]);
        $this->studio($free)->assertStatus(422);

        // A renewal that is overdue.
        $overdue = $this->apiMember($plan);
        Subscription::query()->where('user_id', $overdue->id)->update(['billing_payment_state' => 'past_due', 'billing_grace_ends_at' => now()->addDays(5)]);
        CreditPeriod::counter($overdue->id)->update(['total_chars' => 90000]);
        $this->studio($overdue)->assertStatus(422);

        // A plan cancelled to end with its period: nothing renews, so "your credits renew on" would be wrong.
        $leaving = $this->apiMember($plan);
        BillingSubscription::query()->create([
            'user_id' => $leaving->id, 'type' => 'default', 'stripe_id' => 'sub_leaving', 'stripe_status' => 'active',
            'stripe_price' => 'price_x', 'quantity' => 1, 'ends_at' => now()->addDays(9),
        ]);
        CreditPeriod::counter($leaving->id)->update(['total_chars' => 90000]);
        $this->studio($leaving)->assertStatus(422);

        // Credits handed back before the email went (the voice that used them failed): they did not run out.
        $refunded = $this->apiMember($plan);
        CreditPeriod::counter($refunded->id)->update(['total_chars' => 90000 - 5000]);
        $this->assertFalse(app(CreditsEndedEmail::class)->memberRanOut($refunded->id));

        // The email switched off, then extra credits switched off, then a server that is not the live site.
        $member = $this->apiMember($plan);
        CreditPeriod::counter($member->id)->update(['total_chars' => 90000]);
        app(CreditsEndedEmail::class)->save(['enabled' => false]);
        $this->studio($member)->assertStatus(422);
        app(CreditsEndedEmail::class)->save(['enabled' => true]);
        SiteSetting::set(ExtraCredits::ENABLED_SETTING, '0', 'billing');
        $this->studio($member)->assertStatus(422);
        SiteSetting::set(ExtraCredits::ENABLED_SETTING, '1', 'billing');
        config(['listen.live_actions' => false]);
        $this->studio($member)->assertStatus(422);

        Mail::assertNotSent(CreditsEndedMail::class);
        $this->assertNull(CreditPeriod::counter($member->id)->credits_ended_email_at);

        // Back on the live site, the member is told.
        config(['listen.live_actions' => true]);
        $this->studio($member)->assertStatus(422);
        Mail::assertSent(CreditsEndedMail::class, fn (CreditsEndedMail $mail): bool => $mail->hasTo($member->email));
    }

    public function test_a_send_that_fails_is_tried_again_later_and_never_breaks_the_request(): void
    {
        $user = $this->apiMember();
        CreditPeriod::counter($user->id)->update(['total_chars' => 90000]);
        Mail::shouldReceive('mailer')->andThrow(new \RuntimeException('The mail server did not answer.'));

        $this->studio($user)->assertStatus(422)->assertJsonPath('out_of_credits', true);

        $this->assertNull(CreditPeriod::counter($user->id)->credits_ended_email_at, 'left free to be sent later');
        $this->assertTrue(Cache::has('credits_ended_email:retry:'.$user->id));
    }

    public function test_the_admin_edits_its_words_and_sends_a_test(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'email' => 'owner@example.test']);
        $this->apiPlan();
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(CreditsEndedEmailPage::class)
            ->assertFormSet(['enabled' => true, 'cta_label_en' => 'Add credits'])
            ->fillForm(['subject_en' => 'Out of credits on {plan}', 'cta_label_ar' => 'أضف دقائق'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->callAction('sendTest', ['email' => 'owner@example.test'])
            ->assertNotified('Test sent');

        $this->assertSame('أضف دقائق', app(CreditsEndedEmail::class)->settings()['cta_label_ar']);
        Mail::assertSent(CreditsEndedMail::class, fn (CreditsEndedMail $mail): bool => $mail->hasTo('owner@example.test')
            && str_contains($mail->subjectLine, 'Out of credits on Creator')
            && str_contains($mail->render(), 'أضف دقائق'));
        $this->assertStringContainsString('Add credits', app(CreditsEndedEmail::class)->previewHtml());
    }

    private function studio(User $user, string $text = 'Hello there, this is a test.'): TestResponse
    {
        $voice = Voice::query()->where('internal_voice_id', 'en-Gemini-HD-Kore')->first() ?? $this->premiumVoice();

        return $this->actingAs($user)->postJsonLocalizedRoute('dashboard.queue', [
            'content' => $text,
            'target_language' => 'en',
            'voice_tier' => 'hd',
            'voice_key' => VoiceCatalog::keyFromId((int) $voice->id),
        ]);
    }
}
