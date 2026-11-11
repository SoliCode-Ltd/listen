<?php

namespace Tests\Feature;

use App\Filament\Pages\SuspiciousUsers;
use App\Filament\Resources\UsageCounterResource\Pages\ListUsageCounters;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\AudioGeneration;
use App\Models\DemoVoiceGeneration;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use App\Services\DemoSuspects;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Tests\TestCase;

/**
 * The speed fixes change no result: a member's history comes back the same, in the
 * same order; the admin lists show the same values with a number of queries that no
 * longer grows with the rows on the page; settings not saved are not looked up on
 * every read; the indexes are there and adding them is safe to run again.
 */
class ListSpeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_members_history_comes_back_the_same_newest_first_with_the_index_hint(): void
    {
        $member = User::factory()->create();
        $other = User::factory()->create();
        foreach (range(1, 6) as $i) {
            $this->generation($member);
            $this->generation($other);
        }

        $plain = AudioGeneration::query()->where('user_id', $member->id)->latest('id')->limit(4)->pluck('id')->all();
        $query = AudioGeneration::query()->select(AudioGeneration::memberNewestColumns())->where('user_id', $member->id)->latest('id')->limit(4);

        $this->assertStringStartsWith('select /*+ INDEX(audio_generations '.AudioGeneration::MEMBER_ID_INDEX.') */ audio_generations.*', $query->toSql());
        $this->assertSame($plain, $query->pluck('id')->all());
        $this->assertCount(4, $query->get());
        $this->assertSame($member->id, $query->get()->first()->user_id, 'every column comes back');

        $columns = AudioGeneration::memberNewestColumns(['id', 'created_at']);
        $this->assertSame('created_at', $columns[1]);
        $this->assertStringEndsWith('audio_generations.id', (string) $columns[0]->getValue(DB::connection()->getQueryGrammar()));
    }

    public function test_the_studio_checks_for_new_voices_as_before(): void
    {
        $member = User::factory()->create();
        $first = $this->generation($member);
        $second = $this->generation($member);
        $this->generation(User::factory()->create());

        $this->actingAs($member)
            ->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class, LaravelLocalizationViewPath::class])
            ->getJson($this->localizedRoute('dashboard.jobs.latest').'?after='.$first->id)
            ->assertOk()
            ->assertJsonCount(1, 'jobs')
            ->assertJsonPath('jobs.0.id', $second->id);
    }

    public function test_every_index_is_there_and_adding_them_again_changes_nothing(): void
    {
        $indexes = collect(Schema::getIndexes('audio_generations'))->pluck('name');
        $this->assertContains(AudioGeneration::MEMBER_ID_INDEX, $indexes);
        $this->assertContains('audio_generations_user_id_created_at_index', $indexes);
        $this->assertContains('audio_generations_created_at_index', $indexes);
        $this->assertContains('users_created_at_index', collect(Schema::getIndexes('users'))->pluck('name'));
        $this->assertContains('users_email_key_index', collect(Schema::getIndexes('users'))->pluck('name'));

        $migration = require database_path('migrations/2026_11_26_120000_add_list_indexes.php');
        $migration->up();
        $migration->up();

        $this->assertSame($indexes->sort()->values()->all(), collect(Schema::getIndexes('audio_generations'))->pluck('name')->sort()->values()->all());
    }

    public function test_a_setting_that_is_not_saved_is_looked_up_once_a_minute_not_on_every_read(): void
    {
        $lookups = 0;
        DB::listen(function ($query) use (&$lookups): void {
            if (str_contains($query->sql, 'site_settings')) {
                $lookups++;
            }
        });

        foreach (range(1, 5) as $i) {
            $this->assertNull(SiteSetting::get('never_saved_setting'));
            $this->assertSame('fallback', SiteSetting::get('never_saved_setting', 'fallback'));
        }
        $this->assertSame(1, $lookups);

        // Saved from the admin: read at once.
        SiteSetting::set('never_saved_setting', 'now saved');
        $this->assertSame('now saved', SiteSetting::get('never_saved_setting'));

        // A minute on, a value removed by other means is read again.
        SiteSetting::query()->where('key', 'never_saved_setting')->delete();
        $this->travel(61)->seconds();
        $this->assertSame('fallback', SiteSetting::get('never_saved_setting', 'fallback'));
    }

    public function test_the_users_list_shows_the_same_plan_and_demo_use_with_the_same_queries_for_any_page_size(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $paid = Plan::factory()->create(['slug' => 'speed_paid', 'name' => 'Speed Plan', 'price_cents' => 900, 'is_active' => true]);
        $payer = User::factory()->create();
        Subscription::query()->create(['user_id' => $payer->id, 'plan_id' => $paid->id, 'status' => 'active',
            'current_period_start' => now(), 'current_period_end' => now()->addMonth()]);
        $demoUser = User::factory()->create();
        DemoVoiceGeneration::query()->create($this->demoRow($demoUser, 120));
        $this->actingAs($admin);

        Livewire::test(ListUsers::class)
            ->assertTableColumnStateSet('current_plan', 'Speed Plan', (string) $payer->id)
            ->assertTableColumnStateSet('current_plan', 'Free', (string) $demoUser->id)
            ->assertTableColumnStateSet('demo_chars_used', 120, (string) $demoUser->id)
            ->assertTableColumnStateSet('demo_chars_used', 0, (string) $payer->id);

        $few = $this->queriesFor(fn () => Livewire::test(ListUsers::class)->set('tableRecordsPerPage', 10));
        User::factory()->count(20)->create();
        $many = $this->queriesFor(fn () => Livewire::test(ListUsers::class)->set('tableRecordsPerPage', 25));

        // At most the "IP-linked accounts" count is left for each row (none without signals): not the seven a row before.
        $this->assertLessThanOrEqual($few + 15 + 5, $many);
    }

    public function test_the_usage_months_filter_lists_the_same_months(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        foreach (['2026-09', '2026-10-14 08:37', '2026-10', '2026-11'] as $i => $ym) {
            UsageCounter::query()->create(['user_id' => User::factory()->create()->id, 'period_ym' => $ym, 'total_chars' => 10 * ($i + 1)]);
        }
        $this->actingAs($admin);

        $options = Livewire::test(ListUsageCounters::class)->instance()->getTable()->getFilter('period_ym')->getOptions();

        $this->assertSame(['2026-11', '2026-10', '2026-09'], array_keys($options));
    }

    public function test_the_suspicious_users_list_is_worked_out_ahead_of_time(): void
    {
        $a = User::factory()->create(['registration_device_id' => 'browser_warm_0123456789abcdefghij']);
        $b = User::factory()->create(['registration_device_id' => 'browser_warm_0123456789abcdefghij']);

        $this->artisan('listen:warm-demo-suspects')->assertSuccessful();

        $this->assertSame([$a->id, $b->id], array_keys(Cache::get(DemoSuspects::CACHE_KEY)));
        $lookups = $this->queriesFor(fn () => SuspiciousUsers::getNavigationBadge());
        $this->assertSame('2', SuspiciousUsers::getNavigationBadge());
        $this->assertSame(0, $lookups, 'the badge on every admin page reads what was worked out');
    }

    private function queriesFor(callable $work): int
    {
        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });
        $before = $count;
        $work();

        return $count - $before;
    }

    private function generation(User $user): AudioGeneration
    {
        return AudioGeneration::query()->create([
            'user_id' => $user->id,
            'input_text' => 'Hello',
            'target_language' => 'en',
            'voice_name' => 'en-US-Wavenet-C',
            'voice_tier' => 'standard',
            'status' => 'done',
            'billable_chars' => 5,
        ]);
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
