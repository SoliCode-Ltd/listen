<?php

namespace Tests\Feature\Agents;

use App\Models\Agent;
use App\Models\AgentWidget;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Tests\TestCase;
use ZipArchive;

/**
 * The owner's side of Agents: the list, the setup, an agent's page, saving
 * its parts, the WordPress plugin, and that nobody reaches another member's agent.
 */
class AgentPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.gemini_tts.api_key' => 'test-key']);
    }

    private function agent(User $owner, array $attributes = []): Agent
    {
        return Agent::create(array_merge([
            'user_id' => $owner->id, 'name' => 'Sara', 'business_name' => 'Cairo Smiles', 'business_type' => 'healthcare',
            'jobs' => ['answer'], 'language' => 'ar', 'dialect' => 'ar-EG', 'status' => Agent::DRAFT,
        ], $attributes));
    }

    private function withoutLocale(): static
    {
        return $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class, LaravelLocalizationViewPath::class, ValidateCsrfToken::class]);
    }

    private function patchAgent(Agent $agent, array $data): TestResponse
    {
        return $this->withoutLocale()->patchJson($this->localizedRoute('voice-agents.update', ['agent' => $agent]), $data);
    }

    private function writerAnswers(array $answer): void
    {
        Http::fake(['*generateContent' => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode($answer, JSON_UNESCAPED_UNICODE)]]]]]])]);
    }

    public function test_guests_are_sent_to_sign_in_and_members_see_their_agents(): void
    {
        $this->getLocalizedRoute('voice-agents.index')->assertRedirect();

        $owner = User::factory()->create();
        $this->agent($owner, ['name' => 'Huda']);
        $this->agent(User::factory()->create(), ['name' => 'Someone Else']);

        $this->actingAs($owner)->getLocalizedRoute('voice-agents.index')
            ->assertOk()->assertSee('Huda')->assertDontSee('Someone Else')
            // The Studio | Agents switch is on the app's own pages.
            ->assertSee('class="product-switch"', false);
        $this->actingAs($owner)->getLocalizedRoute('voice-agents.create')->assertOk()->assertSee('data-ag-business-form', false);
    }

    public function test_the_first_step_creates_the_agent_and_writes_it_in_the_owners_language(): void
    {
        $this->writerAnswers([
            'first_message' => 'أهلًا بيك، معاك سارة من كايرو سمايلز، أقدر أساعدك إزاي؟',
            'identity' => 'سارة موظفة استقبال ودودة.',
            'never' => ['لا تخترع الأسعار.'],
            'steps' => ['اسأل المتصل عما يحتاجه.'],
            'handoff' => 'عندما يطلب الطبيب.',
            'knowledge' => 'تبييض الأسنان 1500 جنيه',
            'questions' => [['question' => 'إذا طلب المتصل خصمًا؟', 'options' => ['يوضح أن الأسعار ثابتة', 'يأخذ رقمه'], 'recommended' => 0]],
        ]);
        $owner = User::factory()->create();
        app()->setLocale('ar');

        $response = $this->actingAs($owner)->postJsonLocalizedRoute('voice-agents.store', [
            'business_name' => 'Cairo Smiles', 'name' => 'Sara', 'business_type' => 'healthcare',
            'jobs' => ['answer', 'booking'], 'language' => 'ar', 'dialect' => 'ar-EG', 'notes' => 'Whitening 1,500 EGP',
        ])->assertOk();

        $agent = Agent::where('user_id', $owner->id)->sole();
        $this->assertSame(Agent::DRAFT, $agent->status);
        $this->assertSame('أهلًا بيك، معاك سارة من كايرو سمايلز، أقدر أساعدك إزاي؟', $agent->first_message);
        $this->assertSame(['لا تخترع الأسعار.'], $agent->script['never']);
        $this->assertSame('يوضح أن الأسعار ثابتة', $agent->choices[0]['answer']);
        $this->assertSame(route('voice-agents.edit', $agent), $response->json('agent.urls.edit'));
        $this->assertCount(1, $response->json('questions'));
        // What the owner reads and edits is asked for in Arabic; what callers hear, in Egyptian.
        Http::assertSent(fn ($r) => str_contains($r['contents'][0]['parts'][0]['text'], 'in simple Modern Standard Arabic, 2 or 3 sentences')
            && str_contains($r['contents'][0]['parts'][0]['text'], 'Egyptian'));
    }

    public function test_an_agent_without_the_model_still_gets_two_decisions_in_the_owners_language(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'unavailable']], 503)]);
        $owner = User::factory()->create();

        $this->actingAs($owner)->postJsonLocalizedRoute('voice-agents.store', [
            'business_name' => 'Cairo Smiles', 'name' => 'Sara', 'business_type' => 'healthcare', 'language' => 'en',
        ])->assertOk()->assertJsonPath('written_by', 'fallback')->assertJsonCount(2, 'questions');
    }

    public function test_choosing_a_voice_and_going_live_save_on_the_agent(): void
    {
        $owner = User::factory()->create();
        $agent = $this->agent($owner);
        \App\Models\Voice::query()->create([
            'language' => 'ar', 'gender' => 'female', 'tier' => 'hd', 'display_name' => 'Fatima', 'dialect' => 'ar-SA',
            'internal_voice_id' => 'ar-Gemini-HD-Achernar', 'provider' => 'gemini', 'provider_voice_id' => 'Achernar', 'is_active' => true,
        ]);
        $voice = \App\Support\Agents\AgentVoices::all('ar', true)[0];

        // Going live needs a voice first.
        $this->actingAs($owner)->patchAgent($agent, ['status' => 'live'])->assertStatus(422);

        $this->actingAs($owner)->patchAgent($agent, ['speaker_id' => $voice['id']])->assertOk();
        $agent->refresh();
        $this->assertSame($voice['voice'], $agent->live_voice);
        $this->assertSame(Agent::READY, $agent->status);

        $this->actingAs($owner)->patchAgent($agent, [
            'status' => 'live',
            'allowed_origins' => 'cairosmiles.com, https://www.cairosmiles.com/booking  not a site',
            'script' => ['never' => ['Never quote implants.', ''], 'identity' => ' Calm. '],
            'max_call_seconds' => 300,
        ])->assertOk();
        $agent->refresh();
        $this->assertTrue($agent->isLive());
        $this->assertNotNull($agent->went_live_at);
        $this->assertSame(['Never quote implants.'], $agent->script['never']);
        $this->assertSame('Calm.', $agent->script['identity']);
        $this->assertSame(300, $agent->max_call_seconds);
        $this->assertSame(['https://cairosmiles.com', 'https://www.cairosmiles.com'], $agent->mainWidget()->allowed_origins);
    }

    public function test_a_correction_joins_what_the_agent_knows(): void
    {
        $owner = User::factory()->create();
        $agent = $this->agent($owner, ['knowledge' => 'Cleaning 600 EGP']);

        $this->actingAs($owner)->postJsonLocalizedRoute('voice-agents.correct', ['question' => 'Open on Friday?', 'answer' => 'No, closed on Friday.'], ['agent' => $agent])->assertOk();

        $this->assertStringContainsString("Q: Open on Friday?\nA: No, closed on Friday.", $agent->fresh()->knowledge);
        $this->assertStringContainsString('Cleaning 600 EGP', $agent->fresh()->knowledge);
    }

    public function test_nobody_reaches_another_members_agent(): void
    {
        $agent = $this->agent(User::factory()->create());
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->getLocalizedRoute('voice-agents.show', ['agent' => $agent])->assertNotFound();
        $this->actingAs($stranger)->getLocalizedRoute('voice-agents.edit', ['agent' => $agent])->assertNotFound();
        $this->actingAs($stranger)->patchAgent($agent, ['name' => 'Taken'])->assertNotFound();
        $this->actingAs($stranger)->postJsonLocalizedRoute('voice-agents.test-call', [], ['agent' => $agent])->assertNotFound();
        $this->actingAs($stranger)->getLocalizedRoute('voice-agents.plugin', ['agent' => $agent, 'platform' => 'wordpress'])->assertNotFound();
        $this->assertSame('Sara', $agent->fresh()->name);
    }

    public function test_the_agent_page_shows_its_link_qr_code_embed_line_and_builder_guides(): void
    {
        $owner = User::factory()->create();
        $agent = $this->agent($owner, ['status' => Agent::LIVE, 'live_voice' => 'Achernar']);
        $widget = $agent->mainWidget();

        $page = $this->actingAs($owner)->getLocalizedRoute('voice-agents.show', ['agent' => $agent])->assertOk();

        $page->assertSee(route('agent-talk', ['slug' => $widget->slug]), false)
            ->assertSee('data-ag-qr', false)
            ->assertSee('data-agent=&quot;'.$widget->public_key.'&quot;', false)
            ->assertSee('data-via=&quot;shopify&quot;', false)
            ->assertSee('id="ag-guide-wordpress"', false)
            ->assertSee(route('voice-agents.plugin', ['agent' => $agent, 'platform' => 'wordpress']), false);
    }

    public function test_the_wordpress_plugin_comes_with_the_agent_inside(): void
    {
        $owner = User::factory()->create();
        $agent = $this->agent($owner);
        $key = $agent->mainWidget()->public_key;

        $response = $this->actingAs($owner)->getLocalizedRoute('voice-agents.plugin', ['agent' => $agent, 'platform' => 'wordpress'])->assertOk();

        $this->assertStringContainsString('listen-voice-agent.zip', (string) $response->headers->get('content-disposition'));
        $path = $response->baseResponse->getFile()->getPathname();
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $main = (string) $zip->getFromName('listen-voice-agent/listen-voice-agent.php');
        $this->assertStringContainsString('Plugin Name:       Listen Voice Agent', $main);
        $this->assertStringContainsString("const AGENT_KEY = '".$key."';", $main);
        $this->assertStringContainsString("const SCRIPT_URL = '".asset('js/listen-agent.js')."';", $main);
        $this->assertNotFalse($zip->getFromName('listen-voice-agent/readme.txt'));
        $this->assertStringContainsString('WP_UNINSTALL_PLUGIN', (string) $zip->getFromName('listen-voice-agent/uninstall.php'));
        $zip->close();
        @unlink($path);

        // The plugin's PHP is valid.
        $file = tempnam(sys_get_temp_dir(), 'lvp');
        file_put_contents($file, $main);
        exec('php -l '.escapeshellarg($file).' 2>&1', $out, $code);
        @unlink($file);
        $this->assertSame(0, $code, implode("\n", $out));
    }

    public function test_signing_up_from_the_agents_pages_lands_on_creating_an_agent(): void
    {
        $this->withoutLocale()->get($this->localizedRoute('register').'?next=agents')->assertOk();

        $this->assertSame(route('voice-agents.create'), session('url.intended'));
    }

    public function test_the_first_visit_page_offers_both_sides(): void
    {
        $member = User::factory()->create();

        $this->actingAs($member)->getLocalizedRoute('onboarding.start')
            ->assertOk()
            ->assertSee(route('voice-agents.create'), false)
            ->assertSee(__('agents.start_title'));
    }

    public function test_a_widget_key_is_never_shown_for_another_agent(): void
    {
        $owner = User::factory()->create();
        $mine = $this->agent($owner);
        $other = $this->agent(User::factory()->create());

        $this->actingAs($owner)->getLocalizedRoute('voice-agents.show', ['agent' => $mine])
            ->assertOk()->assertDontSee(AgentWidget::where('agent_id', $other->id)->value('public_key') ?? $other->mainWidget()->public_key);
    }
}
