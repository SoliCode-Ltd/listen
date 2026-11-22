<?php

namespace Tests\Feature;

use App\Filament\Resources\SubscriptionResource\Pages\ListSubscriptions;
use App\Models\MemberSource;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Acquisition\Channels;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Admin → Subscriptions shows where each paying member came from, as Users does, and filters by it. */
class SubscriptionCameFromTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_list_shows_and_filters_where_each_member_came_from(): void
    {
        $plan = Plan::create([
            'slug' => 'starter', 'name' => 'Starter', 'price_cents' => 900, 'monthly_chars' => 500000,
            'monthly_minutes' => 120, 'monthly_requests' => 1000, 'max_chars_per_request' => 5000,
            'allow_overage' => false, 'is_active' => true,
        ]);
        $fromAds = User::factory()->create();
        MemberSource::create(['user_id' => $fromAds->id, 'channel' => Channels::GOOGLE_ADS, 'detail' => 'Listen | Arabic | Gulf']);
        $notKept = User::factory()->create();
        $subAds = Subscription::create(['user_id' => $fromAds->id, 'plan_id' => $plan->id, 'status' => 'active']);
        $subNotKept = Subscription::create(['user_id' => $notKept->id, 'plan_id' => $plan->id, 'status' => 'active']);

        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ListSubscriptions::class)
            ->assertSee('Came from')
            ->assertSee('Google Ads')
            ->assertSee('Listen | Arabic | Gulf')
            ->assertSee('Not recorded')
            ->filterTable('came_from', Channels::GOOGLE_ADS)
            ->assertCanSeeTableRecords([$subAds])
            ->assertCanNotSeeTableRecords([$subNotKept]);

        Livewire::test(ListSubscriptions::class)
            ->filterTable('came_from', Channels::UNKNOWN)
            ->assertCanSeeTableRecords([$subNotKept])
            ->assertCanNotSeeTableRecords([$subAds]);
    }
}
