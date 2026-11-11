<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\DemoFraudSignalResource\Pages\ListDemoFraudSignals;
use App\Filament\Resources\ReferralConversionResource\Pages\ListReferralConversions;
use App\Models\DemoFraudSignal;
use App\Models\ReferralCampaign;
use App\Models\ReferralConversion;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Admin list columns that show a stored list as one line. Filament 5 formats a list item by
 * item (Filament 3 joined it first), so these show the line they always meant to, once.
 */
class ListColumnsAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->admin = User::factory()->create(['is_admin' => true]);
    }

    public function test_a_referrals_flags_are_listed_once_up_to_three(): void
    {
        $campaign = ReferralCampaign::query()->create(['slug' => 'flags', 'name' => 'Test', 'is_active' => true,
            'qualification_step' => 'signup', 'reward_chars' => 100, 'visit_window_days' => 30]);
        $flagged = ReferralConversion::query()->create([
            'campaign_id' => $campaign->id, 'referrer_user_id' => User::factory()->create()->id, 'referred_user_id' => User::factory()->create()->id,
            'status' => 'flagged', 'qualification_step' => 'signup',
            'fraud_indicators' => ['shared_registration_ip', 'shared_mailbox', 'shared_device_id', 'shared_device'],
        ]);
        $clean = ReferralConversion::query()->create([
            'campaign_id' => $campaign->id, 'referrer_user_id' => User::factory()->create()->id, 'referred_user_id' => User::factory()->create()->id,
            'status' => 'approved', 'qualification_step' => 'signup', 'fraud_indicators' => [],
        ]);

        $html = Livewire::actingAs($this->admin)->test(ListReferralConversions::class)
            ->assertCanSeeTableRecords([$flagged, $clean])
            ->assertSee('shared_registration_ip, shared_mailbox, shared_device_id')
            ->html();

        $this->assertSame(1, substr_count($html, 'shared_mailbox'));
        $this->assertStringNotContainsString('shared_device,', $html, 'only the first three flags');
    }

    public function test_a_demo_signals_data_is_one_line_cut_as_a_whole(): void
    {
        $signal = DemoFraudSignal::query()->create([
            'identity_hash' => str_repeat('a', 64), 'signal_type' => 'ip_fingerprint_flood',
            'signal_data' => ['ip' => '203.0.113.9', 'hits' => 7, 'seen' => ['fp1', 'fp2']],
        ]);

        $html = Livewire::actingAs($this->admin)->test(ListDemoFraudSignals::class)
            ->assertCanSeeTableRecords([$signal])
            // As Filament 3 showed it: the values, a nested one as JSON.
            ->assertSee('203.0.113.9, 7, ["fp1","fp2"]')
            ->html();

        $this->assertSame(1, substr_count($html, '203.0.113.9'));

        // Cut at 50 as one line (Filament 5 would cut each value at 50 and show them all).
        $long = DemoFraudSignal::query()->create([
            'identity_hash' => str_repeat('b', 64), 'signal_type' => 'vpn_detected',
            'signal_data' => ['agent' => str_repeat('x', 40), 'referrer' => str_repeat('y', 40)],
        ]);
        Livewire::actingAs($this->admin)->test(ListDemoFraudSignals::class)
            ->assertCanSeeTableRecords([$long])
            ->assertSee(str_repeat('x', 40).', '.str_repeat('y', 8))
            ->assertDontSee(str_repeat('y', 40));
    }
}
