<?php

namespace Tests\Feature;

use App\Models\AudioGeneration;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\TextItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * A Generate whose reply never came (a gateway timeout, a dropped connection)
 * may still be made, and charged, on the server. The studio does not call it
 * a failure: it says the voice may still be finishing, looks for the voice
 * that request made, and does not send the same text again until it knows,
 * so a retry never charges twice. One request is in flight at a time.
 */
class StudioLostReplyTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        app()->setLocale('en');

        parent::tearDown();
    }

    public function test_the_newest_voices_after_the_one_given_are_listed_newest_first(): void
    {
        $user = $this->member();
        $first = $this->generation($user, 'The first one.');
        $second = $this->generation($user, 'The second one.', 'done');
        $third = $this->generation($user, 'The one whose reply was lost.');
        $this->generation($this->member(), 'Someone else.');
        $demo = TextItem::query()->create(['user_id' => $user->id, 'source_type' => 'demo', 'content' => 'A demo.', 'language' => 'en', 'status' => 'done']);
        $this->generation($user, 'A demo.', 'done', ['text_item_id' => $demo->id]);

        $response = $this->latest($user, $first->id)->assertOk()->assertJsonPath('ok', true);

        $this->assertSame([$third->id, $second->id], array_column($response->json('jobs'), 'id'), 'only theirs, after the one given, and not the demo copies');
        $response->assertJsonPath('jobs.0.text_excerpt', 'The one whose reply was lost.')
            ->assertJsonPath('jobs.0.status', 'processing')
            ->assertJsonPath('jobs.0.row_key', 'audio:'.$third->id)
            ->assertJsonPath('jobs.1.status', 'done');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        foreach (range(1, 6) as $n) {
            $this->generation($user, "Another one {$n}.");
        }
        $this->assertCount(5, $this->latest($user, 0)->json('jobs'), 'a few at most');
    }

    public function test_looking_needs_a_signed_in_member_and_skips_the_billing_sync(): void
    {
        $this->withoutMiddleware($this->localization())->getJson($this->localizedRoute('dashboard.jobs.latest'))->assertUnauthorized();

        $middleware = Route::getRoutes()->getByName('dashboard.jobs.latest')?->gatherMiddleware() ?? [];
        $this->assertContains('auth', $middleware);
        $this->assertNotContains('subscription.sync', $middleware);
    }

    public function test_the_studio_looks_for_a_lost_reply_instead_of_calling_it_a_failure(): void
    {
        $this->assertSame(
            "Your voice may still be finishing. It will appear in your history in a moment — please don't generate it again.",
            trans('ui.dashboard.voice_may_still_be_finishing', [], 'en'),
        );
        $this->assertNotSame(trans('ui.dashboard.voice_may_still_be_finishing', [], 'en'), trans('ui.dashboard.voice_may_still_be_finishing', [], 'ar'));

        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            $html = $this->actingAs($this->member())->getLocalizedRoute('dashboard')->assertOk()->getContent();

            $this->assertStringContainsString("const latestJobsUrl = '".route('dashboard.jobs.latest')."';", $html);
            // No reply, or a gateway that gave up: looked for, not called a failure.
            $this->assertStringContainsString('return status === 0 || status === 502 || status === 504 || (status >= 520 && status <= 524);', $html);
            $this->assertStringContainsString('if (replyWasLost(response ? response.status : 0)) {', $html);
            $this->assertStringContainsString('startLostReplyCheck(submittedText, newestJobIdBefore);', $html);
            $this->assertStringContainsString("showFormMessage('note', i18n.voice_may_still_be_finishing);", $html);
            $this->assertStringContainsString('const LOST_REPLY_CHECK_FOR_MS = 3 * 60 * 1000;', $html);
            $this->assertStringContainsString("watchJob(job.id, { now: true, onFinish: () => settleLostReplyCheck(check) });", $html);
            // The same text waits for the answer; one request at a time.
            $this->assertStringContainsString('if (lostReplyCheck && lostReplyCheck.text === submittedText) {', $html);
            $this->assertStringContainsString('if (generateInFlight) return;', $html);
            $this->assertStringContainsString($this->jsonText(trans('ui.dashboard.voice_may_still_be_finishing', [], $locale)), $html);
        }
    }

    private function latest(User $user, int $after): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($user)->withoutMiddleware($this->localization())
            ->getJson($this->localizedRoute('dashboard.jobs.latest').'?after='.$after);
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

    /** How the page's @json writes a text. */
    private function jsonText(string $text): string
    {
        return substr(json_encode($text, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT), 1, -1);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function generation(User $user, string $text, string $status = 'processing', array $attributes = []): AudioGeneration
    {
        return AudioGeneration::query()->create(array_merge([
            'user_id' => $user->id,
            'user_sequence_number' => (int) AudioGeneration::query()->where('user_id', $user->id)->max('user_sequence_number') + 1,
            'input_text' => $text,
            'source_language' => 'en',
            'target_language' => 'en',
            'voice_tier' => 'standard',
            'voice_name' => 'en-US-Wavenet-C',
            'status' => $status,
        ], $attributes));
    }

    private function member(): User
    {
        $plan = Plan::factory()->create(['voice_tier' => 'standard', 'is_active' => true, 'monthly_chars' => 100000, 'max_chars_per_request' => 5000, 'monthly_requests' => 0]);
        $user = User::factory()->create(['subscription_status_checked_at' => now()]);
        Subscription::query()->create(['user_id' => $user->id, 'plan_id' => $plan->id, 'status' => 'active']);

        return $user;
    }
}
