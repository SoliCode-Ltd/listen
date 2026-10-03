<?php

namespace Tests\Feature;

use App\Models\SiteSetting;
use App\Models\User;
use App\Services\Analytics\BusinessReportPdf;
use App\Services\Analytics\BusinessStatsService;
use App\Services\LocalizedPricingService;
use App\Support\Countries;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MemberCountryTest extends TestCase
{
    use RefreshDatabase;

    public function test_country_names_and_regions(): void
    {
        $this->assertSame('Saudi Arabia', Countries::name('sa'));
        $this->assertSame('UAE', Countries::name('AE'));
        $this->assertSame('Unknown', Countries::name(null));
        $this->assertSame('Gulf (GCC)', Countries::region('KW'));
        $this->assertSame('Egypt', Countries::region('EG'));
        $this->assertSame('Levant & Iraq', Countries::region('IQ'));
        $this->assertSame('Europe', Countries::region('GB'));
        $this->assertSame('Rest of world', Countries::region('IN'));
        $this->assertTrue(Countries::isArabWorld('MA'));
        $this->assertFalse(Countries::isArabWorld('US'));
    }

    public function test_sign_in_saves_the_country_from_the_request_once(): void
    {
        $user = User::factory()->create(['country_code' => null]);
        // A real visitor: public IP on the live domain (localhost is treated as development).
        request()->server->set('REMOTE_ADDR', '8.8.8.8');
        request()->headers->set('HOST', 'listenai.studio');
        request()->headers->set('CF-IPCountry', 'SA');

        event(new Login('web', $user, false));
        $this->assertSame('SA', $user->fresh()->country_code);

        // A later sign-in from elsewhere does not overwrite it.
        request()->headers->set('CF-IPCountry', 'EG');
        event(new Login('web', $user->fresh(), false));
        $this->assertSame('SA', $user->fresh()->country_code);
    }

    public function test_sign_in_does_not_guess_a_country(): void
    {
        Http::fake(fn () => Http::response([], 500));
        SiteSetting::set('pricing_public_ip_lookup_enabled', '0', 'billing');
        Cache::flush();
        $user = User::factory()->create(['country_code' => null]);
        request()->headers->remove('CF-IPCountry');
        request()->server->set('REMOTE_ADDR', '8.8.8.8');
        request()->headers->set('HOST', 'listenai.studio');

        event(new Login('web', $user, false));

        // No header and no lookup answer: a fallback or browser-language guess is not saved.
        $this->assertNull($user->fresh()->country_code);
    }

    public function test_backfill_fills_countries_from_stored_ips(): void
    {
        $a = User::factory()->create(['country_code' => null, 'registration_ip' => '41.0.0.1']);
        $b = User::factory()->create(['country_code' => null, 'registration_ip' => '41.0.0.1']);
        $c = User::factory()->create(['country_code' => null, 'registration_ip' => null, 'last_login_ip' => '5.0.0.9']);
        $d = User::factory()->create(['country_code' => 'AE', 'registration_ip' => '41.0.0.1']);
        $e = User::factory()->create(['country_code' => null, 'registration_ip' => '10.0.0.5']);

        $lookups = [];
        $this->mock(LocalizedPricingService::class, function ($mock) use (&$lookups) {
            $mock->shouldReceive('countryForIp')->andReturnUsing(function (string $ip) use (&$lookups) {
                $lookups[] = $ip;

                return ['41.0.0.1' => 'EG', '5.0.0.9' => 'SA'][$ip] ?? null;
            });
        });

        $this->artisan('users:backfill-country', ['--dry-run' => true, '--sleep' => 0])->assertSuccessful();
        $this->assertNull($a->fresh()->country_code, 'dry run saves nothing');

        $lookups = [];
        $this->artisan('users:backfill-country', ['--sleep' => 0])->assertSuccessful();

        $this->assertSame('EG', $a->fresh()->country_code);
        $this->assertSame('EG', $b->fresh()->country_code);
        $this->assertSame('SA', $c->fresh()->country_code);
        $this->assertSame('AE', $d->fresh()->country_code, 'an existing country is kept');
        $this->assertNull($e->fresh()->country_code);
        $this->assertSame(1, collect($lookups)->filter(fn ($ip) => $ip === '41.0.0.1')->count(), 'each IP is looked up once');
    }

    public function test_private_addresses_have_no_country(): void
    {
        Http::fake();

        $pricing = app(LocalizedPricingService::class);
        foreach (['10.1.2.3', '192.168.1.1', '127.0.0.1', '::1', 'not-an-ip', ''] as $ip) {
            $this->assertNull($pricing->countryForIp($ip));
        }
        Http::assertNothingSent();
    }

    public function test_report_has_an_audience_section(): void
    {
        $free = DB::table('plans')->insertGetId(['slug' => 'free-x', 'name' => 'Free', 'price_cents' => 0, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $paid = DB::table('plans')->insertGetId(['slug' => 'starter-x', 'name' => 'Starter', 'price_cents' => 900, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $make = function (?string $country, string $email, int $plan) {
            $user = User::factory()->create(['country_code' => $country, 'email' => $email]);
            DB::table('subscriptions')->insert(['user_id' => $user->id, 'plan_id' => $plan, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

            return $user;
        };
        $eg1 = $make('EG', 'a@gmail.com', $free);
        $make('EG', 'b@hotmail.co.uk', $paid);
        $make('SA', 'c@company.sa', $free);
        $make(null, 'd@gmail.com', $free);
        User::factory()->create(['country_code' => 'US', 'is_admin' => true, 'email' => 'admin@x.com']);
        DB::table('audio_generations')->insert([
            'user_id' => $eg1->id, 'input_text' => 'x', 'target_language' => 'ar', 'status' => 'done',
            'voice_tier' => 'hd', 'dialect' => 'ar-SA', 'created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2),
        ]);

        $snapshot = app(BusinessStatsService::class)->build(6);
        $a = $snapshot['audience'];
        $this->assertSame([], $snapshot['errors']);
        $this->assertSame(4, $a['members'], 'admins excluded');
        $this->assertSame(3, $a['with_country']);
        $this->assertSame(['EG', 'SA', null], array_column($a['countries'], 'code'), 'largest first, unknown last');
        $this->assertSame([2, 1, 1], array_column($a['countries'], 'members'));
        $this->assertSame(1, $a['countries'][0]['active_30d']);
        $this->assertSame(1, $a['countries'][0]['paying']);
        $this->assertSame(['Personal email' => 3, 'Work email' => 1], $a['email_types']);
        $this->assertSame(['Saudi' => 1], $a['dialects']);

        $report = app(BusinessReportPdf::class);
        $data = $report->viewData($snapshot);
        $this->assertSame('Egypt', $data['audience']['top_country']);
        $this->assertEqualsWithDelta(1.0, $data['audience']['arab_share'], 0.001);
        $html = view('reports.business-analytics', $data)->render();
        $this->assertStringContainsString('Who our members are', $html);
        $this->assertStringContainsString('Saudi Arabia', $html);
        $this->assertStringNotContainsString('@gmail', $html, 'no personal data');
        $this->assertStringStartsWith('%PDF-', $report->render($snapshot));
    }

    public function test_lookup_survives_a_cache_it_cannot_write(): void
    {
        // On live, storage/framework/cache folders made by the web server were not writable over SSH.
        Http::fake(['https://ipapi.co/*' => Http::response(['country_code' => 'EG'])]);
        Cache::shouldReceive('get')->andReturn(null);
        Cache::shouldReceive('put')->andThrow(new \ErrorException('file_put_contents(storage/framework/cache/data/8c/d0/x): Failed to open stream: Permission denied'));

        $this->assertSame('EG', app(LocalizedPricingService::class)->countryForIp('41.33.0.1'));
    }

    public function test_backfill_keeps_going_when_one_lookup_fails(): void
    {
        $a = User::factory()->create(['country_code' => null, 'registration_ip' => '41.0.0.1']);
        $b = User::factory()->create(['country_code' => null, 'registration_ip' => '5.0.0.9']);

        $this->mock(LocalizedPricingService::class, function ($mock) {
            $mock->shouldReceive('countryForIp')->andReturnUsing(function (string $ip) {
                if ($ip === '41.0.0.1') {
                    throw new \ErrorException('Permission denied');
                }

                return 'SA';
            });
        });

        $this->artisan('users:backfill-country', ['--sleep' => 0])
            ->expectsOutputToContain('1 lookups failed with an error')
            ->assertSuccessful();

        $this->assertNull($a->fresh()->country_code, 'skipped, retried on the next run');
        $this->assertSame('SA', $b->fresh()->country_code);
    }

    public function test_admin_lists_show_and_filter_by_country(): void
    {
        $plan = \App\Models\Plan::create([
            'slug' => 'starter', 'name' => 'Starter', 'price_cents' => 900, 'monthly_chars' => 500000,
            'monthly_minutes' => 120, 'monthly_requests' => 1000, 'max_chars_per_request' => 5000,
            'allow_overage' => false, 'is_active' => true,
        ]);
        $egypt = User::factory()->create(['country_code' => 'EG']);
        $saudi = User::factory()->create(['country_code' => 'SA']);
        $unknown = User::factory()->create(['country_code' => null]);
        $subEgypt = \App\Models\Subscription::create(['user_id' => $egypt->id, 'plan_id' => $plan->id, 'status' => 'active']);
        $subSaudi = \App\Models\Subscription::create(['user_id' => $saudi->id, 'plan_id' => $plan->id, 'status' => 'active']);

        $this->actingAs(User::factory()->create(['is_admin' => true, 'country_code' => 'GB']));
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));

        \Livewire\Livewire::test(\App\Filament\Resources\UserResource\Pages\ListUsers::class)
            ->assertSee('🇪🇬 Egypt')
            ->assertSee('🇸🇦 Saudi Arabia')
            ->filterTable('country_code', 'EG')
            ->assertCanSeeTableRecords([$egypt])
            ->assertCanNotSeeTableRecords([$saudi, $unknown]);

        \Livewire\Livewire::test(\App\Filament\Resources\UserResource\Pages\ListUsers::class)
            ->filterTable('country_code', '__unknown')
            ->assertCanSeeTableRecords([$unknown])
            ->assertCanNotSeeTableRecords([$egypt, $saudi]);

        \Livewire\Livewire::test(\App\Filament\Resources\SubscriptionResource\Pages\ListSubscriptions::class)
            ->assertSee('🇸🇦 Saudi Arabia')
            ->filterTable('country', 'SA')
            ->assertCanSeeTableRecords([$subSaudi])
            ->assertCanNotSeeTableRecords([$subEgypt]);

        $this->get(\App\Filament\Resources\UserResource::getUrl('edit', ['record' => $egypt]))
            ->assertOk()
            ->assertSee('🇪🇬 Egypt');
    }
}
