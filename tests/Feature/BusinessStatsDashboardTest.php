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

        // Gemini logs no cost: 60 s of audio and 400 characters are priced from config/analytics.php.
        DB::table('audio_generations')->insert([
            'user_id' => $paying->id, 'input_text' => 'hello', 'target_language' => 'ar', 'status' => 'done',
            'tts_provider' => 'gemini', 'tts_model' => 'gemini-2.5-flash-preview-tts', 'tts_latency_ms' => 20000,
            'billable_chars' => 400, 'duration_seconds' => 60, 'tts_cost_estimate_usd' => 0,
            'created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2),
        ]);
        DB::table('demo_voice_generations')->insert([
            'primary_identity_hash' => 'demo-1', 'input_text' => 'hi', 'target_language' => 'ar',
            'voice_provider' => 'google_cloud', 'voice_name' => 'ar-XA-Wavenet-A', 'billable_chars' => 1000,
            'duration_seconds' => 30, 'created_at' => now()->subDays(3), 'updated_at' => now()->subDays(3),
        ]);

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
        $this->assertSame(4, $s['generations']['last_30d']);
        $this->assertSame(1, $s['generations']['failed_30d']);
        $this->assertSame(1, $s['revenue']['paying_stripe']);
        $this->assertSame(900, $s['revenue']['mrr_cents']);
        $this->assertSame(1, $s['revenue']['members_on_paid_plans']);
        $this->assertCount(12, $s['monthly']);
        $this->assertSame(3, array_sum(array_column($s['monthly'], 'signups')));
        $this->assertSame('google_cloud', $s['providers'][0]['provider']);
        $this->assertSame(3, $s['providers'][0]['generations']);
    }

    public function test_api_usage_includes_demo_and_admins_and_estimates_missing_costs(): void
    {
        $this->seedBusinessData();

        $api = app(BusinessStatsService::class)->build(12)['api_usage'];
        $t = $api['totals_30d'];

        // 4 Google member/admin generations logged at $0.01, Gemini estimated, demo WaveNet estimated.
        $gemini = (60 * 32 * 10.0 + 400 / 4 * 0.5) / 1_000_000;
        $demo = 1000 / 1_000_000 * 16;
        $this->assertSame(6, $t['requests']);
        $this->assertSame(400 + 1000 + 4 * 100, $t['chars']);
        $this->assertEqualsWithDelta(0.04 + $gemini + $demo, $t['cost'], 0.006);
        $this->assertEqualsWithDelta(0.01, $t['admin_cost'], 0.0001);
        $this->assertEqualsWithDelta($demo, $t['demo_cost'], 0.006);

        $models = collect($api['by_model_30d'])->keyBy('model');
        $this->assertTrue($models->has('Gemini · gemini-2.5-flash-preview-tts'));
        $this->assertSame('estimated', $models['Gemini · gemini-2.5-flash-preview-tts']['cost_basis']);
        $this->assertTrue($models->has('Google Cloud TTS · Wavenet'));

        config(['analytics.gemini_tts.free_tier' => true]);
        $free = app(BusinessStatsService::class)->build(12)['api_usage']['by_model_30d'];
        $this->assertSame(0.0, (float) collect($free)->firstWhere('model', 'Gemini · gemini-2.5-flash-preview-tts')['cost_usd']);
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

        $this->assertSame(['Summary', 'Monthly', 'Cohorts', 'Plans', 'API usage', 'API by model (30d)', 'API by model (period)', 'API monthly', 'Plan quota usage', 'Breakdowns'], $names);
        $this->assertEquals(3, $summary['Total members (excl. admins)']);
        $this->assertEquals(9, $summary['Est. MRR (USD, list price)']);
    }

    public function test_admin_dashboard_renders_and_downloads_report(): void
    {
        ['admin' => $admin] = $this->seedBusinessData();

        $this->actingAs($admin)->get('/admin')->assertOk()->assertSee('Investor report (PDF)')->assertSee('Raw data (Excel)');
        $this->assertSame(
            ['ApiCostChart', 'ApiUsageBreakdown', 'ApiUsageKpis', 'BusinessBreakdowns', 'BusinessGrowthChart', 'BusinessGrowthKpis', 'BusinessProductKpis', 'BusinessRevenueChart', 'BusinessRevenueKpis', 'BusinessUsageChart'],
            collect(\Filament\Facades\Filament::getPanel('admin')->getWidgets())->map(fn ($w) => class_basename($w))->sort()->values()->all(),
            'Only the business widgets should be on the dashboard.'
        );

        Livewire::actingAs($admin)
            ->test(Dashboard::class)
            ->callAction('downloadReport')
            ->assertFileDownloaded();

        Livewire::actingAs($admin)
            ->test(Dashboard::class)
            ->callAction('downloadInvestorReport')
            ->assertFileDownloaded('listen-studio-business-report-'.now()->format('Y-m-d').'.pdf');
    }

    public function test_investor_pdf_is_a_real_pdf_with_the_headline_numbers(): void
    {
        $this->seedBusinessData();
        $snapshot = app(BusinessStatsService::class)->build(6);
        $report = app(\App\Services\Analytics\BusinessReportPdf::class);

        $pdf = $report->render($snapshot);
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertGreaterThan(10_000, strlen($pdf));

        $data = $report->viewData($snapshot);
        $this->assertSame('3', $data['kpis'][0]['value'], 'members exclude admins');
        $this->assertSame('$9', $data['kpis'][5]['value'], 'MRR from the Starter card subscription');
        $this->assertNotEmpty($data['highlights']);
        $html = view('reports.business-analytics', $data)->render();
        $this->assertStringNotContainsString('Blocked', $html, 'internal moderation figures stay out of the investor report');
        $this->assertStringNotContainsString('@example', $html, 'no personal data');
    }

    public function test_investor_pdf_renders_for_a_brand_new_site(): void
    {
        $pdf = app(\App\Services\Analytics\BusinessReportPdf::class)->render(app(BusinessStatsService::class)->build(3));

        $this->assertStringStartsWith('%PDF-', $pdf);
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
            \App\Filament\Widgets\ApiUsageKpis::class => 'Gross margin',
            \App\Filament\Widgets\ApiCostChart::class => 'API cost',
            \App\Filament\Widgets\ApiUsageBreakdown::class => 'Gemini · gemini-2.5-flash-preview-tts',
        ] as $widget => $text) {
            Livewire::test($widget, ['filters' => ['months' => 6]])->assertSee($text);
        }
    }
}
