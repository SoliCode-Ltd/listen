<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\ApiKeyResource;
use App\Filament\Resources\ApiKeyResource\Pages\ListApiKeys;
use App\Filament\Resources\ApiKeyResource\Pages\ViewApiKey;
use App\Filament\Resources\ApiKeyResource\RelationManagers\RequestsRelationManager;
use App\Filament\Resources\ApiRequestLogResource;
use App\Filament\Resources\ApiRequestLogResource\Pages\ListApiRequestLogs;
use App\Filament\Resources\ApiRequestLogResource\Pages\ViewApiRequestLog;
use App\Models\ApiKey;
use App\Models\ApiKeyUsage;
use App\Models\ApiRequestLog;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Api\ApiKeyStatus;
use App\Support\CreditPeriod;
use Filament\Facades\Filament;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * System → API keys: the menu counts the keys (not credits), each key shows its credits against
 * its own limit (or says it has none), the member's day against their plan's daily limit and the
 * limits it hit; a key opens on its own page with every request, and every request on its own.
 */
class ApiKeysAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $member;

    private ApiKey $limited;

    private ApiKey $open;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->member = User::factory()->create(['email' => 'kifak@example.test']);
        Plan::factory()->free()->create(['monthly_chars' => 10000]);
        $plan = Plan::factory()->create(['slug' => 'pro', 'name' => 'Pro', 'price_cents' => 4900, 'monthly_chars' => 600000, 'daily_requests' => 3, 'is_active' => true]);
        Subscription::create(['user_id' => $this->member->id, 'plan_id' => $plan->id, 'status' => 'active']);
        $this->limited = ApiKey::issue($this->member, 'Kifak audio generator', null, 60000)['key'];
        $this->open = ApiKey::issue($this->member, 'Cooking voice')['key'];
        $period = CreditPeriod::current((int) $this->member->id)['key'];
        ApiKeyUsage::create(['api_key_id' => $this->limited->id, 'period_ym' => $period, 'requests' => 100, 'credits' => 1965]);
        ApiKeyUsage::create(['api_key_id' => $this->open->id, 'period_ym' => $period, 'requests' => 11, 'credits' => 1785]);
    }

    private function log(ApiKey $key, array $attributes = []): ApiRequestLog
    {
        $log = ApiRequestLog::create(array_merge([
            'request_id' => 'req_'.Str::random(20), 'user_id' => $key->user_id, 'api_key_id' => $key->id, 'method' => 'POST',
            'path' => '/v1/text-to-speech', 'endpoint' => 'v1.tts', 'status' => 200, 'duration_ms' => 820, 'credits' => 45, 'characters' => 30,
            'ip' => '203.0.113.5', 'user_agent' => 'kifak-bot/1.0',
        ], $attributes));
        $log->forceFill(['created_at' => $attributes['created_at'] ?? now()])->save();

        return $log;
    }

    public function test_the_menu_counts_the_keys_that_work_not_the_credits(): void
    {
        $this->assertSame('2', ApiKeyResource::getNavigationBadge());
        $this->open->revoke();
        $this->assertSame('1', ApiKeyResource::getNavigationBadge());
    }

    public function test_every_key_says_its_credits_against_its_own_limit_or_that_it_has_none(): void
    {
        $this->assertSame('1,965 of 60,000', ApiKeyStatus::creditsLine($this->limited));
        $this->assertSame('This key\'s own monthly limit (3% used)', ApiKeyStatus::creditsNote($this->limited));
        $this->assertSame('1,785', ApiKeyStatus::creditsLine($this->open));
        $this->assertSame('No limit set on this key: the member\'s plan credits apply', ApiKeyStatus::creditsNote($this->open));

        Livewire::actingAs($this->admin)->test(ListApiKeys::class)
            ->assertCanSeeTableRecords([$this->limited, $this->open])
            ->assertSee('1,965 of 60,000')
            ->assertSee('No limit set on this key: the member&#039;s plan credits apply', false);
    }

    public function test_a_key_stopped_by_the_daily_limit_today_says_so_and_can_be_found(): void
    {
        $this->log($this->open, ['status' => 429, 'error_code' => ApiKeyStatus::DAILY, 'credits' => null]);
        $this->log($this->open, ['status' => 402, 'error_code' => ApiKeyStatus::KEY_LIMIT, 'credits' => null, 'created_at' => now()->subDays(2)]);

        $this->assertTrue(ApiKeyStatus::hitDailyToday($this->open));
        $this->assertFalse(ApiKeyStatus::hitDailyToday($this->limited));
        $this->assertStringContainsString('Pro allows 3 generations a day (studio and API)', ApiKeyStatus::todayNote($this->open));

        Livewire::actingAs($this->admin)->test(ListApiKeys::class)
            ->filterTable('daily_today')
            ->assertCanSeeTableRecords([$this->open])
            ->assertCanNotSeeTableRecords([$this->limited])
            ->assertSee('Daily limit today');
    }

    public function test_a_key_opens_on_its_own_page_with_all_its_requests(): void
    {
        $ok = $this->log($this->limited);
        $refused = $this->log($this->limited, ['status' => 402, 'error_code' => ApiKeyStatus::KEY_LIMIT, 'credits' => null]);
        $other = $this->log($this->open);

        Livewire::actingAs($this->admin)->test(ListApiKeys::class)
            ->assertTableActionExists('view');
        $this->actingAs($this->admin)->get(ApiKeyResource::getUrl('view', ['record' => $this->limited]))->assertOk()
            ->assertSee('Kifak audio generator')->assertSee('1,965 of 60,000')->assertSee('Requests')->assertSee('Use by period');

        $requests = Livewire::actingAs($this->admin)->test(RequestsRelationManager::class, ['ownerRecord' => $this->limited, 'pageClass' => ViewApiKey::class])
            ->assertCanSeeTableRecords([$ok, $refused])
            ->assertCanNotSeeTableRecords([$other]);
        // Pages without counting the whole log first.
        $this->assertInstanceOf(Paginator::class, $requests->instance()->getTableRecords());
        $this->assertNotInstanceOf(LengthAwarePaginator::class, $requests->instance()->getTableRecords());

        $this->actingAs($this->admin)->get(ApiRequestLogResource::getUrl('view', ['record' => $refused]))->assertOk()
            ->assertSee($refused->request_id)->assertSee('This key had used its own monthly credit limit.')
            ->assertSee('kifak-bot/1.0')->assertSee('203.0.113.5');
    }

    public function test_the_whole_log_pages_without_counting_it(): void
    {
        $this->log($this->open);
        $list = Livewire::actingAs($this->admin)->test(ListApiRequestLogs::class);
        $this->assertNotInstanceOf(LengthAwarePaginator::class, $list->instance()->getTableRecords());
        Livewire::actingAs($this->admin)->test(ViewApiRequestLog::class, ['record' => ApiRequestLog::first()->getRouteKey()])->assertOk();
    }

    public function test_requests_are_under_each_key_not_in_the_menu(): void
    {
        $this->assertFalse(ApiRequestLogResource::shouldRegisterNavigation());
        $this->actingAs($this->admin)->get(ApiKeyResource::getUrl('index'))->assertOk()
            ->assertDontSee('href="'.ApiRequestLogResource::getUrl('index').'"', false);
    }

    public function test_members_cannot_open_it(): void
    {
        $this->actingAs($this->member)->get(ApiKeyResource::getUrl('view', ['record' => $this->limited]))->assertForbidden();
    }
}
