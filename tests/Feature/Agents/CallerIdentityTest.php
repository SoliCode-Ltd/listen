<?php

namespace Tests\Feature\Agents;

use App\Models\Agent;
use App\Models\AgentContact;
use App\Models\AgentConversation;
use App\Models\AgentWidget;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Voice;
use App\Services\Agents\AgentCalls;
use App\Services\Agents\WordPressPlugin;
use App\Support\Agents\CallerIdentity;
use App\Support\Agents\HouseAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Tests\TestCase;

/**
 * Who is calling: a website that knows who is signed in signs their email and name for its call
 * button, and the call keeps them for the agent's owner. Listen's own pages sign their members.
 * Nothing unsigned, altered, expired or from a link page is ever kept.
 */
class CallerIdentityTest extends TestCase
{
    use RefreshDatabase;

    private const SITE = 'https://cairosmiles.com';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.gemini_tts.api_key' => 'test-key']);
        Http::fake(['*auth_tokens' => Http::response(['name' => 'auth_tokens/live-key'])]);
        Plan::factory()->free()->create(['monthly_chars' => 10000, 'voice_tier' => 'standard']);
        Voice::query()->create(['language' => 'ar', 'gender' => 'female', 'tier' => 'hd', 'display_name' => 'Sumaya', 'dialect' => 'ar-EG',
            'internal_voice_id' => 'ar-Gemini-HD-Achernar', 'provider' => 'gemini', 'provider_voice_id' => 'Achernar', 'is_active' => true]);
    }

    private function page(string $locale, string $route): TestResponse
    {
        app()->setLocale($locale);

        return $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class, LaravelLocalizationViewPath::class])
            ->get($this->localizedRoute($route));
    }

    /** The call client sends JSON as text/plain. */
    private function startCall(array $body, array $server = []): TestResponse
    {
        return $this->call('POST', '/agent-api/calls', [], [], [], $server + ['CONTENT_TYPE' => 'text/plain;charset=UTF-8', 'REMOTE_ADDR' => '203.0.113.9'], json_encode($body));
    }

    /** A business's live agent, its button allowed on its own website. */
    private function businessAgent(): Agent
    {
        $plan = Plan::factory()->create(['slug' => 'pro-'.uniqid(), 'price_cents' => 4500, 'voice_tier' => 'hd', 'monthly_chars' => 500000,
            'allow_overage' => false, 'agents_enabled' => true, 'agent_concurrent_calls' => 20]);
        $owner = User::factory()->create();
        Subscription::create(['user_id' => $owner->id, 'plan_id' => $plan->id, 'status' => 'active']);
        $agent = Agent::create(['user_id' => $owner->id, 'name' => 'Sara', 'business_name' => 'Cairo Smiles', 'business_type' => 'healthcare',
            'jobs' => ['answer'], 'language' => 'ar', 'dialect' => 'ar-EG', 'speaker_id' => 'ar-Gemini-HD-Achernar', 'live_voice' => 'Achernar',
            'status' => Agent::LIVE, 'max_call_seconds' => 600, 'first_message' => 'أهلًا بيك']);
        $agent->mainWidget()->forceFill(['allowed_origins' => [self::SITE]])->save();

        return $agent;
    }

    /** What the business's server puts on the button's tag for its signed-in visitor. */
    private function signed(string $key, string $email, string $name, ?int $expires = null): array
    {
        $expires ??= time() + 3600;

        return ['email' => $email, 'name' => $name, 'expires' => (string) $expires, 'signature' => CallerIdentity::sign($key, $email, $name, $expires)];
    }

    public function test_a_member_signed_in_to_listen_is_kept_with_their_call_to_listens_own_agent(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->artisan('agents:house')->assertSuccessful();
        $key = HouseAgent::for('en')['key'];
        $member = User::factory()->create(['name' => 'Mona Adel', 'email' => 'mona@example.com']);

        $html = $this->actingAs($member)->page('en', 'home')->assertOk()->getContent();
        preg_match('/<script src="[^"]*listen-agent\.js"([^>]*)>/', $html, $tag);
        $this->assertNotEmpty($tag, 'the call button is on the page');
        preg_match_all('/data-caller-(email|name|expires|signature)="([^"]*)"/', $tag[1], $found, PREG_SET_ORDER);
        $caller = [];
        foreach ($found as [, $field, $value]) {
            $caller[$field] = html_entity_decode($value, ENT_QUOTES);
        }
        $this->assertSame('mona@example.com', $caller['email'] ?? null);
        $this->assertSame('Mona Adel', $caller['name'] ?? null);

        // The button sends them as the page gave them.
        $start = $this->startCall(['key' => $key, 'visitor' => 'v-mona', 'caller' => $caller], ['HTTP_ORIGIN' => rtrim(url('/'), '/')])->assertOk();
        $call = AgentConversation::where('public_id', $start->json('conversation'))->sole();
        $this->assertSame(['email' => 'mona@example.com', 'name' => 'Mona Adel'], $call->caller);

        // Listen's team sees who called, with the call.
        $agent = Agent::where('public_id', HouseAgent::for('en')['agent'])->sole();
        $this->actingAs($admin)->page('en', 'voice-agents.index');
        $this->get($this->localizedRoute('voice-agents.show', ['agent' => $agent]))->assertOk()
            ->assertSee(__('agents.conversation_caller', [], 'en'))->assertSee('mailto:mona@example.com', false)->assertSee('Mona Adel');
    }

    public function test_a_visitor_who_is_not_signed_in_stays_anonymous(): void
    {
        User::factory()->create(['is_admin' => true]);
        $this->artisan('agents:house')->assertSuccessful();

        $this->page('en', 'home')->assertOk()->assertSee('data-agent="'.HouseAgent::for('en')['key'].'"', false)->assertDontSee('data-caller-', false);
    }

    public function test_a_business_website_that_signs_its_visitor_shows_who_called(): void
    {
        $agent = $this->businessAgent();
        $key = $agent->mainWidget()->public_key;

        $start = $this->startCall(['key' => $key, 'visitor' => 'v-1', 'caller' => $this->signed($key, 'ahmed@cairosmiles.com', 'Ahmed <b>Ali</b>')], ['HTTP_ORIGIN' => self::SITE])->assertOk();

        $call = AgentConversation::where('public_id', $start->json('conversation'))->sole();
        $this->assertSame(['email' => 'ahmed@cairosmiles.com', 'name' => 'Ahmed Ali'], $call->caller);

        // When the agent saves the caller's number, their customer record carries the email too.
        app(AgentCalls::class)->tool($call, 'save_contact', ['phone' => '+20 100 123 4567', 'note' => 'Wants a cleaning on Sunday']);
        $customer = AgentContact::query()->where('user_id', $agent->user_id)->sole();
        $this->assertSame(['+201001234567', 'ahmed@cairosmiles.com', 'Ahmed Ali'], [$customer->phone, $customer->email, $customer->name]);
    }

    public function test_nothing_unsigned_altered_expired_or_meant_for_another_button_is_kept(): void
    {
        $agent = $this->businessAgent();
        $key = $agent->mainWidget()->public_key;
        $other = 'lw_'.str_repeat('a', 28);
        $good = $this->signed($key, 'ahmed@cairosmiles.com', 'Ahmed');
        $cases = [
            'no signature' => ['email' => 'ahmed@cairosmiles.com', 'name' => 'Ahmed', 'expires' => (string) (time() + 3600)],
            'another name' => ['name' => 'Someone else'] + $good,
            'another email' => ['email' => 'boss@cairosmiles.com'] + $good,
            'another button' => $this->signed($other, 'ahmed@cairosmiles.com', 'Ahmed'),
            'expired' => $this->signed($key, 'ahmed@cairosmiles.com', 'Ahmed', time() - 5),
            'for a year' => $this->signed($key, 'ahmed@cairosmiles.com', 'Ahmed', time() + 365 * 86400),
            'not an email' => $this->signed($key, 'ahmed', 'Ahmed'),
            'not a caller' => 'ahmed@cairosmiles.com',
            'not text' => ['email' => ['ahmed@cairosmiles.com'], 'name' => ['Ahmed'], 'expires' => [time() + 3600], 'signature' => [$good['signature']]],
        ];
        foreach ($cases as $case => $caller) {
            $start = $this->startCall(['key' => $key, 'visitor' => 'v-'.md5($case), 'caller' => $caller], ['HTTP_ORIGIN' => self::SITE, 'REMOTE_ADDR' => '203.0.113.'.(10 + array_search($case, array_keys($cases), true))]);
            $start->assertOk();
            $this->assertNull(AgentConversation::where('public_id', $start->json('conversation'))->sole()->caller, $case);
        }
    }

    public function test_a_call_from_a_link_page_never_keeps_a_caller(): void
    {
        $agent = $this->businessAgent();
        $widget = $agent->mainWidget();

        $start = $this->startCall(['slug' => $widget->slug, 'visitor' => 'v-link', 'caller' => $this->signed($widget->public_key, 'ahmed@cairosmiles.com', 'Ahmed')])->assertOk();

        $this->assertNull(AgentConversation::where('public_id', $start->json('conversation'))->sole()->caller);
    }

    public function test_calls_go_on_before_the_server_can_keep_who_called(): void
    {
        $agent = $this->businessAgent();
        $key = $agent->mainWidget()->public_key;
        // The code arrives a minute before its migration.
        Schema::table('agent_conversations', fn ($table) => $table->dropColumn('caller'));
        $this->assertFalse(AgentConversation::keepsCaller());

        $this->startCall(['key' => $key, 'visitor' => 'v-early', 'caller' => $this->signed($key, 'ahmed@cairosmiles.com', 'Ahmed')], ['HTTP_ORIGIN' => self::SITE])
            ->assertOk()->assertJsonPath('free', false);
        $this->assertSame(1, AgentConversation::count());
        // The owner's agent page shows its calls as before.
        $this->actingAs($agent->user)->page('en', 'voice-agents.index');
        $this->get($this->localizedRoute('voice-agents.show', ['agent' => $agent]))->assertOk()
            ->assertDontSee(__('agents.conversation_caller', [], 'en'));
    }

    public function test_the_install_panel_gives_developers_the_caller_secret_and_the_plugin_signs_with_it(): void
    {
        $agent = $this->businessAgent();
        $widget = $agent->mainWidget();
        $secret = CallerIdentity::secret($widget->public_key);

        $this->actingAs($agent->user)->page('en', 'voice-agents.index');
        $this->get($this->localizedRoute('voice-agents.show', ['agent' => $agent]))->assertOk()
            ->assertSee('LISTEN_AGENT_CALLER_SECRET='.$secret, false)
            ->assertSee('data-caller-signature', false);

        $files = app(WordPressPlugin::class)->files($widget);
        $this->assertStringContainsString("const CALLER_SECRET = '".$secret."';", $files['listen-voice-agent.php']);
        $this->assertStringContainsString("hash_hmac('sha256', \$email . \"\\n\" . \$name . \"\\n\" . \$expires, CALLER_SECRET)", $files['src/Plugin.php']);
        $this->assertStringContainsString("public const CALLERS = 'listen_voice_agent_callers';", $files['src/Settings.php']);
        // Off until the site's owner turns it on.
        $this->assertStringContainsString("get_option(self::CALLERS, '0')", $files['src/Settings.php']);
        $this->assertSame(WordPressPlugin::VERSION, '1.3.0');
        // Another button's secret is another secret.
        $this->assertNotSame($secret, CallerIdentity::secret('lw_'.str_repeat('b', 28)));
        $this->assertInstanceOf(AgentWidget::class, $widget);
    }
}
