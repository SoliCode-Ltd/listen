<?php

namespace Tests\Feature;

use App\Models\BillingTrial;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\Billing\StripeTrialGateway;
use App\Services\BlockedUserService;
use App\Services\MultiAccountEnforcementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Support\FakeStripeTrialGateway;
use Tests\TestCase;

/**
 * A blocked member cannot start over under a new name on the same browser:
 * accounts made there after the block are blocked too. Accounts that were
 * already there, and accounts an admin unblocked, are left alone.
 */
class BlockEvasionTest extends TestCase
{
    use RefreshDatabase;

    private const BROWSER = 'dev_blocked_browser_0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();

        Plan::query()->firstOrCreate(['slug' => 'free'], [
            'name' => 'Free', 'price_cents' => 0, 'monthly_chars' => 10000, 'monthly_minutes' => 10,
            'monthly_requests' => 60, 'max_chars_per_request' => 1200, 'is_active' => true,
        ]);
        // Generous browser limit, like live, so only the new rule is at work.
        SiteSetting::set('max_accounts_per_ip', '10', 'security');
    }

    public function test_account_registered_on_a_blocked_members_browser_is_blocked(): void
    {
        $this->blockedMember();

        $this->register('second@example.com');

        $user = User::where('email', 'second@example.com')->first();
        $this->assertTrue($user->isBlocked());
        $this->assertSame(MultiAccountEnforcementService::EVASION_REASON_EN, $user->blocked_reason_en);
        $this->assertSame(MultiAccountEnforcementService::EVASION_REASON_AR, $user->blocked_reason_ar);
        $this->assertNull($user->blocked_by, 'automatic, not an admin');
    }

    public function test_existing_account_made_after_the_block_is_caught_at_its_next_sign_in(): void
    {
        $this->blockedMember();
        $this->travel(5)->minutes();
        $evader = User::factory()->create([
            'email' => 'evader@example.com',
            'password' => Hash::make('Password123!'),
            'registration_device_id' => self::BROWSER,
        ]);

        $this->withCookies(['listen_demo_device' => self::BROWSER])
            ->postLocalizedRoute('login.submit', ['email' => 'evader@example.com', 'password' => 'Password123!']);

        $this->assertTrue($evader->fresh()->isBlocked());
        $this->assertGuest();
    }

    public function test_account_that_was_there_before_the_block_is_left_alone(): void
    {
        $family = User::factory()->create([
            'email' => 'family@example.com',
            'password' => Hash::make('Password123!'),
            'registration_device_id' => self::BROWSER,
        ]);
        $this->travel(1)->hours();
        $this->blockedMember();

        $this->withCookies(['listen_demo_device' => self::BROWSER])
            ->postLocalizedRoute('login.submit', ['email' => 'family@example.com', 'password' => 'Password123!']);

        $this->assertFalse($family->fresh()->isBlocked());
        $this->assertAuthenticatedAs($family);
    }

    public function test_other_browsers_are_not_affected(): void
    {
        $this->blockedMember();

        $this->register('stranger@example.com', 'dev_another_browser_abcdef0123456789');

        $this->assertFalse(User::where('email', 'stranger@example.com')->first()->isBlocked());
    }

    public function test_an_account_the_admin_unblocked_stays_unblocked(): void
    {
        $this->blockedMember();
        $this->register('second@example.com');
        $user = User::where('email', 'second@example.com')->first();

        app(BlockedUserService::class)->unblock($user);
        $user->forceFill(['password' => Hash::make('Password123!')])->save();

        $this->withCookies(['listen_demo_device' => self::BROWSER])
            ->postLocalizedRoute('login.submit', ['email' => 'second@example.com', 'password' => 'Password123!']);

        $this->assertFalse($user->fresh()->isBlocked());
    }

    public function test_unblocking_the_original_member_frees_the_browser(): void
    {
        $original = $this->blockedMember();
        app(BlockedUserService::class)->unblock($original);

        $this->register('second@example.com');

        $this->assertFalse(User::where('email', 'second@example.com')->first()->isBlocked());
    }

    public function test_the_rule_can_be_switched_off(): void
    {
        config(['listen.abuse.block_evasion' => false]);
        $this->blockedMember();

        $this->register('second@example.com');

        $this->assertFalse(User::where('email', 'second@example.com')->first()->isBlocked());
    }

    public function test_admins_are_never_blocked_by_it(): void
    {
        $this->blockedMember();
        $admin = User::factory()->create(['is_admin' => true, 'registration_device_id' => self::BROWSER]);

        $this->assertFalse(app(MultiAccountEnforcementService::class)->blockIfEvadingBlock($admin));
        $this->assertFalse($admin->fresh()->isBlocked());
    }

    public function test_a_caught_accounts_free_trial_is_cancelled_too_where_switched_on(): void
    {
        $stripe = new FakeStripeTrialGateway();
        $this->app->instance(StripeTrialGateway::class, $stripe);
        config(['listen.billing.stripe_trial_actions' => true]);
        $this->blockedMember();
        $this->travel(5)->minutes();
        $evader = User::factory()->create(['registration_device_id' => self::BROWSER]);
        $trial = BillingTrial::query()->create([
            'user_id' => $evader->id,
            'plan_id' => Plan::factory()->create(['slug' => 'creator-e'])->id,
            'status' => BillingTrial::STATUS_TRIALING,
            'stripe_subscription_id' => 'sub_evader',
            'started_at' => now(),
            'trial_ends_at' => now()->addWeek(),
        ]);

        app(MultiAccountEnforcementService::class)->enforceAfterAuth($evader->fresh());

        $this->assertSame(['sub_evader'], $stripe->canceled);
        $this->assertSame(BillingTrial::STATUS_CANCELED, $trial->fresh()->status);
    }

    // ──────────────────────────────────────────────────────────────────
    //  One-off catch-up command
    // ──────────────────────────────────────────────────────────────────

    public function test_command_lists_by_default_and_blocks_only_with_apply(): void
    {
        $this->blockedMember();
        $this->travel(5)->minutes();
        $evaders = User::factory()->count(3)->create(['registration_device_id' => self::BROWSER]);
        $bystander = User::factory()->create(['registration_device_id' => 'dev_another_browser_abcdef0123456789']);

        $this->artisan('listen:block-evading-accounts')
            ->expectsOutputToContain('3 account(s) found')
            ->assertSuccessful();
        $this->assertSame(0, User::whereIn('id', $evaders->pluck('id'))->where('is_blocked', true)->count(), 'listing changes nothing');

        $this->artisan('listen:block-evading-accounts --apply')
            ->expectsOutputToContain('3 account(s) handled')
            ->assertSuccessful();
        $this->assertSame(3, User::whereIn('id', $evaders->pluck('id'))->where('is_blocked', true)->count());
        $this->assertFalse($bystander->fresh()->isBlocked());
    }

    public function test_command_with_nothing_to_do(): void
    {
        $this->artisan('listen:block-evading-accounts')
            ->expectsOutputToContain('No accounts made after a block')
            ->assertSuccessful();
    }

    // ──────────────────────────────────────────────────────────────────

    private function blockedMember(): User
    {
        $member = User::factory()->create(['registration_device_id' => self::BROWSER]);
        app(BlockedUserService::class)->block($member, 'Repeated submission of abusive or prohibited language');

        return $member->fresh();
    }

    private function register(string $email, string $browser = self::BROWSER): void
    {
        $this->withCookies(['listen_demo_device' => $browser])
            ->postLocalizedRoute('register.submit', [
                'name' => 'New Member',
                'email' => $email,
                'password' => 'Password123!',
                'password_confirmation' => 'Password123!',
            ]);
    }
}
