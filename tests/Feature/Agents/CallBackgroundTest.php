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
 * The quiet office a caller hears behind the agent: on every call (owners no longer choose), the
 * same sound for every website, and the studio's Office environment made from the same colleagues.
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

    public function test_every_call_has_a_quiet_office_behind_the_agent(): void
    {
        $owner = $this->owner();
        $agent = $this->agent($owner);
        $widget = $agent->mainWidget();
        // A call button made before there was a background has the office too.
        $widget->forceFill(['appearance' => array_diff_key(AgentWidget::DEFAULT_APPEARANCE, ['background' => 1])])->save();

        $background = $this->startCall($widget, 'v-1')->assertOk()->json('background');
        $this->assertStringContainsString('/agent-api/sounds/office?v=', $background['url']);
        $this->assertSame(CallBackground::LOOP, $background['loop']);
        $this->assertEqualsWithDelta(CallBackground::RMS, $background['rms'], 1e-9);
        $this->assertEqualsWithDelta(CallBackground::UNDER_DB, $background['under'], 1e-9);

        // The owner's own test call sounds as their callers' calls do.
        $test = $this->withoutLocale()->actingAs($owner)->postJson($this->localizedRoute('voice-agents.test-call', ['agent' => $agent]))->assertOk();
        $this->assertSame($background['url'], $test->json('background.url'));

        // Owners no longer choose: a call button saved with "None" while there was a choice has the office too.
        $widget->forceFill(['appearance' => ['background' => 'none'] + AgentWidget::DEFAULT_APPEARANCE])->save();
        $this->assertSame($background['url'], $this->startCall($widget->fresh(), 'v-2')->assertOk()->json('background.url'));
        $this->assertSame($background['url'], $this->withoutLocale()->actingAs($owner)->postJson($this->localizedRoute('voice-agents.test-call', ['agent' => $agent]))->assertOk()->json('background.url'));
        $this->assertSame(CallBackground::sound('office'), CallBackground::for(null));
    }

    public function test_the_design_tab_has_no_background_choice(): void
    {
        $owner = $this->owner();
        $agent = $this->agent($owner);

        $page = $this->actingAs($owner)->getLocalizedRoute('voice-agents.show', ['agent' => $agent])->assertOk();
        $html = $page->getContent();
        foreach (['name="background"', 'data-ag-sound', 'ag-sound-play', 'ag-sound-choice'] as $gone) {
            $this->assertStringNotContainsString($gone, $html);
        }
        $this->assertArrayNotHasKey('sound', $this->pageConfig($html)['design']);
        foreach (['ar', 'en'] as $l) {
            app()->setLocale($l);
            $this->assertFalse(\Illuminate\Support\Facades\Lang::has('agents.design.group_sound'));
            $this->assertFalse(\Illuminate\Support\Facades\Lang::has('agents.design.backgrounds'));
        }

        // Nothing is left of the sample player, and the call client's office measure is still shared.
        $page = (string) file_get_contents(public_path('js/agents.js'));
        $this->assertStringNotContainsString('soundTry', $page);
        $this->assertStringNotContainsString('stopTry', $page);
        $this->assertStringNotContainsString('.ag-sound-', (string) file_get_contents(public_path('css/agents.css')));
        $call = (string) file_get_contents(public_path('js/agent-call.js'));
        $this->assertStringContainsString('window.ListenAgentCall = { start: start, officeLevel: officeLevel, spokenPower: spokenPower, converter: converter, micGate: micGate };', $call);
        $this->assertStringContainsString('return officeLevel(voiceN ? voicePow : 0, bg);', $call);
        $this->assertNull(CallBackground::sound('none'));
        $this->assertNull(CallBackground::sound('jungle'));
    }

    /** The page's settings for agents.js (window.LISTEN_AGENTS, which Js::from writes as JSON.parse('...')). */
    private function pageConfig(string $html): array
    {
        $this->assertSame(1, preg_match("/window\\.LISTEN_AGENTS = JSON\\.parse\\('(.*?)'\\);/s", $html, $m));

        return json_decode((string) json_decode('"'.$m[1].'"'), true);
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
        $this->assertStringContainsString('bgGain.connect(vctx.destination);', $script);
        // Starts when the call connects and stops with it.
        $this->assertMatchesRegularExpression('/m\.setupComplete\)\s*\{\s*set\(\'listening\'\);\s*startBackground\(\);/', $script);
        $this->assertMatchesRegularExpression('/cancelAnimationFrame\(raf\);\s*stopBackground\(\);/', $script);
    }

    public function test_the_agents_voice_plays_in_the_microphones_engine_and_its_goodbye_is_never_cut(): void
    {
        $script = (string) file_get_contents(public_path('js/agent-call.js'));

        // One audio engine for the microphone and the voice, as every phone plays it: a second engine at
        // the voice's 24 kHz stayed silent on a phone (the agent heard the caller, the caller heard nothing).
        $this->assertStringNotContainsString('sampleRate: 24000', $script);
        $this->assertStringNotContainsString('voiceEngine', $script);
        $this->assertStringContainsString("vctx = ctx;\n      conv = converter(24000, ctx.sampleRate);", $script);
        $this->assertStringContainsString('outAnalyser.connect(vctx.destination);', $script);
        $this->assertStringContainsString('micSource = ctx.createMediaStreamSource(stream);', $script);
        $this->assertSame(2, substr_count($script, 'closeEngines(300);'), 'busy and the end close the engine');
        $this->assertSame(1, substr_count($script, 'closeEngines(0);'), 'an error closes the engine');

        // Each 24 kHz piece is brought to the engine's rate here, carrying on from the piece before (a
        // windowed-sinc converter), so no piece is converted on its own roughly (a metallic shimmer).
        $this->assertStringContainsString('function converter(from, to) {', $script);
        $this->assertStringContainsString('L = to / a, M = from / a, H = 32, W = 2 * H;', $script);
        $this->assertStringContainsString('var fc = 0.92 * Math.min(1, L / M)', $script);
        $this->assertStringContainsString('(0.42 + 0.5 * Math.cos(Math.PI * u) + 0.08 * Math.cos(2 * Math.PI * u))', $script);
        $this->assertStringContainsString('rest = all.slice(cur);', $script);
        $this->assertStringContainsString('queueVoice(conv ? conv.push(f) : f, fresh);', $script);
        // On the engine's own frames, one piece straight after the other.
        $this->assertStringContainsString('var at = Math.max(Math.ceil((vctx.currentTime + (fresh ? START_LEAD : 0.03)) * R), playFrame);', $script);
        $this->assertStringContainsString('playFrame = at + Math.round(b.duration * R);', $script);
        // A line's last bit comes at the end of the turn; the caller talking over the agent drops it.
        $this->assertMatchesRegularExpression('/sc\.turnComplete\) \{ endVoice\(\); flushTurns\(\); dropTurn = false; if \(closing\) closingDone = true; \}/', $script);
        $this->assertMatchesRegularExpression('/playFrame = 0;\s*if \(conv\) conv\.reset\(\);/', $script);

        // A new line starts with 0.15 s of the voice in hand, so a short pause in the voice model's
        // stream early in the line (a test call's greeting: 0.18 s of silence mid-word) does not break
        // a word; within a line a late piece plays at once.
        $this->assertStringContainsString('START_LEAD = 0.15', $script);
        $this->assertStringContainsString('var fresh = !sources.size && Date.now() - voiceEndAt > 400;', $script);
        $this->assertStringContainsString('if (fresh && conv) conv.reset();', $script);
        $this->assertSame(2, substr_count($script, 'voiceEndAt = Date.now();'), 'the voice ending and the caller talking over it both mark the end of a line');

        // The agent hangs up while its goodbye is still on its way: it plays to the end of the turn.
        $this->assertStringContainsString('if (hangUp) hangUpAfterGoodbye();', $script);
        $this->assertStringNotContainsString('if (hangUp) hungUp = true;', $script);
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
