<?php

namespace Tests\Feature;

use App\Models\BillingSubscription;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionCancellation;
use App\Models\User;
use App\Services\Newsletter\CancellationEmail;
use App\Services\StripeWebhookEmailService;
use App\Support\TitanMailbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Stripe\ApiRequestor;
use Tests\Support\FakeStripePlanSyncClient;
use Tests\TestCase;

/**
 * The email a member gets the moment they cancel (CancellationEmail): in Arabic and
 * English, in the newsletter's design, from the help mailbox; it asks why, or thanks
 * them when they already said in the cancel dialog; once per cancellation.
 */
class CancellationEmailTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    private Carbon $endsAt;

    protected function setUp(): void
    {
        parent::setUp();
        ApiRequestor::setHttpClient(new FakeStripePlanSyncClient);
        config(['listen.live_actions' => true]);
        Carbon::setTestNow(Carbon::parse('2026-11-28 10:00:00', 'UTC'));

        $plan = Plan::factory()->create(['slug' => 'creator', 'name' => 'Creator', 'name_ar' => 'مُبدع', 'price_cents' => 1900]);
        $this->member = User::factory()->create(['name' => 'Sara Ali', 'email' => 'sara@example.test']);
        $this->member->forceFill(['stripe_id' => 'cus_cx'])->save();
        Subscription::withoutEvents(fn () => Subscription::create([
            'user_id' => $this->member->id, 'plan_id' => $plan->id, 'status' => 'active', 'current_period_end' => now()->addDays(17),
        ]));
        $this->endsAt = now()->addDays(17)->startOfMinute();
        BillingSubscription::query()->create([
            'user_id' => $this->member->id, 'type' => 'default', 'stripe_id' => 'sub_cx',
            'stripe_status' => 'active', 'ends_at' => $this->endsAt,
        ]);
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient(null);
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_member_who_cancels_gets_one_email_in_both_languages_asking_why(): void
    {
        app(StripeWebhookEmailService::class)->sendCancellationScheduledForUser($this->member);

        $this->assertCount(1, $this->sent());
        $message = $this->sent()->first()->getOriginalMessage();
        $html = (string) $message->getHtmlBody();

        // Two-language subjects read English first in every email of this design.
        $this->assertSame("Your Creator plan won't renew · تم إلغاء تجديد باقة مُبدع", $this->subjectOf($message));
        // What happens, with the plan's end date, in each language.
        $this->assertStringContainsString('أهلاً Sara، ألغينا تجديد اشتراكك في باقة مُبدع كما طلبت.', $html);
        $this->assertStringContainsString('15 ديسمبر 2026', $html);
        $this->assertStringContainsString('Hi Sara, we&#039;ve cancelled the renewal of your Creator plan, as you asked.', $html);
        $this->assertStringContainsString('15 December 2026', $html);
        // The question, with its numbered answers, and the way back.
        $this->assertStringContainsString('يكفي أن تردّ على هذه الرسالة برقم:', $html);
        $this->assertStringContainsString('Just reply to this email with a number:', $html);
        $this->assertStringContainsString('السعر لم يكن مناسبًا', $html);
        $this->assertStringContainsString('Keep my plan', $html);
        $this->assertStringContainsString('أبقِ اشتراكي', $html);
        $this->assertStringContainsString('/ar/manage-subscription', $html);
        $this->assertStringContainsString('/en/manage-subscription', $html);
        // Replies reach the team; no other company is named.
        $this->assertSame(TitanMailbox::ADDRESS, $message->getReplyTo()[0]->getAddress());
        $this->assertStringNotContainsString('Stripe', $html);
        $this->assertStringNotContainsString('sub_cx', $html);
    }

    public function test_a_member_who_gave_a_reason_is_thanked_instead_of_asked(): void
    {
        $record = SubscriptionCancellation::create([
            'user_id' => $this->member->id, 'reason' => 'price', 'locale' => 'ar', 'cancelled_at' => now(), 'ends_at' => $this->endsAt,
        ]);

        app(StripeWebhookEmailService::class)->sendCancellationScheduledForUser($this->member);

        $html = (string) $this->sent()->sole()->getOriginalMessage()->getHtmlBody();
        $this->assertStringContainsString('شكرًا لأنك أخبرتنا بسبب الإلغاء', $html);
        $this->assertStringContainsString('Thank you for telling us why you cancelled.', $html);
        $this->assertStringNotContainsString('with a number', $html);
        $this->assertStringNotContainsString('برقم', $html);
        $this->assertNotNull($record->fresh()->email_sent_at);
    }

    public function test_the_webhook_after_the_site_sends_nothing_more(): void
    {
        $emails = app(StripeWebhookEmailService::class);
        $emails->sendCancellationScheduledForUser($this->member);
        // Stripe tells the same cancellation a moment later.
        $emails->sendForEvent(json_decode(json_encode([
            'id' => 'evt_cx', 'type' => 'customer.subscription.updated',
            'data' => [
                'object' => [
                    'id' => 'sub_cx', 'object' => 'subscription', 'customer' => 'cus_cx', 'status' => 'active',
                    'cancel_at_period_end' => true, 'cancel_at' => $this->endsAt->timestamp, 'current_period_end' => $this->endsAt->timestamp,
                    'items' => ['object' => 'list', 'data' => []],
                ],
                'previous_attributes' => ['cancel_at_period_end' => false],
            ],
        ])));

        $this->assertCount(1, $this->sent());
    }

    public function test_a_cancellation_in_stripe_alone_gets_the_same_email(): void
    {
        app(StripeWebhookEmailService::class)->sendForEvent(json_decode(json_encode([
            'id' => 'evt_portal', 'type' => 'customer.subscription.updated',
            'data' => [
                'object' => [
                    'id' => 'sub_portal', 'object' => 'subscription', 'customer' => 'cus_cx', 'status' => 'active',
                    'cancel_at_period_end' => true, 'cancel_at' => $this->endsAt->timestamp, 'current_period_end' => $this->endsAt->timestamp,
                    'items' => ['object' => 'list', 'data' => []],
                ],
                'previous_attributes' => ['cancel_at_period_end' => false],
            ],
        ])));

        $html = (string) $this->sent()->sole()->getOriginalMessage()->getHtmlBody();
        $this->assertStringContainsString('Keep my plan', $html);
        $this->assertStringContainsString('15 December 2026', $html);
    }

    public function test_switched_off_the_plain_notice_goes_instead(): void
    {
        app(CancellationEmail::class)->save(['enabled' => false]);

        app(StripeWebhookEmailService::class)->sendCancellationScheduledForUser($this->member);

        $this->assertSame('Cancellation scheduled', $this->sent()->sole()->getOriginalMessage()->getSubject());
    }

    public function test_staging_emails_no_member(): void
    {
        config(['listen.live_actions' => false]);

        app(StripeWebhookEmailService::class)->sendCancellationScheduledForUser($this->member);

        $this->assertCount(0, $this->sent());
    }

    public function test_the_admin_preview_shows_both_versions(): void
    {
        $email = app(CancellationEmail::class);

        $this->assertStringContainsString('with a number', $email->previewHtml());
        $this->assertStringContainsString('Thank you for telling us why', $email->previewHtml(null, true));
    }

    public function test_the_admin_sees_the_reasons_and_edits_the_email(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));
        $this->actingAs($admin);
        SubscriptionCancellation::create([
            'user_id' => $this->member->id, 'plan_name' => 'Creator', 'reason' => 'quality',
            'note' => 'The Gulf dialect sounded off.', 'cancelled_at' => now(), 'ends_at' => $this->endsAt,
        ]);
        // One Stripe refused: not a cancellation.
        SubscriptionCancellation::create(['user_id' => $this->member->id, 'reason' => 'price']);

        \Livewire\Livewire::test(\App\Filament\Resources\SubscriptionCancellationResource\Pages\ListSubscriptionCancellations::class)
            ->assertSee('sara@example.test')
            ->assertSee('Voices or dialects not as expected')
            ->assertSee('The Gulf dialect sounded off.')
            ->assertCanSeeTableRecords(SubscriptionCancellation::query()->whereNotNull('cancelled_at')->get())
            ->assertCanNotSeeTableRecords(SubscriptionCancellation::query()->whereNull('cancelled_at')->get());

        \Livewire\Livewire::test(\App\Filament\Pages\CancellationEmailSettings::class)
            ->assertSee('Goes out by itself the moment a member cancels')
            ->set('data.cta_label_en', 'Stay with Listen')
            ->call('save')
            ->assertHasNoErrors();
        $this->assertStringContainsString('Stay with Listen', app(CancellationEmail::class)->previewHtml());
    }

    private function sent(): Collection
    {
        return app('mailer')->getSymfonyTransport()->messages();
    }

    private function subjectOf(object $message): string
    {
        return (string) $message->getSubject();
    }
}
