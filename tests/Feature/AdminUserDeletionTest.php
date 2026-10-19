<?php

namespace Tests\Feature;

use App\Filament\Pages\SuspiciousUsers;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\BillingSubscription;
use App\Models\BillingTrial;
use App\Models\ClonedVoice;
use App\Models\DemoVoiceGeneration;
use App\Models\Plan;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

/**
 * Deleting an account in the admin (edit page, bulk delete, Suspicious Users).
 * Stripe would keep charging a live card subscription or open free trial after
 * the account is gone, so those are refused; everyone else's cloned voices are
 * removed at the provider and on disk, and the log says who deleted whom.
 */
class AdminUserDeletionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Plan $creator;

    protected function setUp(): void
    {
        parent::setUp();

        Plan::query()->firstOrCreate(['slug' => 'free'], [
            'name' => 'Free', 'price_cents' => 0, 'monthly_chars' => 10000, 'monthly_minutes' => 10,
            'monthly_requests' => 60, 'max_chars_per_request' => 1200, 'is_active' => true,
        ]);
        $this->creator = Plan::factory()->create(['slug' => 'creator-x', 'price_cents' => 1900]);
        $this->admin = User::factory()->create(['is_admin' => true]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_a_member_with_a_live_card_subscription_is_kept_with_the_reason(): void
    {
        foreach (['active', 'trialing', 'past_due', 'unpaid'] as $status) {
            $member = User::factory()->create();
            $this->cardSubscription($member, $status);

            Livewire::actingAs($this->admin)
                ->test(EditUser::class, ['record' => $member->getKey()])
                ->callAction('delete')
                ->assertNotified(__('ui.admin.users.delete_refused_title'));

            $this->assertNotNull($member->fresh(), $status.' subscription keeps the account');
        }
    }

    public function test_a_member_on_an_open_free_trial_is_kept_and_their_data_is_untouched(): void
    {
        $member = User::factory()->create(['registration_ip' => '203.0.113.9']);
        User::factory()->create(['registration_ip' => '203.0.113.9']);
        $this->openTrial($member);
        DemoVoiceGeneration::query()->create([
            'user_id' => $member->id,
            'primary_identity_hash' => 'u:'.$member->id,
            'input_text' => str_repeat('x', 120),
            'speech_text' => str_repeat('x', 120),
            'source_language' => 'en',
            'target_language' => 'en',
            'translate' => false,
            'voice_name' => 'en-US-Wavenet-C',
            'voice_provider' => 'google_cloud',
            'voice_tier' => 'standard',
            'billable_chars' => 120,
        ]);

        Livewire::actingAs($this->admin)
            ->test(SuspiciousUsers::class)
            ->callTableAction('deleteUser', $member)
            ->assertNotified(__('ui.admin.users.delete_refused_title'));

        $this->assertNotNull($member->fresh());
        $this->assertSame(1, DemoVoiceGeneration::query()->where('user_id', $member->id)->count(), 'nothing is removed when the delete is refused');
    }

    public function test_bulk_delete_keeps_members_who_would_be_charged_and_says_how_many(): void
    {
        $subscribed = User::factory()->create();
        $this->cardSubscription($subscribed, 'active');
        $trialing = User::factory()->create();
        $this->openTrial($trialing);
        $cancelled = User::factory()->create();
        $this->cardSubscription($cancelled, 'canceled');
        $plain = User::factory()->create();

        Livewire::actingAs($this->admin)
            ->test(ListUsers::class)
            ->callTableBulkAction('delete', [$subscribed, $trialing, $cancelled, $plain])
            ->assertNotified(__('ui.admin.users.bulk_delete_kept_title'));

        $this->assertNotNull($subscribed->fresh());
        $this->assertNotNull($trialing->fresh());
        $this->assertNull($cancelled->fresh(), 'an ended subscription does not stop the delete');
        $this->assertNull($plain->fresh());
    }

    public function test_deleting_a_member_removes_their_cloned_voices_everywhere_and_logs_it(): void
    {
        Storage::fake('local');
        config(['services.gemini_tts.api_key' => 'test-key']);
        $deleted = [];
        Http::fake(function ($request) use (&$deleted) {
            if ($request->method() === 'DELETE') {
                $deleted[] = $request->url();
            }

            return Http::response([], 200);
        });
        Log::spy();

        $member = User::factory()->create();
        Storage::disk('local')->put('voice-cloning/9/sample.wav', 'sample');
        Storage::disk('local')->put('voice-cloning/9/consent.wav', 'consent');
        ClonedVoice::factory()->forUser($member)->create([
            'status' => 'ready',
            'provider_voice_key' => 'voice_member_own',
            'source_audio_path' => 'voice-cloning/9/sample.wav',
            'consent_audio_path' => 'voice-cloning/9/consent.wav',
        ]);
        $removedEarlier = ClonedVoice::factory()->forUser($member)->create(['source_audio_path' => 'voice-cloning/9/old.wav']);
        Storage::disk('local')->put('voice-cloning/9/old.wav', 'old');
        $removedEarlier->delete();

        Livewire::actingAs($this->admin)
            ->test(EditUser::class, ['record' => $member->getKey()])
            ->callAction('delete')
            ->assertHasNoActionErrors();

        $this->assertNull($member->fresh());
        $this->assertCount(1, $deleted);
        $this->assertStringEndsWith('/voices/voice_member_own', $deleted[0]);
        Storage::disk('local')->assertMissing(['voice-cloning/9/sample.wav', 'voice-cloning/9/consent.wav', 'voice-cloning/9/old.wav']);
        $this->assertSame(0, ClonedVoice::withTrashed()->where('user_id', $member->id)->count());
        Log::shouldHaveReceived('info')->with('An admin deleted a member account.', Mockery::on(
            fn (array $context): bool => $context['user_id'] === $member->id && $context['admin_id'] === $this->admin->id
        ));
    }

    private function cardSubscription(User $user, string $status): void
    {
        BillingSubscription::query()->create([
            'user_id' => $user->id,
            'type' => 'default',
            'stripe_id' => 'sub_'.$user->id,
            'stripe_status' => $status,
            'stripe_price' => 'price_creator_month',
            'quantity' => 1,
        ]);
    }

    private function openTrial(User $user): void
    {
        BillingTrial::query()->create([
            'user_id' => $user->id,
            'plan_id' => $this->creator->id,
            'status' => BillingTrial::STATUS_TRIALING,
            'is_admin_test' => false,
            'stripe_customer_id' => 'cus_'.$user->id,
            'stripe_subscription_id' => 'sub_trial_'.$user->id,
            'amount_cents' => 1900,
            'started_at' => now()->subDay(),
            'trial_ends_at' => now()->addDays(6),
        ]);
    }
}
