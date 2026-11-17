<?php

namespace Tests\Feature;

use App\Models\AudioGeneration;
use App\Models\BillingSubscription;
use App\Models\BillingTrial;
use App\Models\Plan;
use App\Models\User;
use App\Services\Billing\StripeTrialGateway;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\InApp\InAppTestCase;
use Tests\Support\FakeStripeTrialGateway;

/**
 * "Delete my account": a member deletes their own account, on the site and in
 * the apps, without writing to anyone (Apple's guideline 5.1.1(v)). A running
 * subscription is ended in Stripe first, and only where Stripe actions are
 * switched on (live), never on staging. Stripe is faked.
 */
class MemberAccountDeletionTest extends InAppTestCase
{
    /** The iPhone app's web view (it is told from the Android one by the end of its name). */
    private const IPHONE_UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 26_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 ListenApp/1.0.0 (iOS)';

    private FakeStripeTrialGateway $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stripe = new FakeStripeTrialGateway();
        $this->app->instance(StripeTrialGateway::class, $this->stripe);
        config(['listen.billing.stripe_trial_actions' => true]);
        Storage::fake('public');
        $this->seedPlansWithEverythingOpen();
    }

    public function test_a_guest_is_asked_to_sign_in_first(): void
    {
        $this->inBrowser(null)->page('account.delete')->assertRedirect();
        $this->inBrowser(null)->post($this->localizedRoute('account.delete.confirm'), ['confirm' => '1'])->assertRedirect();
        $this->assertSame(0, User::query()->count());
    }

    public function test_the_page_says_what_is_deleted_and_what_stays_in_both_languages(): void
    {
        $member = $this->memberOn('starter');

        foreach (['en', 'ar'] as $locale) {
            foreach ([self::BROWSER_UA, self::APP_UA, self::IPHONE_UA] as $userAgent) {
                $page = $this->visitor($member, $locale, $userAgent)->page('account.delete')->assertOk();
                $page->assertSee(__('account.delete.title', [], $locale))
                    ->assertSee(__('account.delete.gone_heading', [], $locale))
                    ->assertSee(__('account.delete.kept_heading', [], $locale))
                    ->assertSee(__('account.delete.confirm', [], $locale))
                    ->assertSee(__('account.delete.button', [], $locale))
                    ->assertSee($member->email)
                    ->assertSee('name="confirm"', false);
                foreach ((array) __('account.delete.gone', [], $locale) as $line) {
                    $page->assertSee($line);
                }
                // Nothing is being charged: no word about a subscription.
                $page->assertDontSee(__('account.delete.billing_subscription', [], $locale));
            }
        }
    }

    public function test_the_apps_account_page_leads_to_it_and_not_to_a_contact_form(): void
    {
        $member = $this->memberOn('starter');

        foreach ([self::APP_UA, self::IPHONE_UA] as $userAgent) {
            $account = (string) $this->visitor($member, 'en', $userAgent)->page('app.plan')->assertOk()->getContent();
            $this->assertStringContainsString($this->localizedRoute('account.delete'), $account);
            $this->assertStringNotContainsString('topic=delete', $account);
        }
        // Someone who writes in to ask is told they can do it themselves.
        $this->inBrowser(null)->get($this->localizedRoute('contact').'?topic=delete')->assertOk()
            ->assertSee(__('account.delete.self_service'))
            ->assertSee($this->localizedRoute('account.delete'), false);
        $this->inBrowser(null)->page('contact')->assertOk()->assertDontSee(__('account.delete.self_service'));
    }

    public function test_nothing_is_deleted_without_the_tick(): void
    {
        $member = $this->memberOn('free');

        $this->inBrowser($member)->from($this->localizedRoute('account.delete'))
            ->post($this->localizedRoute('account.delete.confirm'), [])
            ->assertSessionHasErrors('confirm');

        $this->assertNotNull($member->fresh());
        $this->assertAuthenticatedAs($member);
    }

    public function test_a_member_deletes_their_account_and_everything_in_it(): void
    {
        $member = $this->memberOn('free');
        $other = $this->memberOn('free');
        Storage::disk('public')->put('audio/mine.wav', 'mine');
        Storage::disk('public')->put('audio/theirs.wav', 'theirs');
        AudioGeneration::create(['user_id' => $member->id, 'user_sequence_number' => 1, 'input_text' => 'mine', 'target_language' => 'en', 'status' => 'done', 'audio_path' => 'audio/mine.wav']);
        AudioGeneration::create(['user_id' => $other->id, 'user_sequence_number' => 1, 'input_text' => 'theirs', 'target_language' => 'en', 'status' => 'done', 'audio_path' => 'audio/theirs.wav']);

        $done = $this->inBrowser($member)->post($this->localizedRoute('account.delete.confirm'), ['confirm' => '1']);

        $done->assertRedirect($this->localizedRoute('account.deleted'));
        $this->assertNull(User::query()->find($member->id));
        $this->assertGuest();
        // Their voices and the audio itself are gone; nobody else's is touched.
        $this->assertSame(0, AudioGeneration::query()->where('user_id', $member->id)->count());
        Storage::disk('public')->assertMissing('audio/mine.wav');
        Storage::disk('public')->assertExists('audio/theirs.wav');
        $this->assertSame(1, AudioGeneration::query()->where('user_id', $other->id)->count());
        $this->assertNotNull($other->fresh());
        // Signing out saves the member's row once more; the deleted account must not come back with it.
        $this->assertSame(0, User::query()->where('email', $member->email)->count());
        // Nothing was asked of Stripe: there was nothing to end.
        $this->assertSame([], $this->stripe->canceled);

        // The last page needs no account, in either language.
        foreach (['en', 'ar'] as $locale) {
            $this->inBrowser(null, $locale)->page('account.deleted')->assertOk()
                ->assertSee(__('account.deleted.title', [], $locale))
                ->assertSee(__('account.deleted.text', [], $locale));
        }
    }

    public function test_a_running_subscription_is_ended_in_stripe_first_then_the_account_goes(): void
    {
        $member = $this->memberOn('starter');
        $this->billingSubscription($member, 'sub_live_1', 'active');

        // The page says so before anything is done.
        $this->inBrowser($member)->page('account.delete')->assertOk()->assertSee(__('account.delete.billing_subscription'));

        $this->inBrowser($member)->post($this->localizedRoute('account.delete.confirm'), ['confirm' => '1'])
            ->assertRedirect($this->localizedRoute('account.deleted'));

        $this->assertSame(['sub_live_1'], $this->stripe->canceled);
        $this->assertSame(['sub_live_1'], $this->stripe->voided);
        $this->assertNull(User::query()->find($member->id));
    }

    public function test_an_unpaid_renewal_is_ended_too(): void
    {
        $member = $this->memberOn('starter');
        $this->billingSubscription($member, 'sub_due_1', 'past_due');

        $this->inBrowser($member)->post($this->localizedRoute('account.delete.confirm'), ['confirm' => '1'])->assertRedirect();

        $this->assertSame(['sub_due_1'], $this->stripe->canceled);
        $this->assertNull(User::query()->find($member->id));
    }

    public function test_an_open_free_trial_is_ended_so_the_card_is_never_charged(): void
    {
        $member = $this->memberOn('starter');
        $trial = $this->openTrial($member, 'sub_trial_9');
        $this->billingSubscription($member, 'sub_trial_9', 'trialing');

        $this->inBrowser($member)->page('account.delete')->assertOk()->assertSee(__('account.delete.billing_trial'));
        $this->inBrowser($member)->post($this->localizedRoute('account.delete.confirm'), ['confirm' => '1'])->assertRedirect();

        // Once, not twice: the trial and its subscription are the same thing in Stripe.
        $this->assertSame(['sub_trial_9'], $this->stripe->canceled);
        $this->assertNull(User::query()->find($member->id));
        $this->assertNull(BillingTrial::query()->find($trial->id));
    }

    public function test_when_stripe_will_not_end_the_subscription_nothing_is_deleted(): void
    {
        $member = $this->memberOn('starter');
        $this->billingSubscription($member, 'sub_live_2', 'active');
        Storage::disk('public')->put('audio/kept.wav', 'kept');
        AudioGeneration::create(['user_id' => $member->id, 'user_sequence_number' => 1, 'input_text' => 'kept', 'target_language' => 'en', 'status' => 'done', 'audio_path' => 'audio/kept.wav']);
        $this->stripe->throwOnCancel = true;

        $this->inBrowser($member)->post($this->localizedRoute('account.delete.confirm'), ['confirm' => '1'])
            ->assertRedirect($this->localizedRoute('account.delete'))
            ->assertSessionHasErrors(['delete' => __('account.delete.failed_billing')]);

        $this->assertNotNull($member->fresh());
        $this->assertAuthenticatedAs($member);
        $this->assertSame('active', BillingSubscription::query()->where('stripe_id', 'sub_live_2')->value('stripe_status'));
        Storage::disk('public')->assertExists('audio/kept.wav');
        $this->assertSame(1, AudioGeneration::query()->where('user_id', $member->id)->count());
    }

    public function test_staging_never_ends_a_real_subscription(): void
    {
        // Staging has the live Stripe keys and a copy of live's members: Stripe actions are off there.
        config(['listen.billing.stripe_trial_actions' => false]);
        $member = $this->memberOn('starter');
        $this->billingSubscription($member, 'sub_real_1', 'active');

        $this->inBrowser($member)->page('account.delete')->assertOk()
            ->assertSee(__('account.delete.refused_here'))
            ->assertDontSee('name="confirm"', false);
        $this->inBrowser($member)->post($this->localizedRoute('account.delete.confirm'), ['confirm' => '1'])
            ->assertSessionHasErrors(['delete' => __('account.delete.refused_here')]);

        $this->assertSame([], $this->stripe->canceled);
        $this->assertNotNull($member->fresh());
        $this->assertSame('active', BillingSubscription::query()->where('stripe_id', 'sub_real_1')->value('stripe_status'));

        // A member with nothing being charged can still delete their account there.
        $free = $this->memberOn('free');
        $this->inBrowser($free)->post($this->localizedRoute('account.delete.confirm'), ['confirm' => '1'])
            ->assertRedirect($this->localizedRoute('account.deleted'));
        $this->assertNull(User::query()->find($free->id));
    }

    public function test_an_admin_account_is_not_deleted_this_way(): void
    {
        $admin = $this->memberOn('free');
        $admin->forceFill(['is_admin' => true])->save();

        $this->inBrowser($admin)->page('account.delete')->assertOk()
            ->assertSee(__('account.delete.refused_admin'))
            ->assertDontSee('name="confirm"', false);
        $this->inBrowser($admin)->post($this->localizedRoute('account.delete.confirm'), ['confirm' => '1'])
            ->assertSessionHasErrors('delete');

        $this->assertNotNull($admin->fresh());
    }

    public function test_in_the_iphone_app_it_is_done_without_leaving_the_app(): void
    {
        $member = $this->memberOn('free');

        // The page is the app's own (its tabs, no website menu), and sells nothing.
        $page = (string) $this->visitor($member, 'ar', self::IPHONE_UA)->page('account.delete')->assertOk()->getContent();
        $this->assertStringContainsString('data-app-tab="account"', $page);
        $this->assertStringNotContainsString('mailto:', $page);
        $this->assertStringNotContainsString('wa.me', $page);
        // "Keep my account" leads back to the account page, and is one of the links the app shows.
        $this->assertMatchesRegularExpression('#<a class="btn" href="[^"]*/app/plan" data-in-app-plan>#', $page);

        $this->visitor($member, 'ar', self::IPHONE_UA)->post($this->localizedRoute('account.delete.confirm'), ['confirm' => '1'])
            ->assertRedirect($this->localizedRoute('account.deleted'));
        $this->assertNull(User::query()->find($member->id));

        // "Done" leads to the app's first page (sign-in, for someone with no account).
        $gone = $this->visitor(null, 'ar', self::IPHONE_UA)->page('account.deleted')->assertOk();
        $gone->assertSee(__('account.deleted.title', [], 'ar'))->assertSee('/app/start', false);
    }

    /** A request from this browser or app, as this member (or signed out), in this language. */
    private function visitor(?User $user, string $locale, string $userAgent): static
    {
        app('auth')->forgetGuards();
        app()->setLocale($locale);
        $this->flushHeaders()->withHeader('User-Agent', $userAgent);

        return $user ? $this->actingAs($user) : $this;
    }

    private function openTrial(User $user, string $subscriptionId): BillingTrial
    {
        return BillingTrial::query()->create([
            'user_id' => $user->id,
            'plan_id' => Plan::query()->where('slug', 'starter')->value('id'),
            'status' => BillingTrial::STATUS_TRIALING,
            'is_admin_test' => false,
            'stripe_customer_id' => 'cus_'.$user->id,
            'stripe_subscription_id' => $subscriptionId,
            'card_brand' => 'mastercard',
            'card_last4' => '7130',
            'amount_cents' => 1900,
            'started_at' => now()->subDays(3),
            'trial_ends_at' => now()->addDays(4),
        ]);
    }

    private function billingSubscription(User $user, string $subscriptionId, string $status): void
    {
        BillingSubscription::query()->create([
            'user_id' => $user->id,
            'type' => 'default',
            'stripe_id' => $subscriptionId,
            'stripe_status' => $status,
            'stripe_price' => 'price_starter_month',
            'quantity' => 1,
        ]);
    }
}
