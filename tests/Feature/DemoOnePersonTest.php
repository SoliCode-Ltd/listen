<?php

namespace Tests\Feature;

use App\Filament\Pages\SuspiciousUsers;
use App\Models\AgentConversation;
use App\Models\DemoLeftBehind;
use App\Models\DemoLeftBehindSignal;
use App\Models\DemoVoiceGeneration;
use App\Models\MemberDevice;
use App\Models\Plan;
use App\Models\ReferralCampaign;
use App\Models\SiteSetting;
use App\Models\Subscription;
use App\Models\User;
use App\Models\VideoDub;
use App\Models\Voice;
use App\Services\Agents\AgentCalls;
use App\Services\BlockedUserService;
use App\Services\DemoSamePerson;
use App\Services\DemoSuspects;
use App\Services\MultiAccountEnforcementService;
use App\Services\Referral\ReferralAbuseGuard;
use App\Services\UserDeletionService;
use App\Support\Agents\DemoAgent;
use App\Support\MailboxKey;
use App\Support\VideoDubbing;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Once;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Tests\TestCase;

/**
 * One person, one free demo (App\Services\DemoSamePerson): the text demo, its
 * Voice Design try, the free dub and the free agent call time all count the same
 * accounts as one person, an admin deleting one account gives none of it back,
 * and Admin → Suspicious Users shows the numbers the demo itself uses.
 */
class DemoOnePersonTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Plan::query()->firstOrCreate(['slug' => 'free'], [
            'name' => 'Free', 'price_cents' => 0, 'monthly_minutes' => 10, 'monthly_chars' => 10000,
            'monthly_requests' => 60, 'max_chars_per_request' => 1200, 'voice_tier' => 'standard',
            'tts_provider_policy' => 'google_cloud', 'dialogue_enabled' => false, 'custom_prompt_enabled' => false, 'is_active' => true,
        ]);
        Voice::query()->firstOrCreate(['internal_voice_id' => 'en-US-Wavenet-C'], [
            'language' => 'en', 'gender' => 'female', 'tier' => 'standard', 'display_name' => 'Emma',
            'provider' => 'google_cloud', 'provider_voice_id' => 'en-US-Wavenet-C', 'is_active' => true,
        ]);
    }

    // ── One inbox ───────────────────────────────────────────────────────────────

    public function test_the_mailbox_key_is_the_inbox_an_address_reaches(): void
    {
        $this->assertSame('janedoe@gmail.com', MailboxKey::for('Jane.Doe+demo2@Gmail.com'));
        $this->assertSame('janedoe@gmail.com', MailboxKey::for('j.a.n.e.d.o.e@googlemail.com'));
        $this->assertSame('omar@outlook.com', MailboxKey::for(' omar+listen@outlook.com '));
        // Dots matter everywhere but Gmail: two people at one company.
        $this->assertSame('jo.smith@acme.example', MailboxKey::for('jo.smith@acme.example'));
        $this->assertNotSame(MailboxKey::for('jo.smith@acme.example'), MailboxKey::for('josmith@acme.example'));
        // Odd addresses are kept as they are, never emptied.
        $this->assertSame('+tag@example.com', MailboxKey::for('+tag@example.com'));
        $this->assertSame('...@gmail.com', MailboxKey::for('...@gmail.com'));
        $this->assertNull(MailboxKey::for(null));
        $this->assertNull(MailboxKey::for('  '));
    }

    public function test_an_account_keeps_its_mailbox_key_up_to_date(): void
    {
        $user = User::factory()->create(['email' => 'Sam.Smith+one@gmail.com']);
        $this->assertSame('samsmith@gmail.com', $user->fresh()->email_key);

        $user->update(['email' => 'sam@example.org']);
        $this->assertSame('sam@example.org', $user->fresh()->email_key);
    }

    public function test_the_migration_fills_in_the_mailbox_key_of_existing_accounts(): void
    {
        $old = User::factory()->create(['email' => 'old.member+x@gmail.com']);
        DB::table('users')->update(['email_key' => null]);

        (require database_path('migrations/2026_11_25_120000_add_email_key_to_users_table.php'))->up();

        $this->assertSame('oldmember@gmail.com', DB::table('users')->where('id', $old->id)->value('email_key'));
    }

    public function test_gmail_dots_and_tags_share_one_demo_whatever_the_browser_network_or_date(): void
    {
        $first = $this->member('198.51.100.10', 'browser_alias_first_0123456789abcd', 'jane.doe@gmail.com');
        $this->usedDemo($first, 300);

        $this->travel(5)->weeks();
        $second = $this->member('203.0.113.99', 'browser_alias_second_0123456789abc', 'janedoe+demo2@googlemail.com');

        $this->quota($second, 'browser_alias_second_0123456789abc')->assertJsonPath('quota.remaining_chars', 0);
    }

    public function test_a_plus_tag_at_any_provider_shares_one_demo(): void
    {
        $first = $this->member('198.51.100.12', 'browser_plus_first_0123456789abcde', 'omar@outlook.com');
        $this->usedDemo($first, 300);
        $this->travel(5)->weeks();
        $second = $this->member('203.0.113.97', 'browser_plus_second_0123456789abcd', 'omar+listen@outlook.com');

        $this->quota($second, 'browser_plus_second_0123456789abcd')->assertJsonPath('quota.remaining_chars', 0);
    }

    public function test_two_people_at_one_company_keep_their_own_demo(): void
    {
        $first = $this->member('198.51.100.13', 'browser_co_first_0123456789abcdefg', 'jo.smith@acme.example');
        $this->usedDemo($first, 300);
        $this->travel(5)->weeks();
        $second = $this->member('203.0.113.96', 'browser_co_second_0123456789abcdef', 'josmith@acme.example');

        $this->quota($second, 'browser_co_second_0123456789abcdef')->assertJsonPath('quota.remaining_chars', 300);
    }

    public function test_an_inbox_chained_to_another_browser_still_counts_that_browsers_demo(): void
    {
        // A, then C in A's browser (C spends the demo), then B: A's inbox in a new browser.
        $a = $this->member('198.51.100.14', 'browser_chain_a_0123456789abcdefgh', 'chain@gmail.com');
        $this->travel(2)->weeks();
        $c = $this->member('203.0.113.14', 'browser_chain_a_0123456789abcdefgh', 'someone.else@example.org');
        $this->usedDemo($c, 300);
        $this->travel(2)->weeks();
        $b = $this->member('192.0.2.14', 'browser_chain_b_0123456789abcdefgh', 'c.h.a.i.n+2@gmail.com');

        $this->quota($b, 'browser_chain_b_0123456789abcdefgh')->assertJsonPath('quota.remaining_chars', 0);
    }

    public function test_someone_typing_your_address_into_an_account_they_never_confirm_cannot_use_up_your_demo(): void
    {
        // The attacker's own account, which has spent its demo, in the attacker's browser.
        $attacker = $this->member('198.51.100.16', 'browser_griefer_0123456789abcdefgh', 'griefer@example.org');
        $this->usedDemo($attacker, 300);
        // The victim: a confirmed Gmail account that has not used its demo.
        $victim = $this->member('203.0.113.16', 'browser_victim_0123456789abcdefghi', 'victim.person@gmail.com');

        // In the attacker's browser, an account with a +tag of the victim's address, never confirmed.
        User::factory()->create(['email' => 'victimperson+x@gmail.com', 'email_verified_at' => null,
            'registration_device_id' => 'browser_griefer_0123456789abcdefgh', 'last_seen_device_id' => 'browser_griefer_0123456789abcdefgh']);

        $this->quota($victim, 'browser_victim_0123456789abcdefghi')->assertJsonPath('quota.remaining_chars', 300);
        $this->assertArrayNotHasKey($victim->id, app(DemoSuspects::class)->all());
    }

    public function test_a_referral_between_one_inbox_is_held_for_review(): void
    {
        $referrer = $this->member('198.51.100.15', 'browser_ref_a_0123456789abcdefghij', 'friend@gmail.com');
        $this->travel(2)->days();
        $referred = $this->member('203.0.113.15', 'browser_ref_b_0123456789abcdefghij', 'f.r.i.e.n.d+1@gmail.com');
        $campaign = ReferralCampaign::query()->create(['slug' => 'one-inbox', 'name' => 'Test', 'is_active' => true,
            'qualification_step' => 'signup', 'reward_chars' => 100, 'visit_window_days' => 30]);

        $verdict = app(ReferralAbuseGuard::class)->evaluate($referrer, $referred, $campaign);

        $this->assertContains('shared_mailbox', $verdict['indicators']);
        $this->assertNotSame('approve', $verdict['verdict']);
    }

    // ── One rule for the free dub ───────────────────────────────────────────────

    public function test_the_free_dub_counts_accounts_made_in_a_row_on_one_network_like_the_text_demo(): void
    {
        $first = $this->member('198.51.100.20', 'browser_dub_first_0123456789abcdef', 'dub1@example.com');
        VideoDub::query()->create(['user_id' => $first->id, 'status' => VideoDub::READY, 'is_free' => true,
            'dialect' => 'ar-EG', 'voice' => 'Kore', 'video_seconds' => 20, 'browser' => 'browser_dub_first_0123456789abcdef']);

        $this->travel(5)->minutes();
        $second = $this->member('198.51.100.20', 'browser_dub_second_0123456789abcde', 'dub2@example.com');

        $this->assertTrue(VideoDubbing::freeUsed($second, $this->browserRequest('browser_dub_second_0123456789abcde')));
    }

    public function test_the_free_dub_of_two_people_on_one_network_days_apart_is_each_their_own(): void
    {
        $first = $this->member('198.51.100.21', 'browser_dub_far_a_0123456789abcdef', 'dubfar1@example.com');
        VideoDub::query()->create(['user_id' => $first->id, 'status' => VideoDub::READY, 'is_free' => true,
            'dialect' => 'ar-EG', 'voice' => 'Kore', 'video_seconds' => 20, 'browser' => 'browser_dub_far_a_0123456789abcdef']);

        $this->travel(3)->days();
        $second = $this->member('198.51.100.21', 'browser_dub_far_b_0123456789abcdef', 'dubfar2@example.com');

        $this->assertFalse(VideoDubbing::freeUsed($second, $this->browserRequest('browser_dub_far_b_0123456789abcdef')));
    }

    // ── One rule for the free agent call ────────────────────────────────────────

    public function test_a_second_account_in_the_same_browser_gets_no_new_free_call_seconds(): void
    {
        $this->agentsOn();
        $first = $this->member('198.51.100.30', 'browser_call_first_0123456789abcde', 'call1@example.com');
        $this->demoCall($first, 'browser_call_first_0123456789abcde')->assertOk();
        AgentConversation::query()->update(['settled_at' => now(), 'billed_seconds' => 120, 'status' => 'ended']);

        $second = $this->member('198.51.100.30', 'browser_call_first_0123456789abcde', 'call2@example.com');

        $this->demoCall($second, 'browser_call_first_0123456789abcde')->assertStatus(422)->assertJsonPath('reason', 'free_used');
        $this->assertSame(0, app(AgentCalls::class)->freeTestSecondsLeft($second->id));
    }

    public function test_two_free_calls_started_at_the_same_moment_cannot_both_have_the_free_seconds(): void
    {
        $this->agentsOn();
        $member = $this->member('198.51.100.31', 'browser_call_race_0123456789abcdef', 'race@example.com');
        $agent = DemoAgent::agentFor($member, DemoAgent::PRESETS[0], 'en');

        // The second request arrives while the first is being kept.
        $inner = null;
        AgentConversation::creating(function () use (&$inner, $agent): void {
            if ($inner === null) {
                $inner = false;
                $inner = app(AgentCalls::class)->start($agent, 'test', null, ['visitor' => 'demo-x', 'free_only' => true]);
            }
        });
        $outer = app(AgentCalls::class)->start($agent, 'test', null, ['visitor' => 'demo-x', 'free_only' => true]);

        $this->assertTrue($outer['ok']);
        $this->assertFalse($inner['ok']);
        $this->assertSame(1, AgentConversation::query()->where('free', true)->count());
    }

    public function test_a_member_who_pays_keeps_their_own_free_call_seconds(): void
    {
        $this->agentsOn();
        $first = $this->member('198.51.100.32', 'browser_call_paid_0123456789abcdef', 'paid1@example.com');
        $this->demoCall($first, 'browser_call_paid_0123456789abcdef')->assertOk();
        AgentConversation::query()->update(['settled_at' => now(), 'billed_seconds' => 120, 'status' => 'ended']);

        $payer = $this->member('198.51.100.32', 'browser_call_paid_0123456789abcdef', 'paid2@example.com');
        $this->paying($payer);

        $this->assertSame(120, app(AgentCalls::class)->freeTestSecondsLeft($payer->id));
    }

    // ── Deleting an account gives nothing back ──────────────────────────────────

    public function test_deleting_one_account_does_not_give_its_twin_a_fresh_demo(): void
    {
        $first = $this->member('198.51.100.40', 'browser_twin_0123456789abcdefghijk', 'twin1@example.com');
        $this->usedDemo($first, 300);
        $this->travel(2)->weeks();
        $second = $this->member('203.0.113.40', 'browser_twin_0123456789abcdefghijk', 'twin2@example.com');
        $this->quota($second, 'browser_twin_0123456789abcdefghijk')->assertJsonPath('quota.remaining_chars', 0);

        // What Suspicious Users → Delete User does.
        $this->assertNull(app(UserDeletionService::class)->delete($first, null, withDemoHistory: true));
        $this->assertNull(User::query()->find($first->id));
        $this->assertSame(0, DemoVoiceGeneration::query()->where('user_id', $first->id)->count());

        $this->quota($second->fresh(), 'browser_twin_0123456789abcdefghijk')->assertJsonPath('quota.remaining_chars', 0);
        // A new account later in that browser does not get it back either.
        $this->travel(1)->week();
        $third = $this->member('192.0.2.40', 'browser_twin_0123456789abcdefghijk', 'twin3@example.com');
        $this->quota($third, 'browser_twin_0123456789abcdefghijk')->assertJsonPath('quota.remaining_chars', 0);
    }

    public function test_a_deleted_account_is_recognised_by_its_inbox_device_and_network_as_well(): void
    {
        $gone = $this->member('198.51.100.41', 'browser_gone_0123456789abcdefghijk', 'gone.member@gmail.com');
        MemberDevice::query()->create(['user_id' => $gone->id, 'kind' => MemberDevice::DEVICE, 'signal' => str_repeat('a', 64), 'touch' => false]);
        $this->usedDemo($gone, 250);
        app(UserDeletionService::class)->delete($gone, null, withDemoHistory: true);

        // The same inbox, weeks later, anywhere.
        $this->travel(3)->weeks();
        $inbox = $this->member('203.0.113.41', 'browser_inbox_0123456789abcdefghij', 'gonemember+again@gmail.com');
        $this->quota($inbox, 'browser_inbox_0123456789abcdefghij')->assertJsonPath('quota.used_chars', 250);

        // The same computer, another window, within its window.
        $device = $this->member('192.0.2.41', 'browser_device_0123456789abcdefghi', 'device@example.org');
        $this->flushHeaders();
        $this->defaultCookies = [];
        $this->withCredentials()->withCookies(['listen_demo_device' => 'browser_device_0123456789abcdefghi'])
            ->withHeaders(['X-Client-Device' => str_repeat('a', 64)])
            ->actingAs($device)->postJsonLocalizedRoute('demo.quota')
            ->assertJsonPath('quota.used_chars', 250);
    }

    public function test_what_is_kept_of_a_deleted_account_is_hashes_never_its_details(): void
    {
        $gone = $this->member('198.51.100.42', 'browser_hashes_0123456789abcdefghi', 'private.person@gmail.com');
        $this->usedDemo($gone, 120);

        app(UserDeletionService::class)->delete($gone, null, withDemoHistory: true);

        $left = DemoLeftBehind::query()->sole();
        $this->assertSame(120, $left->demo_chars);
        $stored = DemoLeftBehindSignal::query()->pluck('signal')->implode(' ');
        foreach (['198.51.100.42', 'browser_hashes_0123456789abcdefghi', 'privateperson@gmail.com', 'private.person@gmail.com'] as $detail) {
            $this->assertStringNotContainsString($detail, $stored);
        }
        $this->assertSame(64, strlen(DemoLeftBehindSignal::query()->value('signal')));
        // Keyed with the app's secret: a plain hash of a network address could be worked back.
        $this->assertNotContains(hash('sha256', '198.51.100.42'), DemoLeftBehindSignal::query()->pluck('signal')->all());
    }

    public function test_an_account_that_used_none_of_the_demo_leaves_nothing_behind(): void
    {
        $unused = $this->member('198.51.100.43', 'browser_unused_0123456789abcdefghi', 'unused@example.com');

        app(UserDeletionService::class)->delete($unused, null, withDemoHistory: true);

        $this->assertSame(0, DemoLeftBehind::query()->count());
    }

    public function test_a_deleted_accounts_voice_design_try_free_dub_and_call_seconds_still_count(): void
    {
        $this->agentsOn();
        $gone = $this->member('198.51.100.44', 'browser_all_gone_0123456789abcdefg', 'all.gone@example.com');
        DemoVoiceGeneration::query()->create($this->demoRow($gone, 40) + ['metadata' => ['voice_design' => ['description' => 'x']]]);
        VideoDub::query()->create(['user_id' => $gone->id, 'status' => VideoDub::READY, 'is_free' => true,
            'dialect' => 'ar-EG', 'voice' => 'Kore', 'video_seconds' => 20]);
        $this->demoCall($gone, 'browser_all_gone_0123456789abcdefg')->assertOk();
        AgentConversation::query()->update(['settled_at' => now(), 'billed_seconds' => 90, 'status' => 'ended']);

        app(UserDeletionService::class)->delete($gone, null, withDemoHistory: true);

        $this->travel(1)->week();
        $twin = $this->member('203.0.113.44', 'browser_all_gone_0123456789abcdefg', 'twin.again@example.com');
        $request = $this->browserRequest('browser_all_gone_0123456789abcdefg', $twin);
        $person = app(DemoSamePerson::class);
        $this->assertTrue($person->designUsed($twin, $request));
        $this->assertTrue(VideoDubbing::freeUsed($twin, $request));
        $this->assertSame(30, app(AgentCalls::class)->freeTestSecondsLeft($twin->id));
    }

    // ── Before the new tables exist (deployed, migration not run yet) ───────────

    public function test_everything_works_before_the_new_migrations_have_run(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            // Dropping tables commits the test's transaction there: the rows of later tests would be wrong.
            $this->markTestSkipped('Runs where table changes are rolled back with the test (the suite\'s SQLite).');
        }
        Schema::drop('demo_left_behind_signals');
        Schema::drop('demo_left_behind');
        Schema::table('users', function ($table): void {
            $table->dropIndex('users_email_key_index');
            $table->dropColumn('email_key');
        });
        Once::flush();

        try {
            $first = $this->member('198.51.100.50', 'browser_before_0123456789abcdefghi', 'before@gmail.com');
            $this->usedDemo($first, 300);
            $second = $this->member('203.0.113.50', 'browser_before_0123456789abcdefghi', 'b.e.f.o.r.e@gmail.com');
            $this->quota($second, 'browser_before_0123456789abcdefghi')->assertJsonPath('quota.remaining_chars', 0);

            $this->assertNull(app(UserDeletionService::class)->delete($first, null, withDemoHistory: true));
            $this->assertIsArray(app(DemoSuspects::class)->all());
            $admin = User::factory()->create(['is_admin' => true]);
            $this->actingAs($admin);
            Livewire::test(SuspiciousUsers::class)->assertOk();
        } finally {
            // Put them back: on MySQL a dropped table is not rolled back with the test.
            (require database_path('migrations/2026_11_25_120000_add_email_key_to_users_table.php'))->up();
            (require database_path('migrations/2026_11_25_120100_create_demo_left_behind_tables.php'))->up();
            Once::flush();
        }
    }

    // ── Admin → Suspicious Users ────────────────────────────────────────────────

    public function test_the_page_lists_accounts_the_demo_links_through_the_device(): void
    {
        $device = 'aa11bb22cc33dd44ee55ff66aa11bb22cc33dd44ee55ff66aa11bb22cc33dd44';
        $first = $this->member('198.51.100.60', 'browser_dev_first_0123456789abcdef', 'dev1@example.com');
        MemberDevice::query()->create(['user_id' => $first->id, 'kind' => MemberDevice::DEVICE, 'signal' => $device, 'touch' => false]);
        $this->travel(10)->minutes();
        $second = $this->member('203.0.113.60', 'browser_dev_second_0123456789abcde', 'dev2@example.com');
        MemberDevice::query()->create(['user_id' => $second->id, 'kind' => MemberDevice::DEVICE, 'signal' => $device, 'touch' => false]);

        $suspects = app(DemoSuspects::class)->all();

        $this->assertSame([DemoSuspects::DEVICE], $suspects[$second->id] ?? null);
        $this->assertSame([DemoSuspects::DEVICE], $suspects[$first->id] ?? null);
    }

    public function test_strangers_on_one_network_weeks_apart_are_not_listed_or_counted_together(): void
    {
        $first = $this->member('100.70.2.2', 'browser_gt_first_0123456789abcdefg', 'gt1@example.com');
        $this->usedDemo($first, 300);
        $this->travel(4)->weeks();
        $second = $this->member('100.70.2.2', 'browser_gt_second_0123456789abcdef', 'gt2@example.com');
        $this->usedDemo($second, 300);

        $this->assertArrayNotHasKey($second->id, app(DemoSuspects::class)->all());
        $this->assertSame(300, app(DemoSamePerson::class)->demoUsage($second)['chars']);
    }

    public function test_the_page_shows_the_shared_demo_as_the_demo_counts_it(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $first = $this->member('198.51.100.61', 'browser_page_0123456789abcdefghijk', 'page1@example.com');
        $this->usedDemo($first, 200);
        $second = $this->member('203.0.113.61', 'browser_page_0123456789abcdefghijk', 'page2@example.com');
        $this->usedDemo($second, 50);
        $loner = $this->member('192.0.2.61', 'browser_loner_0123456789abcdefghij', 'loner@example.com');

        $this->actingAs($admin);
        Livewire::test(SuspiciousUsers::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$first, $second])
            ->assertCanNotSeeTableRecords([$loner])
            ->assertTableColumnStateSet('linked_count', 1, (string) $first->id)
            ->assertTableColumnStateSet('ip_group_total_chars', 250, (string) $first->id)
            ->assertTableColumnStateSet('demo_chars_used', 200, (string) $first->id)
            ->assertTableColumnStateSet('suspicion_signals', 'Browser', (string) $first->id);

        $this->assertSame('2', SuspiciousUsers::getNavigationBadge());
    }

    public function test_selected_suspicious_accounts_can_be_deleted_together(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'registration_device_id' => 'browser_bulk_0123456789abcdefghijk']);
        $a = $this->member('198.51.100.62', 'browser_bulk_0123456789abcdefghijk', 'bulk1@example.com');
        $this->usedDemo($a, 300);
        $b = $this->member('198.51.100.62', 'browser_bulk_0123456789abcdefghijk', 'bulk2@example.com');
        $payer = $this->member('198.51.100.62', 'browser_bulk_0123456789abcdefghijk', 'payer@example.com');
        $this->paying($payer);
        $keeper = $this->member('198.51.100.62', 'browser_bulk_0123456789abcdefghijk', 'keeper@example.com');

        $this->actingAs($admin);
        Livewire::test(SuspiciousUsers::class)
            ->callTableBulkAction('deleteSelected', [$a, $b, $payer, $admin])
            ->assertNotified();

        $this->assertNull(User::query()->find($a->id));
        $this->assertNull(User::query()->find($b->id));
        $this->assertNotNull(User::query()->find($payer->id), 'pays or has paid: kept for the admin to decide one by one');
        $this->assertNotNull(User::query()->find($admin->id), 'admins and the admin\'s own account are never deleted');
        // What they used still counts for the account left in that browser.
        $this->quota($keeper, 'browser_bulk_0123456789abcdefghijk')->assertJsonPath('quota.remaining_chars', 0);
    }

    public function test_delete_user_on_the_page_keeps_the_demo_counted_for_the_twin(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $first = $this->member('198.51.100.63', 'browser_row_del_0123456789abcdefgh', 'rowdel1@example.com');
        $this->usedDemo($first, 300);
        $second = $this->member('203.0.113.63', 'browser_row_del_0123456789abcdefgh', 'rowdel2@example.com');

        $this->actingAs($admin);
        Livewire::test(SuspiciousUsers::class)->callTableAction('deleteUser', (string) $first->id)->assertNotified();

        $this->assertNull(User::query()->find($first->id));
        $this->quota($second, 'browser_row_del_0123456789abcdefgh')->assertJsonPath('quota.remaining_chars', 0);
    }

    public function test_block_and_unblock_on_the_page_do_what_they_say(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $first = $this->member('198.51.100.64', 'browser_row_blk_0123456789abcdefgh', 'rowblk1@example.com');
        $this->member('203.0.113.64', 'browser_row_blk_0123456789abcdefgh', 'rowblk2@example.com');

        $this->actingAs($admin);
        Livewire::test(SuspiciousUsers::class)->callTableAction('blockUser', (string) $first->id);
        $this->assertTrue($first->fresh()->isBlocked());
        $this->assertSame($admin->id, (int) $first->fresh()->blocked_by);

        Livewire::test(SuspiciousUsers::class)->callTableAction('unblockUser', (string) $first->id);
        $this->assertFalse($first->fresh()->isBlocked());
        $this->assertTrue((bool) $first->fresh()->multi_account_whitelisted, 'an admin unblock also trusts the account');
    }

    public function test_the_blocked_users_audit_never_undoes_an_admins_own_block(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $abuser = $this->member('198.51.100.65', 'browser_admin_block_0123456789abcd', 'abuser@example.com');
        $enforcement = app(MultiAccountEnforcementService::class);
        app(BlockedUserService::class)->block($abuser, $enforcement->blockReasonEn(), $admin,
            ['en' => $enforcement->blockReasonEn(), 'ar' => $enforcement->blockReasonAr()]);

        $this->artisan('listen:audit-blocked', ['--fix' => true])->assertSuccessful();

        $this->assertTrue($abuser->fresh()->isBlocked());
        $this->assertFalse((bool) $abuser->fresh()->multi_account_whitelisted);
    }

    public function test_the_multi_account_block_says_device_not_network(): void
    {
        $this->assertStringContainsString('same device', MultiAccountEnforcementService::BLOCK_REASON_EN);
        $this->assertStringContainsString('multiple accounts', MultiAccountEnforcementService::BLOCK_REASON_EN, 'the blocked-users audit finds it by these words');
        $this->assertStringContainsString('حسابات متعددة', MultiAccountEnforcementService::BLOCK_REASON_AR);
    }

    // ── helpers ─────────────────────────────────────────────────────────────────

    private function member(string $ip, string $browser, string $email): User
    {
        return User::factory()->create([
            'email' => $email,
            'registration_ip' => $ip,
            'last_login_ip' => $ip,
            'registration_device_id' => $browser,
            'last_seen_device_id' => $browser,
            'email_verified_at' => now(),
        ]);
    }

    private function quota(User $member, string $browser): TestResponse
    {
        $this->flushHeaders();
        $this->defaultCookies = [];

        return $this->withCredentials()->withCookies(['listen_demo_device' => $browser])
            ->actingAs($member)
            ->postJsonLocalizedRoute('demo.quota')
            ->assertOk();
    }

    private function browserRequest(string $browser, ?User $user = null): Request
    {
        $request = Request::create('/dub-my-video', 'POST', [], ['listen_demo_device' => $browser]);
        if ($user !== null) {
            $request->setUserResolver(fn () => $user);
        }

        return $request;
    }

    private function paying(User $member): void
    {
        $plan = Plan::factory()->create(['slug' => 'starter_'.$member->id, 'price_cents' => 900, 'is_active' => true]);
        Subscription::query()->create(['user_id' => $member->id, 'plan_id' => $plan->id, 'status' => 'active',
            'current_period_start' => now(), 'current_period_end' => now()->addMonth()]);
    }

    private function agentsOn(): void
    {
        config(['services.gemini_tts.api_key' => 'test-key']);
        Http::fake(['*auth_tokens' => Http::response(['name' => 'auth_tokens/live-key'])]);
        SiteSetting::set('agents_free_test_seconds', '120', 'agents');
    }

    private function demoCall(User $member, string $browser): TestResponse
    {
        $this->flushHeaders();
        $this->defaultCookies = [];

        return $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class, LaravelLocalizationViewPath::class, ValidateCsrfToken::class])
            ->withCredentials()->withCookies(['listen_demo_device' => $browser])
            ->actingAs($member)->postJson($this->localizedRoute('demo.agent-call'), ['preset' => DemoAgent::PRESETS[0]]);
    }

    private function usedDemo(User $member, int $chars): void
    {
        DemoVoiceGeneration::query()->create($this->demoRow($member, $chars));
    }

    /** @return array<string, mixed> */
    private function demoRow(User $member, int $chars): array
    {
        return [
            'user_id' => $member->id, 'primary_identity_hash' => 'u:'.$member->id,
            'input_text' => str_repeat('x', $chars), 'speech_text' => str_repeat('x', $chars),
            'source_language' => 'en', 'target_language' => 'en', 'translate' => false,
            'voice_name' => 'en-US-Wavenet-C', 'voice_provider' => 'google_cloud', 'voice_tier' => 'standard',
            'billable_chars' => $chars,
        ];
    }
}
