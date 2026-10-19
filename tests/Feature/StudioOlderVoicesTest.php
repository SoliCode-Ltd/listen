<?php

namespace Tests\Feature;

use App\Models\AudioGeneration;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The plans promise a history of 15 to 180 days, or one without a limit, so
 * the studio's history is never cut short: the page lists the newest 500
 * voices, and "Show older voices" fetches the rest a page at a time, only
 * ever the member's own.
 */
class StudioOlderVoicesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_page_lists_500_and_show_older_voices_fetches_the_other_100(): void
    {
        $user = $this->member();
        $other = $this->member();
        // Made at the same time as another member's, so the ids of the two are mixed.
        $this->insertGenerations([$user, $other], 600);

        $response = $this->actingAs($user)->getLocalizedRoute('dashboard')->assertOk();
        $listed = $response->viewData('jobs');
        $this->assertCount(500, $listed);
        $before = $response->viewData('olderJobsBefore');
        $this->assertSame((int) $listed->min('id'), $before, 'older ones go on from the oldest listed');
        $html = $response->getContent();
        $this->assertMatchesRegularExpression('/<div class="pagination jobs-older" id="jobsOlder"\s*>/', $html, 'the button is shown');
        $this->assertStringContainsString('<button type="button" class="pagination-btn" id="jobsOlderBtn">'.__('ui.dashboard.show_older_voices').'</button>', $html);
        $this->assertStringContainsString("let olderJobsBefore = {$before};", $html);

        $older = $this->older($user, $before)->assertOk()->assertJsonPath('ok', true)->assertJsonPath('next_before', null);

        $mine = AudioGeneration::query()->where('user_id', $user->id)->orderByDesc('id')->pluck('id')->all();
        $this->assertSame(array_slice($mine, 500), array_column($older->json('jobs'), 'id'), 'the other 100, newest first, and nothing of the other member\'s');
        $older->assertJsonPath('jobs.0.row_key', 'audio:'.$mine[500])
            ->assertJsonPath('jobs.0.text_excerpt', 'Line 100')
            ->assertJsonPath('jobs.0.is_demo_history', false);
        $this->assertStringContainsString('no-store', (string) $older->headers->get('Cache-Control'));
    }

    public function test_older_voices_come_a_page_of_500_at_a_time_until_the_first(): void
    {
        $user = $this->member();
        $this->insertGenerations([$user], 1100);
        $before = $this->actingAs($user)->getLocalizedRoute('dashboard')->viewData('olderJobsBefore');

        $page = $this->older($user, $before)->assertOk();
        $this->assertCount(500, $page->json('jobs'));
        $this->assertSame('Line 600', $page->json('jobs.0.text_excerpt'));
        $next = $page->json('next_before');
        $this->assertSame($page->json('jobs.499.id'), $next, 'the next page goes on from the oldest of these');

        $last = $this->older($user, $next)->assertOk()->assertJsonPath('next_before', null);
        $this->assertCount(100, $last->json('jobs'));
        $this->assertSame('Line 1', $last->json('jobs.99.text_excerpt'));
    }

    public function test_a_history_of_500_or_fewer_has_no_button(): void
    {
        $user = $this->member();
        $this->insertGenerations([$user], 500);

        $response = $this->actingAs($user)->getLocalizedRoute('dashboard')->assertOk();

        $this->assertNull($response->viewData('olderJobsBefore'));
        $this->assertMatchesRegularExpression('/id="jobsOlder"\s+hidden/', $response->getContent());
    }

    public function test_the_button_is_in_both_languages_and_fetching_needs_a_signed_in_member(): void
    {
        $this->assertSame('Show older voices', trans('ui.dashboard.show_older_voices', [], 'en'));
        $this->assertSame('عرض الأصوات الأقدم', trans('ui.dashboard.show_older_voices', [], 'ar'));
        $this->assertNotSame(trans('ui.dashboard.older_voices_failed', [], 'en'), trans('ui.dashboard.older_voices_failed', [], 'ar'));

        $this->withoutMiddleware($this->localization())->getJson($this->localizedRoute('dashboard.jobs.older').'?before=10')->assertUnauthorized();
        $middleware = Route::getRoutes()->getByName('dashboard.jobs.older')?->gatherMiddleware() ?? [];
        $this->assertContains('auth', $middleware);
        $this->assertNotContains('subscription.sync', $middleware, 'it only reads');
    }

    private function older(User $user, int $before): TestResponse
    {
        return $this->actingAs($user)->withoutMiddleware($this->localization())
            ->getJson($this->localizedRoute('dashboard.jobs.older').'?before='.$before);
    }

    /**
     * Finished generations "Line 1" (the oldest) to "Line {$count}" for each member, in turn.
     *
     * @param  array<int, User>  $users
     */
    private function insertGenerations(array $users, int $count): void
    {
        $rows = [];
        foreach (range(1, $count) as $number) {
            foreach ($users as $user) {
                $rows[] = [
                    'user_id' => $user->id, 'user_sequence_number' => $number, 'input_text' => "Line {$number}",
                    'source_language' => 'en', 'target_language' => 'en', 'voice_tier' => 'standard', 'voice_name' => 'en-US-Wavenet-C',
                    'translate' => false, 'status' => 'done', 'audio_path' => "audio/line-{$user->id}-{$number}.mp3",
                    'created_at' => now()->subMinutes(5000 - $number), 'updated_at' => now(),
                ];
            }
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            AudioGeneration::query()->insert($chunk);
        }
    }

    private function member(): User
    {
        $plan = Plan::factory()->create([
            'voice_tier' => 'standard', 'price_cents' => 1999, 'is_active' => true,
            'monthly_chars' => 100000, 'max_chars_per_request' => 5000, 'monthly_requests' => 0,
        ]);
        $user = User::factory()->create(['subscription_status_checked_at' => now()]);
        Subscription::query()->create(['user_id' => $user->id, 'plan_id' => $plan->id, 'status' => 'active']);

        return $user;
    }

    /** @return array<int, class-string> */
    private function localization(): array
    {
        return [
            \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
            \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath::class,
        ];
    }
}
