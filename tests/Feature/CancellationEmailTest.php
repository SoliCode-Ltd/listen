<?php

namespace Tests\Feature;

use App\Models\BillingSubscription;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\Subscription;
use App\Models\SubscriptionCancellation;
use App\Models\User;
use App\Services\Newsletter\CancellationEmail;
use App\Services\StripeWebhookEmailService;
use App\Support\TitanMailbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
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

    public function test_a_member_who_cancels_gets_one_email_in_both_languages(): void
    {
        app(StripeWebhookEmailService::class)->sendCancellationScheduledForUser($this->member);

        $this->assertCount(1, $this->sent());
        $message = $this->sent()->first()->getOriginalMessage();
        $html = (string) $message->getHtmlBody();

        // Two-language subjects read English first in every email of this design.
        $this->assertSame("Your Creator plan won't renew · تم إلغاء تجديد باقة مُبدع", $this->subjectOf($message));
        // What happens, with the plan's end date, in each language.
        $this->assertStringContainsString('أهلاً Sara،', $html);
        $this->assertStringContainsString('ألغينا تجديد اشتراكك في باقة مُبدع كما طلبت.', $html);
        $this->assertStringContainsString('ما الذي يحدث الآن', $html);
        $this->assertStringContainsString('15 ديسمبر 2026', $html);
        $this->assertStringContainsString('Hi Sara,', $html);
        $this->assertStringContainsString('We&#039;ve cancelled the renewal of your Creator plan, as you asked.', $html);
        $this->assertStringContainsString('What happens now', $html);
        $this->assertStringContainsString('until <strong style="font-weight:600;color:#16161D;">15 December 2026</strong>', $html);
        $this->assertStringContainsString('15 December 2026', $html);
        // No reason was given here (no cancel dialog): it invites a reply, and never repeats the dialog's list.
        $this->assertStringContainsString('إذا لم يكن شيء كما توقعت، ردّ على هذه الرسالة وأخبرنا', $html);
        $this->assertStringContainsString('reply to this email and tell us. We read every message ourselves', $html);
        $this->assertStringNotContainsString('with a number', $html);
        $this->assertStringNotContainsString('برقم', $html);
        // The way back, in both languages.
        $this->assertStringContainsString('Keep my plan', $html);
        $this->assertStringContainsString('أبقِ اشتراكي', $html);
        $this->assertStringContainsString('/ar/manage-subscription', $html);
        $this->assertStringContainsString('/en/manage-subscription', $html);
        // Replies reach the team; no other company is named.
        $this->assertSame(TitanMailbox::ADDRESS, $message->getReplyTo()[0]->getAddress());
        $this->assertStringNotContainsString('Stripe', $html);
        $this->assertStringNotContainsString('sub_cx', $html);
    }

    public function test_each_reason_gets_its_own_answer_with_something_to_stay_for(): void
    {
        $expect = [
            'quality' => ['More than 200 dialects and accents', 'أكثر من 200 لهجة ولكنة'],
            'technical' => ['a screenshot helps a lot', 'وصورة الشاشة تساعدنا كثيرًا'],
            'not_needed' => ['Whenever you need a voice-over again, your account will be here.', 'ومتى احتجت إلى تعليق صوتي من جديد'],
            'other' => ['If you&#039;d like to say more, just reply to this email', 'وإن أردت أن تضيف شيئًا'],
        ];
        foreach ($expect as $reason => [$en, $ar]) {
            Cache::flush();
            SubscriptionCancellation::query()->delete();
            $record = SubscriptionCancellation::create([
                'user_id' => $this->member->id, 'reason' => $reason, 'cancelled_at' => now(), 'ends_at' => $this->endsAt,
            ]);

            app(StripeWebhookEmailService::class)->sendCancellationScheduledForUser($this->member);

            $html = (string) $this->sent()->last()->getOriginalMessage()->getHtmlBody();
            $this->assertStringContainsString($en, $html, $reason);
            $this->assertStringContainsString($ar, $html, $reason);
            $this->assertStringNotContainsString('with a number', $html, $reason);
            $this->assertNotNull($record->fresh()->email_sent_at, $reason);
        }
        $this->assertCount(4, $this->sent());
    }

    public function test_the_price_reason_offers_only_what_the_members_plan_really_has(): void
    {
        $creator = Plan::query()->where('slug', 'creator')->sole();
        SubscriptionCancellation::create(['user_id' => $this->member->id, 'reason' => 'price', 'cancelled_at' => now(), 'ends_at' => $this->endsAt]);

        // The smallest paid plan, with no yearly price: nothing to offer, so it thanks them and asks what would work.
        $html = $this->sendAgain();
        $this->assertStringContainsString('If a plan or a price would work better for you', $html);
        $this->assertStringNotContainsString('A smaller plan', $html);
        $this->assertStringNotContainsString('Yearly billing', $html);

        // A cheaper plan exists and theirs has a yearly price they don't pay yet: both, with the saving set in Billing settings.
        Plan::factory()->create(['slug' => 'starter', 'name' => 'Starter', 'price_cents' => 900, 'is_active' => true]);
        $creator->forceFill(['stripe_price_monthly' => 'price_creator_m', 'stripe_price_yearly' => 'price_creator_y'])->save();
        BillingSubscription::query()->where('user_id', $this->member->id)->update(['stripe_price' => 'price_creator_m']);
        SiteSetting::set('billing_yearly_discount_percentage', '20', 'billing');
        $html = $this->sendAgain();
        $this->assertStringContainsString('Pay less, without leaving', $html);
        $this->assertStringContainsString('A smaller plan</strong>, with fewer credits a month.', $html);
        $this->assertStringContainsString('Yearly billing</strong>, which saves you 20% compared with paying monthly.', $html);
        $this->assertStringContainsString('You can switch any time before 15 December 2026, from Manage subscription.', $html);
        $this->assertStringContainsString('باقة أصغر</strong> برصيد شهري أقل.', $html);
        $this->assertStringContainsString('ويوفّر لك 20% مقارنةً بالدفع الشهري.', $html);

        // Already paying yearly: only the smaller plan.
        BillingSubscription::query()->where('user_id', $this->member->id)->update(['stripe_price' => 'price_creator_y']);
        $html = $this->sendAgain();
        $this->assertStringContainsString('A smaller plan', $html);
        $this->assertStringNotContainsString('Yearly billing', $html);

        // On an older or special price (not the plan's monthly one): yearly is never offered on a guess.
        BillingSubscription::query()->where('user_id', $this->member->id)->update(['stripe_price' => 'price_creator_legacy']);
        $html = $this->sendAgain();
        $this->assertStringNotContainsString('Yearly billing', $html);
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

    public function test_the_admin_preview_shows_each_reason(): void
    {
        $email = app(CancellationEmail::class);

        $this->assertStringContainsString('A smaller plan', $email->previewHtml(null, 'price'));
        $this->assertStringContainsString('More than 200 dialects and accents', $email->previewHtml(null, 'quality'));
        $this->assertStringContainsString('We read every message ourselves, and we&#039;ll help right away', $email->previewHtml(null, null));
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
            // A preview for each reason, and one for a cancellation in Stripe.
            ->assertActionExists('preview_price')
            ->assertActionExists('preview_quality')
            ->assertActionExists('preview_technical')
            ->assertActionExists('preview_not_needed')
            ->assertActionExists('preview_other')
            ->assertActionExists('preview_none')
            ->set('data.cta_label_en', 'Stay with Listen')
            ->call('save')
            ->assertHasNoErrors();
        $this->assertStringContainsString('Stay with Listen', app(CancellationEmail::class)->previewHtml());
    }

    /** Sends the email again, as for a new cancellation, and returns its html. */
    private function sendAgain(): string
    {
        Cache::flush();
        app(StripeWebhookEmailService::class)->sendCancellationScheduledForUser($this->member);

        return (string) $this->sent()->last()->getOriginalMessage()->getHtmlBody();
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
