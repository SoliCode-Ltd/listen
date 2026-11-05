<?php

namespace Tests\Feature\Agents;

use App\Models\Agent;
use App\Models\AgentWidget;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Agents\CallBackground;
use App\Support\AudioEffects;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Tests\TestCase;

/**
 * The quiet office a caller hears behind the agent (Design → Call sound): on for every call
 * unless the owner picks None, the same sound for every website, and the studio's Office
 * environment made from the same colleagues.
 */
class CallBackgroundTest extends TestCase
{
    use RefreshDatabase;

    private const SITE = 'https://cairosmiles.com';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.gemini_tts.api_key' => 'test-key']);
        Http::fake(['*auth_tokens' => Http::response(['name' => 'auth_tokens/live-key'])]);
        Plan::factory()->free()->create(['monthly_chars' => 10000, 'voice_tier' => 'standard']);
        \App\Models\SiteSetting::set('agents_credits_per_minute', '8100', 'agents');
    }

    private function owner(): User
    {
        $plan = Plan::factory()->create(['slug' => 'pro-'.uniqid(), 'price_cents' => 4500, 'voice_tier' => 'hd', 'monthly_chars' => 500000, 'allow_overage' => false,
            'agents_enabled' => true, 'agent_concurrent_calls' => 20]);
        $user = User::factory()->create();
        Subscription::create(['user_id' => $user->id, 'plan_id' => $plan->id, 'status' => 'active']);

        return $user;
    }

    private function agent(User $owner, array $attributes = []): Agent
    {
        return Agent::create(array_merge([
            'user_id' => $owner->id, 'name' => 'Sara', 'business_name' => 'Cairo Smiles', 'business_type' => 'healthcare',
            'jobs' => ['answer'], 'language' => 'ar', 'dialect' => 'ar-EG', 'live_voice' => 'Achernar',
            'status' => Agent::LIVE, 'max_call_seconds' => 600, 'first_message' => 'أهلًا بيك',
        ], $attributes));
    }

    private function startCall(AgentWidget $widget, string $visitor): TestResponse
    {
        return $this->call('POST', '/agent-api/calls', [], [], [], ['CONTENT_TYPE' => 'text/plain;charset=UTF-8', 'REMOTE_ADDR' => '203.0.113.9', 'HTTP_ORIGIN' => self::SITE],
            json_encode(['key' => $widget->public_key, 'visitor' => $visitor]));
    }

    private function withoutLocale(): static
    {
        return $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class, LaravelLocalizationViewPath::class, ValidateCsrfToken::class]);
    }

    public function test_every_call_has_a_quiet_office_behind_the_agent_unless_the_owner_picks_none(): void
    {
        $owner = $this->owner();
        $agent = $this->agent($owner);
        $widget = $agent->mainWidget();
        // A call button made before the choice existed has the office too.
        $widget->forceFill(['appearance' => array_diff_key(AgentWidget::DEFAULT_APPEARANCE, ['background' => 1])])->save();
        $this->assertSame('office', $widget->fresh()->style()['background']);

        $background = $this->startCall($widget, 'v-1')->assertOk()->json('background');
        $this->assertStringContainsString('/agent-api/sounds/office?v=', $background['url']);
        $this->assertSame(CallBackground::LOOP, $background['loop']);
        $this->assertEqualsWithDelta(CallBackground::RMS, $background['rms'], 1e-9);
        $this->assertEqualsWithDelta(CallBackground::UNDER_DB, $background['under'], 1e-9);

        // The owner's own test call sounds as their callers' calls do.
        $test = $this->withoutLocale()->actingAs($owner)->postJson($this->localizedRoute('voice-agents.test-call', ['agent' => $agent]))->assertOk();
        $this->assertSame($background['url'], $test->json('background.url'));

        // Design → Call sound → None: nothing behind the agent, on the website and in a test call.
        $this->withoutLocale()->actingAs($owner)->patchJson($this->localizedRoute('voice-agents.update', ['agent' => $agent]), ['appearance' => ['background' => 'none']])->assertOk();
        $this->assertSame('none', $widget->fresh()->style()['background']);
        $this->assertNull($this->startCall($widget->fresh(), 'v-2')->assertOk()->json('background'));
        $this->assertNull($this->withoutLocale()->actingAs($owner)->postJson($this->localizedRoute('voice-agents.test-call', ['agent' => $agent]))->assertOk()->json('background'));

        // Only the two choices are kept.
        $this->withoutLocale()->actingAs($owner)->patchJson($this->localizedRoute('voice-agents.update', ['agent' => $agent]), ['appearance' => ['background' => 'jungle']])->assertUnprocessable();
        $widget->forceFill(['appearance' => ['background' => 'jungle']])->save();
        $this->assertSame('office', $widget->fresh()->style()['background']);
    }

    public function test_the_design_tab_offers_the_choice_in_the_owners_language(): void
    {
        $owner = $this->owner();
        $agent = $this->agent($owner);

        $page = $this->actingAs($owner)->getLocalizedRoute('voice-agents.show', ['agent' => $agent])->assertOk();
        $page->assertSee(__('agents.design.group_sound'))->assertSee(__('agents.design.backgrounds.office'))->assertSee(__('agents.design.background_hint'))
            ->assertSee('name="background" value="office" checked', false)->assertSee('name="background" value="none"', false);
        $this->assertSame('صوت المكالمة', trans('agents.design.group_sound', [], 'ar'));
        $this->assertSame('مكتب هادئ', trans('agents.design.backgrounds.office', [], 'ar'));
        $this->assertSame('Quiet office', trans('agents.design.backgrounds.office', [], 'en'));
    }

    public function test_the_sound_is_the_same_file_for_every_website_and_kept_by_browsers(): void
    {
        $response = $this->withHeaders(['Origin' => self::SITE])->get('/agent-api/sounds/office')->assertOk();

        $this->assertSame('audio/mpeg', $response->headers->get('Content-Type'));
        $this->assertSame('*', $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertStringNotContainsString('Origin', (string) $response->headers->get('Vary'));
        $this->assertStringContainsString('max-age=31536000', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('immutable', (string) $response->headers->get('Cache-Control'));
        $this->assertSame(filesize(CallBackground::path('office')), (int) $response->headers->get('Content-Length'));

        $this->get('/agent-api/sounds/jungle')->assertNotFound();
        // Only a sound's own name: nothing else on the server can be asked for.
        $this->assertNotSame(200, $this->get('/agent-api/sounds/..%2F..%2F.env')->getStatusCode());
        $this->assertNull(CallBackground::path('../../.env'));
    }

    public function test_the_call_client_plays_it_apart_from_the_agents_voice_and_never_dips_it(): void
    {
        $script = (string) file_get_contents(public_path('js/agent-call.js'));

        // Fetched without cookies from any website, looped between its two points.
        $this->assertStringContainsString("fetch(bg.url, { credentials: 'omit' })", $script);
        $this->assertStringContainsString('bgSrc.loopStart = from;', $script);
        $this->assertStringContainsString('bgSrc.loopEnd = to;', $script);
        // Straight to the speaker: the orb and the level only follow the agent's own sound.
        $this->assertStringContainsString('bgGain.connect(ctx.destination);', $script);
        // Starts when the call connects and stops with it.
        $this->assertMatchesRegularExpression('/m\.setupComplete\)\s*\{\s*set\(\'listening\'\);\s*startBackground\(\);/', $script);
        $this->assertMatchesRegularExpression('/cancelAnimationFrame\(raf\);\s*stopBackground\(\);/', $script);
    }

    public function test_the_studio_has_the_same_office_as_a_place(): void
    {
        $this->assertTrue(AudioEffects::isValid('office'));
        $this->assertSame('space', AudioEffects::ALL['office'][1]);
        $this->assertSame('مكتب', AudioEffects::label('office', true));
        $this->assertSame('Office', AudioEffects::label('office', false));
        $this->assertStringContainsString('BED(office,', AudioEffects::ALL['office'][6]);
        $this->assertFileExists(AudioEffects::assetPath('bed', 'office'));
        $this->assertFileExists((string) CallBackground::path('office'));
        // The small room no longer says it is an office.
        $this->assertStringNotContainsString('office', strtolower(AudioEffects::ALL['room'][4]));
    }
}
