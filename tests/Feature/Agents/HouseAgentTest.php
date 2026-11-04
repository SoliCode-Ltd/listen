<?php

namespace Tests\Feature\Agents;

use App\Models\Agent;
use App\Models\AgentConversation;
use App\Models\Plan;
use App\Models\User;
use App\Models\Voice;
use App\Support\Agents\HouseAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Tests\TestCase;

/**
 * Listen's own agents: set up by a command, they answer visitors on Listen's
 * public pages, know the real plans, and their calls are on Listen.
 */
class HouseAgentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.gemini_tts.api_key' => 'test-key']);
        Http::fake(['*auth_tokens' => Http::response(['name' => 'auth_tokens/house-key'])]);
        Plan::factory()->free()->create(['monthly_chars' => 10000, 'voice_tier' => 'standard']);
        Plan::factory()->create(['slug' => 'creator', 'name' => 'Creator', 'price_cents' => 2200, 'monthly_chars' => 180000, 'is_active' => true]);
        Voice::query()->create(['language' => 'ar', 'gender' => 'female', 'tier' => 'hd', 'display_name' => 'Sumaya', 'dialect' => 'ar-EG',
            'internal_voice_id' => 'ar-Gemini-HD-Achernar', 'provider' => 'gemini', 'provider_voice_id' => 'Achernar', 'is_active' => true]);
    }

    private function page(string $locale, string $route): \Illuminate\Testing\TestResponse
    {
        app()->setLocale($locale);

        return $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class, LaravelLocalizationViewPath::class])
            ->get($this->localizedRoute($route));
    }

    public function test_the_command_sets_up_one_arabic_and_one_english_agent_and_updates_them(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->artisan('agents:house')->assertSuccessful();
        $this->artisan('agents:house')->assertSuccessful();

        $agents = Agent::where('user_id', $admin->id)->get();
        $this->assertCount(2, $agents, 'running it again never makes a second pair');
        $ar = $agents->firstWhere('language', 'ar');
        $this->assertSame('ar', $ar->setting('house'));
        $this->assertTrue($ar->isLive());
        $this->assertSame(HouseAgent::MAX_SECONDS, $ar->max_call_seconds);
        $this->assertSame('ar-EG', $ar->dialect);
        // It knows the real plans, in the Arabic names the pricing page uses.
        $this->assertStringContainsString('22 دولار في الشهر، حوالي 200 دقيقة', $ar->knowledge);
        $this->assertStringContainsString(__('ui.pricing.plan_name_creator', [], 'ar'), $ar->knowledge);
        $this->assertStringContainsString('$22 a month, about 200 minutes', $agents->firstWhere('language', 'en')->knowledge);
        $this->assertSame('start', $ar->mainWidget()->look('position'), 'opposite the WhatsApp button');
        $this->assertNotNull(HouseAgent::for('ar'));
        $this->assertSame($ar->mainWidget()->public_key, HouseAgent::for('ar')['key']);

        $this->artisan('agents:house --off')->assertSuccessful();
        $this->assertNull(HouseAgent::for('ar'));
    }

    public function test_its_call_button_is_on_listens_public_pages_only(): void
    {
        User::factory()->create(['is_admin' => true]);
        $this->artisan('agents:house')->assertSuccessful();
        $key = HouseAgent::for('en')['key'];

        $this->page('en', 'home')->assertOk()->assertSee('data-agent="'.$key.'"', false);
        $this->page('en', 'agents-landing')->assertOk()->assertSee('data-agent="'.$key.'"', false)->assertSee('data-agl-live', false);
        $this->actingAs(User::factory()->create());
        $this->page('en', 'dashboard')->assertOk()->assertDontSee('data-agent="'.$key.'"', false);
    }

    public function test_the_agents_page_calls_it_by_the_name_it_has_now(): void
    {
        User::factory()->create(['is_admin' => true]);
        $this->artisan('agents:house')->assertSuccessful();
        $this->page('ar', 'agents-landing')->assertOk()->assertSee('تحدّث مع سارة الآن')->assertSee('اسأل سارة، وكيل إسمع نفسه');

        // Given a man's voice, it takes his name (HouseAgent::texts), and the page says it.
        Agent::where('public_id', HouseAgent::for('ar')['agent'])->update(['name' => 'وليد']);
        $this->page('ar', 'agents-landing')->assertOk()->assertSee('تحدّث مع وليد الآن')->assertSee('اسأل وليد، وكيل إسمع نفسه')
            ->assertDontSee('تحدّث مع سارة الآن');
        $this->page('en', 'agents-landing')->assertOk()->assertSee('Talk to Sara now');
    }

    public function test_its_call_button_loads_on_listens_own_pages(): void
    {
        User::factory()->create(['is_admin' => true]);
        $this->artisan('agents:house')->assertSuccessful();
        $url = '/agent-api/widgets/'.HouseAgent::for('en')['key'];

        // The browser asks from Listen's own page, and a GET from the same website carries no Origin.
        $this->getJson($url, ['Sec-Fetch-Site' => 'same-origin', 'Referer' => url('/en')])
            ->assertOk()->assertJsonPath('available', true)->assertJsonPath('name', 'Sara');
        // Another website still cannot load Listen's own agent.
        $this->getJson($url, ['Origin' => 'https://copycat.example'])->assertForbidden();
        $this->getJson($url, ['Sec-Fetch-Site' => 'cross-site', 'Referer' => url('/en')])->assertForbidden();
    }

    public function test_its_calls_are_on_listen_and_short(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->artisan('agents:house')->assertSuccessful();
        $house = HouseAgent::for('ar');

        $call = $this->call('POST', '/agent-api/calls', [], [], [], ['CONTENT_TYPE' => 'text/plain', 'HTTP_ORIGIN' => rtrim(url('/'), '/'), 'REMOTE_ADDR' => '203.0.113.7'],
            json_encode(['key' => $house['key'], 'visitor' => 'v-house']))->assertOk();

        $this->assertTrue($call->json('free'));
        $this->assertSame(HouseAgent::MAX_SECONDS, $call->json('max_seconds'));
        $this->assertTrue(AgentConversation::where('public_id', $call->json('conversation'))->sole()->free);
        $this->assertSame(0, (int) \App\Models\UsageCounter::where('user_id', $admin->id)->sum('total_chars'));
        // Another website cannot carry Listen's own agent.
        $this->call('POST', '/agent-api/calls', [], [], [], ['CONTENT_TYPE' => 'text/plain', 'HTTP_ORIGIN' => 'https://copycat.example'],
            json_encode(['key' => $house['key']]))->assertForbidden();
    }

    public function test_the_homepage_shows_agents_working(): void
    {
        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            $this->page($locale, 'home')->assertOk()
                ->assertSee(__('agents_landing.home_title'))
                ->assertSee(route('agents-landing'), false)
                // A real call on its own stage, recorded in the page's own language.
                ->assertSee('data-agl-real', false)
                ->assertSee($locale === 'ar' ? 'audio/agents/calls/ar-sa-1.mp3' : 'audio/agents/calls/en-us-1.mp3', false);
        }
    }

    public function test_the_recorded_calls_are_in_the_pages_language_with_women_and_men(): void
    {
        // Each page's calls: its own language only, women and men, every clip on disk.
        $speakers = ['ar' => ['ar-fatima', 'ar-hamed', 'ar-sumaya', 'ar-raed'], 'en' => ['en-caleb', 'en-alice', 'en-sienna']];
        foreach ($speakers as $locale => $faces) {
            app()->setLocale($locale);
            $html = (string) $this->page($locale, 'home')->assertOk()->getContent();
            $other = $locale === 'ar' ? 'en' : 'ar';
            $this->assertStringNotContainsString('audio/agents/calls/'.$other.'-', $html, 'a call in the other language');
            $this->assertStringNotContainsString('images/speakers/'.$other.'-', substr($html, (int) strpos($html, 'data-agl-real'), 60000));
            foreach ($faces as $face) {
                $this->assertStringContainsString('images/speakers/'.$face.'.webp', $html, $face.' is not on the '.$locale.' homepage');
            }
            foreach ((array) __('agents_landing.real_calls') as $key => $call) {
                foreach ($call['turns'] as $turn) {
                    $this->assertNotSame('', trim($turn[1]), $key.' has an empty line');
                    if (! empty($turn[3])) {
                        $this->assertFileExists(public_path('audio/agents/calls/'.$turn[3].'.mp3'));
                    }
                }
            }
        }
        // No line names the brand in the other language: إسمع on the Arabic page.
        app()->setLocale('ar');
        $this->assertStringNotContainsString('Listen', json_encode(__('agents_landing.real_calls'), JSON_UNESCAPED_UNICODE));
    }
}
