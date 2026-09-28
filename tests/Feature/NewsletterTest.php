<?php

namespace Tests\Feature;

use App\Filament\Pages\NewsletterMailbox;
use App\Filament\Resources\NewsletterResource;
use App\Filament\Resources\NewsletterResource\Pages\EditNewsletter;
use App\Filament\Resources\NewsletterResource\RelationManagers\DeliveriesRelationManager;
use App\Mail\NewsletterMail;
use App\Models\Newsletter;
use App\Models\NewsletterDelivery;
use App\Models\NewsletterSuppression;
use App\Models\Plan;
use App\Models\User;
use App\Services\Newsletter\NewsletterAudience;
use App\Services\Newsletter\NewsletterRenderer;
use App\Services\Newsletter\NewsletterSender;
use App\Services\PlanAccessService;
use App\Support\TitanMailbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class NewsletterTest extends TestCase
{
    use RefreshDatabase;

    private Plan $freePlan;

    private Plan $paidPlan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->paidPlan = Plan::factory()->paid()->create([
            'slug' => 'starter',
            'name' => 'Starter',
            'price_cents' => 900,
        ]);
        $this->freePlan = Plan::factory()->free()->create([
            'slug' => 'free',
            'name' => 'Free',
            'price_cents' => 0,
        ]);
    }

    public function test_saving_a_newsletter_does_not_email_anyone(): void
    {
        Mail::fake();

        $newsletter = Newsletter::query()->create(Newsletter::defaultAttributes());

        Mail::assertNothingSent();
        $this->assertSame(Newsletter::STATUS_DRAFT, $newsletter->status);
        $this->assertSame(0, $newsletter->deliveries()->count());
    }

    public function test_audience_is_free_registered_users_only(): void
    {
        $free = User::factory()->create(['name' => 'Free User', 'email' => 'free@example.com']);
        $paid = User::factory()->create(['name' => 'Paid User', 'email' => 'paid@example.com']);
        $blocked = User::factory()->create([
            'name' => 'Blocked User',
            'email' => 'blocked@example.com',
            'is_blocked' => true,
        ]);
        $phone = User::factory()->create([
            'name' => 'Phone User',
            'email' => 'phone+abc@users.listen.local',
        ]);
        $unsubscribed = User::factory()->create(['name' => 'Opt Out', 'email' => 'optout@example.com']);
        $already = User::factory()->create(['name' => 'Already Sent', 'email' => 'already@example.com']);

        app(PlanAccessService::class)->assignPlan($paid, $this->paidPlan);
        NewsletterSuppression::query()->create([
            'email' => 'optout@example.com',
            'unsubscribed_at' => now(),
        ]);

        $newsletter = Newsletter::query()->create(Newsletter::defaultAttributes());
        NewsletterDelivery::query()->create([
            'newsletter_id' => $newsletter->id,
            'user_id' => $already->id,
            'email' => 'already@example.com',
            'locale' => 'both',
            'is_test' => false,
            'status' => NewsletterDelivery::STATUS_SENT,
            'sent_at' => now(),
        ]);

        $emails = $this->audienceEmails($newsletter);

        $this->assertContains('free@example.com', $emails);
        $this->assertNotContains('paid@example.com', $emails);
        $this->assertNotContains('blocked@example.com', $emails);
        $this->assertNotContains('phone+abc@users.listen.local', $emails);
        $this->assertNotContains('optout@example.com', $emails);
        $this->assertNotContains('already@example.com', $emails);
        $this->assertNotContains($paid->email, $emails);
        $this->assertNotContains($blocked->email, $emails);
        $this->assertNotContains($phone->email, $emails);
        $this->assertNotContains($unsubscribed->email, $emails);
        $this->assertContains($free->email, $emails);
    }

    public function test_paid_plan_and_custom_audiences(): void
    {
        $free = User::factory()->create(['email' => 'free-two@example.com']);
        $paid = User::factory()->create(['email' => 'paid-two@example.com']);
        app(PlanAccessService::class)->assignPlan($paid, $this->paidPlan);

        $paidNewsletter = Newsletter::query()->create(array_merge(Newsletter::defaultAttributes(), [
            'audience' => Newsletter::AUDIENCE_PAID,
        ]));
        $this->assertSame(['paid-two@example.com'], $this->audienceEmails($paidNewsletter));

        $planNewsletter = Newsletter::query()->create(array_merge(Newsletter::defaultAttributes(), [
            'name' => 'Plan audience',
            'audience' => Newsletter::AUDIENCE_PLAN,
            'plan_id' => $this->paidPlan->id,
        ]));
        $this->assertSame(['paid-two@example.com'], $this->audienceEmails($planNewsletter));

        $custom = Newsletter::query()->create(array_merge(Newsletter::defaultAttributes(), [
            'name' => 'Custom audience',
            'audience' => Newsletter::AUDIENCE_CUSTOM,
            'custom_emails' => "guest@example.com\n{$free->email}, {$paid->email}",
        ]));
        $customEmails = $this->audienceEmails($custom);
        sort($customEmails);
        $this->assertSame([
            'free-two@example.com',
            'guest@example.com',
            'paid-two@example.com',
        ], $customEmails);
    }

    public function test_example_send_goes_only_to_the_typed_address(): void
    {
        Mail::fake();

        $free = User::factory()->create(['name' => 'Free User', 'email' => 'free@example.com']);
        $newsletter = Newsletter::query()->create(Newsletter::defaultAttributes());

        app(NewsletterSender::class)->sendExample($newsletter, 'Me@Example.com', Newsletter::LANGUAGE_BOTH);

        Mail::assertSent(NewsletterMail::class, function (NewsletterMail $mail): bool {
            return $mail->hasTo('me@example.com')
                && str_contains($mail->htmlBody, 'Clone your voice')
                && str_contains($mail->htmlBody, 'استنسخ صوتك')
                && str_contains($mail->htmlBody, 'dir="rtl"')
                && str_contains($mail->htmlBody, '/en/billing')
                && str_contains($mail->htmlBody, '/ar/billing')
                && str_contains($mail->subjectLine, 'Your voice can now be cloned')
                && str_contains($mail->subjectLine, 'صار بإمكانك استنساخ صوتك');
        });
        Mail::assertNotSent(NewsletterMail::class, fn (NewsletterMail $mail): bool => $mail->hasTo($free->email));

        $newsletter->refresh();
        $this->assertSame(Newsletter::STATUS_DRAFT, $newsletter->status);
        $this->assertSame('me@example.com', $newsletter->last_test_email);
        $this->assertDatabaseHas('newsletter_deliveries', [
            'newsletter_id' => $newsletter->id,
            'email' => 'me@example.com',
            'is_test' => true,
            'status' => NewsletterDelivery::STATUS_SENT,
        ]);
    }

    public function test_audience_send_requires_confirmation_and_reaches_free_users_only(): void
    {
        Mail::fake();

        $free = User::factory()->create(['name' => 'Lina Nasser', 'email' => 'lina@example.com']);
        $paid = User::factory()->create(['email' => 'paid-send@example.com']);
        app(PlanAccessService::class)->assignPlan($paid, $this->paidPlan);
        $newsletter = Newsletter::query()->create(Newsletter::defaultAttributes());

        try {
            app(NewsletterSender::class)->sendToAudience($newsletter, false);
            $this->fail('Unconfirmed audience send should be refused.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('confirmed', $exception->getMessage());
        }
        Mail::assertNothingSent();

        $result = app(NewsletterSender::class)->sendToAudience($newsletter->fresh(), true);

        $this->assertSame(1, $result['sent']);
        $this->assertSame(0, $result['failed']);
        Mail::assertSent(NewsletterMail::class, function (NewsletterMail $mail): bool {
            return $mail->hasTo('lina@example.com')
                && str_contains($mail->htmlBody, 'Hello Lina,')
                && str_contains($mail->htmlBody, 'newsletter/unsubscribe');
        });
        Mail::assertNotSent(NewsletterMail::class, fn (NewsletterMail $mail): bool => $mail->hasTo($paid->email));

        $newsletter->refresh();
        $this->assertSame(Newsletter::STATUS_SENT, $newsletter->status);
        $this->assertSame(1, $newsletter->sent_count);

        Mail::fake();
        $second = app(NewsletterSender::class)->sendToAudience($newsletter->fresh(), true);
        $this->assertSame(0, $second['sent']);
        Mail::assertNothingSent();
        $this->assertSame($free->email, 'lina@example.com');
    }

    public function test_unsubscribe_link_suppresses_the_address(): void
    {
        $user = User::factory()->create(['email' => 'reader@example.com']);
        $newsletter = Newsletter::query()->create(Newsletter::defaultAttributes());
        $english = app(NewsletterRenderer::class)->unsubscribeUrl($user->email, Newsletter::LANGUAGE_EN);
        $arabic = app(NewsletterRenderer::class)->unsubscribeUrl($user->email, Newsletter::LANGUAGE_AR);

        $this->get($english)
            ->assertOk()
            ->assertSee('You are unsubscribed')
            ->assertSee('logo-light.svg')
            ->assertSee('IBM+Plex+Sans:wght@400;500;600;700;800', false)
            ->assertSee('IBM+Plex+Sans+Arabic:wght@400;500;600;700;800', false)
            ->assertSee("font-family: 'IBM Plex Sans', sans-serif", false)
            ->assertDontSee('Segoe UI')
            ->assertDontSee('تم إلغاء الاشتراك')
            ->assertDontSee('logo-ar-light.svg');

        $this->get($arabic)
            ->assertOk()
            ->assertSee('تم إلغاء الاشتراك')
            ->assertSee('منصة إسمع')
            ->assertSee('logo-ar-light.svg')
            ->assertSee("font-family: 'IBM Plex Sans', 'IBM Plex Sans Arabic', sans-serif", false)
            ->assertDontSee('Tahoma')
            ->assertDontSee('You are unsubscribed')
            ->assertDontSee('logo-light.svg');

        $this->assertDatabaseHas('newsletter_suppressions', [
            'email' => 'reader@example.com',
        ]);
        $this->assertNotContains('reader@example.com', $this->audienceEmails($newsletter));

        $this->get(route('newsletter.unsubscribe', ['email' => $user->email]))
            ->assertForbidden();
    }

    public function test_titan_mailbox_replaces_a_broken_resend_mailer(): void
    {
        $this->assertFalse(class_exists(\Resend::class));

        config(['mail.default' => 'resend']);
        TitanMailbox::preferOverBrokenResend();

        $this->assertSame('titan', config('mail.default'));
        $this->assertSame('smtp.titan.email', config('mail.mailers.titan.host'));
        $this->assertSame(465, config('mail.mailers.titan.port'));
        $this->assertSame('help@listenai.studio', config('mail.mailers.titan.username'));
    }

    public function test_missing_titan_password_tells_the_admin_where_to_add_it(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('help@listenai.studio');
        TitanMailbox::requirePassword();
    }

    public function test_titan_auth_failure_explains_the_third_party_lock(): void
    {
        $raw = 'Failed to authenticate on SMTP server with username "help@listenai.studio" using the following authenticators: "LOGIN", "PLAIN". Authenticator "LOGIN" returned "Expected response code "235" but got code "535", with message "535 5.7.8 Error: authentication failed: UGFzc3dvcmQ6".".';

        $explained = TitanMailbox::explainFailure($raw);

        $this->assertStringContainsString('Enable Titan on other apps', $explained);
        $this->assertStringContainsString('https://app.titan.email/mail/', $explained);
        $this->assertStringContainsString('two-factor', strtolower($explained));
        $this->assertStringContainsString('UGFzc3dvcmQ6', $explained);
        $this->assertStringNotContainsString('Expected response code', $explained);
        $this->assertSame('Some other mail error.', TitanMailbox::explainFailure('Some other mail error.'));
    }

    public function test_saved_mailbox_password_is_used_for_titan(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        Livewire::actingAs($admin)
            ->test(NewsletterMailbox::class)
            ->fillForm([
                'host' => 'smtp.titan.email',
                'port' => 465,
                'username' => 'help@listenai.studio',
                'from_name' => 'Listen',
                'password' => 'titan-secret',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('titan-secret', TitanMailbox::password());
        $this->assertSame('help@listenai.studio', TitanMailbox::username());

        $this->actingAs($admin)
            ->get(NewsletterMailbox::getUrl())
            ->assertOk()
            ->assertSee('help@listenai.studio')
            ->assertSee('Enable Titan on other apps')
            ->assertDontSee('titan-secret');
    }

    public function test_renderer_can_show_one_language_and_escapes_html(): void
    {
        $newsletter = new Newsletter(array_merge(Newsletter::defaultAttributes(), [
            'body_en' => '<script>alert(1)</script>',
            'eyebrow_en' => 'Voice cloning',
        ]));
        $html = app(NewsletterRenderer::class)->html($newsletter, Newsletter::LANGUAGE_EN, [
            'name' => 'Sam',
            'email' => 'sam@example.com',
        ]);

        $this->assertStringNotContainsString('Voice cloning', $html);
        $this->assertStringContainsString('IBM+Plex+Sans:wght@400;500;600;700;800', $html);
        $this->assertStringContainsString('IBM+Plex+Sans+Arabic:wght@400;500;600;700;800', $html);
        $this->assertStringContainsString(e(NewsletterRenderer::FONT_EN), $html);
        $this->assertStringNotContainsString('Segoe UI', $html);
        $this->assertStringNotContainsString('Tahoma', $html);
        $this->assertStringContainsString('Hello Sam,', $html);
        $this->assertStringContainsString('email-logo-en.png', $html);
        $this->assertStringContainsString('text-align:center', $html);
        $this->assertDoesNotMatchRegularExpression('/<table[^>]*\salign="/', $html);
        $this->assertStringContainsString('lang=en', $html);
        $this->assertStringContainsString('lang=ar', $html);
        $this->assertStringNotContainsString('>L</td>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('استنسخ صوتك', $html);

        $arabicHtml = app(NewsletterRenderer::class)->html($newsletter, Newsletter::LANGUAGE_AR, [
            'name' => 'سام',
            'email' => 'sam@example.com',
        ]);
        $this->assertStringContainsString('email-logo-ar.png', $arabicHtml);
        $this->assertStringNotContainsString('email-logo-en.png', $arabicHtml);
        $this->assertStringContainsString(e(NewsletterRenderer::FONT_AR), $arabicHtml);
    }

    public function test_admin_can_open_newsletter_and_send_an_example_without_emailing_the_audience(): void
    {
        Mail::fake();

        $admin = User::factory()->create(['is_admin' => true, 'email' => 'admin@example.com']);
        $free = User::factory()->create(['email' => 'audience@example.com']);
        $newsletter = Newsletter::query()->create(Newsletter::defaultAttributes());

        $this->actingAs($admin)
            ->get(NewsletterResource::getUrl('index'))
            ->assertOk()
            ->assertSee('Newsletter')
            ->assertSee('Voice cloning');

        $this->actingAs($free)
            ->get(NewsletterResource::getUrl('index'))
            ->assertForbidden();

        $this->actingAs($admin)
            ->get(NewsletterResource::getUrl('edit', ['record' => $newsletter]))
            ->assertOk()
            ->assertSee('Send example to my email')
            ->assertSee('Send to audience')
            ->assertSee('Clone your voice. Use it on every script.');

        Livewire::actingAs($admin)
            ->test(EditNewsletter::class, ['record' => $newsletter->getKey()])
            ->callAction('sendExample', data: [
                'email' => 'only-me@example.com',
                'language' => Newsletter::LANGUAGE_EN,
            ])
            ->assertNotified();

        Mail::assertSent(NewsletterMail::class, fn (NewsletterMail $mail): bool => $mail->hasTo('only-me@example.com'));
        Mail::assertNotSent(NewsletterMail::class, fn (NewsletterMail $mail): bool => $mail->hasTo($free->email));
        Mail::assertSentCount(1);
    }

    public function test_admin_send_action_emails_free_users_only_after_confirmation(): void
    {
        Mail::fake();

        $admin = User::factory()->create(['is_admin' => true]);
        $free = User::factory()->create(['email' => 'free-action@example.com']);
        $paid = User::factory()->create(['email' => 'paid-action@example.com']);
        app(PlanAccessService::class)->assignPlan($paid, $this->paidPlan);
        $newsletter = Newsletter::query()->create(Newsletter::defaultAttributes());

        Livewire::actingAs($admin)
            ->test(EditNewsletter::class, ['record' => $newsletter->getKey()])
            ->callAction('countAudience')
            ->assertNotified()
            ->callAction('sendToAudience', data: [
                'confirm' => true,
            ])
            ->assertNotified();

        Mail::assertSent(NewsletterMail::class, fn (NewsletterMail $mail): bool => $mail->hasTo($free->email));
        Mail::assertNotSent(NewsletterMail::class, fn (NewsletterMail $mail): bool => $mail->hasTo($paid->email));
        Mail::assertNotSent(NewsletterMail::class, fn (NewsletterMail $mail): bool => $mail->hasTo($admin->email));
        $this->assertSame(Newsletter::STATUS_SENT, $newsletter->fresh()->status);
    }

    public function test_admin_can_remove_send_log_rows(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $newsletter = Newsletter::query()->create(Newsletter::defaultAttributes());
        $keep = NewsletterDelivery::query()->create([
            'newsletter_id' => $newsletter->id,
            'email' => 'keep@example.com',
            'locale' => 'en',
            'is_test' => true,
            'status' => NewsletterDelivery::STATUS_FAILED,
            'error' => 'mailbox',
            'sent_at' => now(),
        ]);
        $remove = NewsletterDelivery::query()->create([
            'newsletter_id' => $newsletter->id,
            'email' => 'remove@example.com',
            'locale' => 'en',
            'is_test' => true,
            'status' => NewsletterDelivery::STATUS_SENT,
            'sent_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(DeliveriesRelationManager::class, [
                'ownerRecord' => $newsletter,
                'pageClass' => EditNewsletter::class,
            ])
            ->assertSee('Clear log')
            ->assertSee('remove@example.com')
            ->callTableAction('delete', $remove);

        $this->assertDatabaseMissing('newsletter_deliveries', ['id' => $remove->id]);
        $this->assertDatabaseHas('newsletter_deliveries', ['id' => $keep->id]);

        Livewire::actingAs($admin)
            ->test(DeliveriesRelationManager::class, [
                'ownerRecord' => $newsletter->fresh(),
                'pageClass' => EditNewsletter::class,
            ])
            ->callTableAction('clearLog');

        $this->assertDatabaseMissing('newsletter_deliveries', ['id' => $keep->id]);
        $this->assertSame(0, $newsletter->deliveries()->count());
    }

    /**
     * @return list<string>
     */
    private function audienceEmails(Newsletter $newsletter): array
    {
        $emails = [];
        app(NewsletterAudience::class)->each($newsletter, function (array $recipient) use (&$emails): void {
            $emails[] = $recipient['email'];
        });
        sort($emails);

        return $emails;
    }
}
