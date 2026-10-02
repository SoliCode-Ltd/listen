<?php

namespace Tests\Feature;

use App\Filament\Pages\Dashboard;
use App\Models\User;
use App\Services\Analytics\BusinessStatsExporter;
use App\Services\Analytics\BusinessStatsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

class BusinessStatsDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function seedBusinessData(): array
    {
        $free = DB::table('plans')->insertGetId([
            'slug' => 'free-test', 'name' => 'Free Test', 'price_cents' => 0, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $starter = DB::table('plans')->insertGetId([
            'slug' => 'starter-test', 'name' => 'Starter Test', 'price_cents' => 900, 'is_active' => true,
            'stripe_price_monthly' => 'price_starter_test_m', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $admin = User::factory()->create(['is_admin' => true, 'created_at' => now()->subDays(3)]);
        $active = User::factory()->create(['created_at' => now()->subDays(40)]);
        $idle = User::factory()->create(['created_at' => now()->subDays(2)]);
        $paying = User::factory()->create(['created_at' => now()->subDays(10)]);

        foreach ([$admin, $active, $idle] as $user) {
            DB::table('subscriptions')->insert(['user_id' => $user->id, 'plan_id' => $free, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('subscriptions')->insert(['user_id' => $paying->id, 'plan_id' => $starter, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('billing_subscriptions')->insert([
            'user_id' => $paying->id, 'type' => 'default', 'stripe_id' => 'sub_test_1', 'stripe_status' => 'active',
            'stripe_price' => 'price_starter_test_m', 'created_at' => now()->subDays(10), 'updated_at' => now(),
        ]);

        $generation = fn (User $user, string $status, $when) => DB::table('audio_generations')->insert([
            'user_id' => $user->id, 'input_text' => 'hello', 'target_language' => 'ar', 'status' => $status,
            'tts_provider' => 'google_cloud', 'tts_latency_ms' => 1500, 'billable_chars' => 100,
            'duration_seconds' => 60, 'tts_cost_estimate_usd' => 0.01, 'created_at' => $when, 'updated_at' => $when,
        ]);
        $generation($active, 'done', now()->subDays(5));
        $generation($active, 'failed', now()->subDays(4));
        $generation($paying, 'done', now()->subDays(1));
        $generation($admin, 'done', now()->subDays(1));

        return compact('admin');
    }

    public function test_snapshot_counts_members_and_excludes_admins(): void
    {
        $this->seedBusinessData();

        $s = app(BusinessStatsService::class)->build(12);

        $this->assertSame([], $s['errors']);
        $this->assertSame(3, $s['members']['total']);
        $this->assertSame(1, $s['members']['admins']);
        $this->assertSame(2, $s['members']['new_30d']);
        $this->assertSame(2, $s['engagement']['activated']);
        $this->assertSame(2, $s['engagement']['active_30d']);
        $this->assertSame(3, $s['generations']['last_30d']);
        $this->assertSame(1, $s['generations']['failed_30d']);
        $this->assertSame(1, $s['revenue']['paying_stripe']);
        $this->assertSame(900, $s['revenue']['mrr_cents']);
        $this->assertSame(1, $s['revenue']['members_on_paid_plans']);
        $this->assertCount(12, $s['monthly']);
        $this->assertSame(3, array_sum(array_column($s['monthly'], 'signups')));
        $this->assertSame('google_cloud', $s['providers'][0]['provider']);
        $this->assertSame(3, $s['providers'][0]['generations']);
    }

    public function test_period_is_limited_to_known_values(): void
    {
        $this->assertSame(6, BusinessStatsService::normalizeMonths('6'));
        $this->assertSame(BusinessStatsService::DEFAULT_MONTHS, BusinessStatsService::normalizeMonths('999'));
        $this->assertSame(BusinessStatsService::DEFAULT_MONTHS, BusinessStatsService::normalizeMonths(null));
    }

    public function test_excel_report_has_every_sheet(): void
    {
        $this->seedBusinessData();
        $path = tempnam(sys_get_temp_dir(), 'report-test-');

        app(BusinessStatsExporter::class)->toXlsx(app(BusinessStatsService::class)->build(6), $path);

        $reader = new Reader;
        $reader->open($path);
        $names = [];
        $summary = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            $names[] = $sheet->getName();
            if ($sheet->getName() === 'Summary') {
                foreach ($sheet->getRowIterator() as $row) {
                    $values = $row->toArray();
                    $summary[$values[1]] = $values[2];
                }
            }
        }
        $reader->close();
        unlink($path);

        $this->assertSame(['Summary', 'Monthly', 'Cohorts', 'Plans', 'Providers (30d)', 'Breakdowns'], $names);
        $this->assertEquals(3, $summary['Total members (excl. admins)']);
        $this->assertEquals(9, $summary['Est. MRR (USD, list price)']);
    }

    public function test_admin_dashboard_renders_and_downloads_report(): void
    {
        ['admin' => $admin] = $this->seedBusinessData();

        $this->actingAs($admin)->get('/admin')->assertOk()->assertSee('Download report (Excel)');

        Livewire::actingAs($admin)
            ->test(Dashboard::class)
            ->callAction('downloadReport')
            ->assertFileDownloaded();
    }

    public function test_dashboard_widgets_render(): void
    {
        ['admin' => $admin] = $this->seedBusinessData();
        $this->actingAs($admin);

        foreach ([
            \App\Filament\Widgets\BusinessGrowthKpis::class => 'Members',
            \App\Filament\Widgets\BusinessRevenueKpis::class => 'Est. MRR',
            \App\Filament\Widgets\BusinessProductKpis::class => 'Failure rate',
            \App\Filament\Widgets\BusinessBreakdowns::class => 'Signup cohorts',
            \App\Filament\Widgets\BusinessGrowthChart::class => 'Growth',
            \App\Filament\Widgets\BusinessUsageChart::class => 'Usage',
            \App\Filament\Widgets\BusinessRevenueChart::class => 'Monetisation',
            \App\Filament\Widgets\BusinessCostChart::class => 'TTS cost',
        ] as $widget => $text) {
            Livewire::test($widget, ['filters' => ['months' => 6]])->assertSee($text);
        }
    }
}
