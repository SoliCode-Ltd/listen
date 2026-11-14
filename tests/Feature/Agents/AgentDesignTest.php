<?php

namespace Tests\Feature\Agents;

use App\Models\Agent;
use App\Models\Plan;
use App\Models\AgentWidget;
use App\Models\User;
use App\Support\Agents\AgentInstructions;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Tests\TestCase;

/**
 * The call button's look (the Design tab), what a website receives, English
 * accents for English agents, and the English Agents page speaking English only.
 */
class AgentDesignTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Members here are on the free plan, which includes agents in these tests.
        Plan::factory()->free()->create(['agents_enabled' => true]);
    }

    private function agent(User $owner, array $attributes = []): Agent
    {
        return Agent::create(array_merge([
            'user_id' => $owner->id, 'name' => 'Sara', 'business_name' => 'Cairo Smiles', 'business_type' => 'healthcare',
            'jobs' => ['answer'], 'language' => 'ar', 'dialect' => 'ar-EG', 'status' => Agent::LIVE, 'live_voice' => 'Achernar',
        ], $attributes));
    }

    private function withoutLocale(): static
    {
        return $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class, LaravelLocalizationViewPath::class, ValidateCsrfToken::class]);
    }

    public function test_the_look_keeps_only_known_values_and_turns_two_colours_into_an_orb(): void
    {
        $widget = $this->agent(User::factory()->create())->mainWidget();
        $widget->appearance = ['style' => 'hologram', 'icon' => 'rocket', 'accent' => 'red', 'size' => 'xl', 'palette' => 'custom', 'orb_colors' => ['#112233'], 'title' => '<b>Hi</b>'];
        $look = $widget->style();
        $this->assertSame('orb', $look['style']);
        $this->assertSame('phone', $look['icon']);
        $this->assertSame('#7659FA', $look['accent']);
        $this->assertSame('m', $look['size']);
        // A custom orb needs two colours; with one it falls back to Listen's.
        $this->assertSame('listen', $look['palette']);
        $this->assertSame(AgentWidget::PALETTES['listen'], $look['colors']);
        $this->assertSame('Hi', $look['title']);

        $widget->appearance = ['palette' => 'custom', 'orb_colors' => ['112233', '#aabbcc'], 'accent' => '#10b981', 'style' => 'bar', 'captions' => false];
        $look = $widget->style();
        $this->assertSame(['#112233', '#AABBCC'], $look['orb_colors']);
        $this->assertSame('#112233', $look['colors'][0]);
        $this->assertSame('#AABBCC', $look['colors'][2]);
        $this->assertSame('#10B981', $look['accent']);
        $this->assertSame('bar', $look['style']);
        $this->assertFalse($look['captions']);
        // The photo next to the agent's name is on unless the owner turns it off.
        $this->assertTrue($look['photo']);
        $widget->appearance = ['photo' => false];
        $this->assertFalse($widget->style()['photo']);
    }

    public function test_the_owner_saves_the_look_and_websites_get_it_without_new_code(): void
    {
        $owner = User::factory()->create();
        $agent = $this->agent($owner);
        $this->actingAs($owner)->withoutLocale()->patchJson($this->localizedRoute('voice-agents.update', ['agent' => $agent]), ['appearance' => [
            'style' => 'waves', 'icon' => 'headset', 'size' => 'l', 'shape' => 'round', 'accent' => '#0F172A',
            'palette' => 'custom', 'orb_colors' => ['#064E3B', '#A7F3D0'], 'radius' => 'soft', 'captions' => false,
            'title' => 'Cairo Smiles Clinic', 'subtitle' => '', 'font' => 'rounded', 'theme' => 'dark', 'position' => 'start',
        ]])->assertOk();
        $this->actingAs($owner)->withoutLocale()->patchJson($this->localizedRoute('voice-agents.update', ['agent' => $agent]), ['appearance' => ['accent' => 'blue']])
            ->assertUnprocessable();

        $config = $this->getJson('/agent-api/widgets/'.$agent->mainWidget()->public_key)->assertOk()->json();
        $this->assertSame('waves', $config['look']['style']);
        $this->assertSame('headset', $config['look']['icon']);
        $this->assertSame('#0F172A', $config['look']['accent']);
        $this->assertSame('#064E3B', $config['colors'][0]);
        $this->assertFalse($config['look']['captions']);
        $this->assertSame('Cairo Smiles Clinic', $config['text']['title']);
        // An emptied line goes back to the default words; the footer only names Listen.
        $this->assertSame('مساعد صوتي ذكي', $config['text']['subtitle']);
        $this->assertSame('مدعوم من إسمع', $config['text']['powered']);
        // No voice picked yet, so no photo next to the name; the owner can also turn the photo off.
        $this->assertNull($config['avatar']);
        $this->assertTrue($config['look']['photo']);
        $this->assertArrayNotHasKey('note', $config['text']);
    }

    public function test_the_chat_card_shows_the_owners_own_line_under_the_name(): void
    {
        $owner = User::factory()->create();
        $agent = $this->agent($owner);
        $card = fn (): array => (array) $this->getJson('/agent-api/widgets/'.$agent->mainWidget()->public_key)->assertOk()->json();

        // No line of the owner's: the look says so, and the chat card says "Available now".
        $none = $card();
        $this->assertNull($none['look']['subtitle']);
        $this->assertSame('متاح الآن', $none['chat']['online']);

        // The owner's line (Design: "Line under the title") goes to the website as theirs.
        $this->actingAs($owner)->withoutLocale()->patchJson($this->localizedRoute('voice-agents.update', ['agent' => $agent]), ['appearance' => ['subtitle' => 'متاح دلوقتي', 'captions' => false]])->assertOk();
        $mine = $card();
        $this->assertSame('متاح دلوقتي', $mine['look']['subtitle']);
        $this->assertSame('متاح دلوقتي', $mine['text']['subtitle']);
        $this->assertFalse($mine['look']['captions']);

        // The card writes it on the chat as on the call card: before, the chat always said "Available now".
        $widget = (string) file_get_contents(public_path('js/agent-widget.js'));
        $this->assertStringContainsString("parts.under = el('bdi', '', look.subtitle || chatText.online || text.subtitle || '');", $widget);
        $this->assertStringContainsString("if (parts.under) parts.under.textContent = (chatOn ? look.subtitle || chatText.online : '') || text.subtitle || '';", $widget);
        // "Show what is said during the call" switched off holds on the chat card too, for the
        // call's words only: what is written in the chat always shows.
        $this->assertStringNotContainsString('asChat.captions = true', $widget);
        $this->assertStringContainsString("function unwritten() { return look.captions === false && (!chatOn || live() || stateNow === 'waiting'); }", $widget);
        $this->assertStringNotContainsString("|| look.captions === false)", $widget);
    }

    public function test_the_design_tab_previews_the_card_visitors_get(): void
    {
        $owner = User::factory()->create();
        $agent = $this->agent($owner, ['first_message' => 'أهلًا بيك في عيادة البسمة']);
        app()->setLocale('en');
        $page = fn () => $this->actingAs($owner)->withoutLocale()->get($this->localizedRoute('voice-agents.show', ['agent' => $agent->fresh()]))->assertOk();

        // Chat on (as every agent starts): the preview gets the chat, its greeting and its words,
        // and the settings say which of them belong to the call card.
        $on = $page();
        $on->assertSee('data-ag-design data-chat="1" data-call="1"', false)
            // The slim bar has no card to write in: not offered while the chat is on.
            ->assertSee('value="bar"  disabled', false)
            ->assertSee('Available with written chat off')
            ->assertSee('With written chat on, the card opens on the chat and a call shows inside it.')
            ->assertSee('Shown when written chat is off.')
            ->assertSee('data-chat-placeholder="متاح الآن"', false);
        $html = (string) $on->getContent();
        $config = json_decode(json_decode('"'.\Illuminate\Support\Str::between($html, "window.LISTEN_AGENTS = JSON.parse('", "');").'"'), true);
        $this->assertSame(['chat' => true, 'call' => true], $config['design']['cfg']['channels']);
        $this->assertSame('أهلًا بيك في عيادة البسمة', $config['design']['cfg']['chat']['greeting']);
        $this->assertSame('متاح الآن', $config['design']['cfg']['chat']['online']);
        $this->assertSame('اكتب رسالتك', $config['design']['cfg']['chat']['placeholder']);

        // Calls switched off, then the chat instead: the preview's card follows.
        \App\Support\Agents\AgentChannels::choose($agent, true, false);
        $agent->save();
        $html = (string) $page()->getContent();
        $config = json_decode(json_decode('"'.\Illuminate\Support\Str::between($html, "window.LISTEN_AGENTS = JSON.parse('", "');").'"'), true);
        $this->assertSame(['chat' => true, 'call' => false], $config['design']['cfg']['channels']);
        // Calls off: no call style to choose (one line says why), and no phone on the button.
        $page()->assertSee('data-ag-design data-chat="1" data-call="0"', false)
            ->assertSee('Voice calls are off. Turn them on in Setup to choose how a call looks.')
            ->assertSee('With voice calls off, the button shows a chat icon.')
            ->assertSee('data-ag-design-styles disabled', false);
        \App\Support\Agents\AgentChannels::choose($agent, false, true);
        $agent->save();
        $page()->assertSee('data-ag-design data-chat="0" data-call="1"', false)->assertDontSee('data-ag-design-styles disabled', false);

        // The page draws it with the same code as the website, takes nothing from the preview's
        // box, and redraws when Setup switches a channel or saves a new greeting.
        $widget = (string) file_get_contents(public_path('js/agent-widget.js'));
        $js = (string) file_get_contents(public_path('js/agents.js'));
        $this->assertStringContainsString('if (preview) { parts.input.readOnly = true; parts.input.tabIndex = -1; }', $widget);
        $this->assertStringContainsString("if (!chatOn || preview) return;", $widget);
        $this->assertStringContainsString("if (!log.length && !chat && !preview) loadChat();", $widget);
        $this->assertStringContainsString("if (C.design && C.design.follow) C.design.follow({ channels: { chat: !!want.chat, call: !!want.calls } });", $js);
        $this->assertStringContainsString("if (C.design && C.design.follow) C.design.follow({ greeting: \$('[name=\"first_message\"]', sf).value.trim() });", $js);
        $this->assertStringContainsString("box.setAttribute('data-chat', chatOn ? '1' : '0');", $js);
        // A style that only shows during a call is shown by a pretend call as it is picked.
        $this->assertStringContainsString("if (restyled && widget && callNow() && (look.style === 'bar' || chatNow())) { widget.demo(true); setPlaying(true); stopLater(); }", $js);
        $this->assertStringContainsString("if (chatOn && slim.checked && orb) { orb.checked = true; look.style = 'orb'; styleBefore = 'orb'; }", $js);
    }

    public function test_a_change_is_set_on_the_card_as_it_stands_and_never_makes_it_blink(): void
    {
        $widget = (string) file_get_contents(public_path('js/agent-widget.js'));
        $js = (string) file_get_contents(public_path('js/agents.js'));

        // Words, colours, size, corners, font, theme and side are set on the card that is there;
        // only a change to its frame draws it again.
        $this->assertStringContainsString("var MENDED = ['title', 'subtitle', 'label', 'ready_text', 'ready_show', 'accent', 'palette', 'orb_colors', 'colors', 'size', 'radius', 'font', 'theme', 'position', 'background'];", $widget);
        $this->assertStringContainsString('if (same) { redrawn = false; mend(); return; }', $widget);
        // The card's own state (open, on a call) is never dropped while its look is set.
        $this->assertStringNotContainsString("box.className = 'lva is-'", $widget);
        $this->assertStringContainsString("box.classList.toggle('is-left', side === 'left');", $widget);
        // Drawn again, the button and the lines already there do not arrive a second time.
        $this->assertStringContainsString("if (redrawn) launch.classList.add('is-there');", $widget);
        $this->assertStringContainsString('.lva-launch.is-there,.lva-launch.is-there .lva-ico::after,.lva-line.is-there{animation:none}', $widget);
        $this->assertStringContainsString("if (showing && redrawn) n.classList.add('is-there');", $widget);
        // The Design tab keeps its one preview: it is never thrown away and built again.
        $this->assertStringNotContainsString('widget.destroy()', $js);
        $this->assertStringContainsString('widget.update(cfg(), { dark: dark });', $js);
    }

    public function test_the_design_tab_shows_every_choice_with_a_live_preview(): void
    {
        $owner = User::factory()->create();
        $agent = $this->agent($owner, ['language' => 'en', 'dialect' => 'en-GB', 'name' => 'Oliver', 'business_name' => 'Kensington Florist']);
        app()->setLocale('en');
        $this->actingAs($owner)->withoutLocale()->get($this->localizedRoute('voice-agents.show', ['agent' => $agent]))
            ->assertOk()
            ->assertSee('data-ag-design', false)
            ->assertSee('data-ag-design-site', false)
            ->assertSee('js/agent-widget.js', false)
            ->assertSee(__('agents.design.title'))
            ->assertSee(__('agents.design.styles.bar')[0])
            ->assertSee(__('agents.design.palettes.gold'))
            ->assertSee('name="captions"', false);
    }

    public function test_an_english_agent_speaks_the_accent_its_owner_picks(): void
    {
        Http::fake(['*generateContent' => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode([
            'first_message' => 'Hello, Kensington Florist, Oliver speaking.', 'identity' => 'Oliver, a friendly florist.',
            'never' => [], 'steps' => [], 'handoff' => '', 'knowledge' => '', 'questions' => [],
        ])]]]]]])]);
        config(['services.gemini_tts.api_key' => 'test-key']);
        $owner = User::factory()->create();
        app()->setLocale('en');
        $this->actingAs($owner)->postJsonLocalizedRoute('voice-agents.store', [
            'business_name' => 'Kensington Florist', 'name' => 'Oliver', 'business_type' => 'retail',
            'jobs' => ['answer'], 'language' => 'en', 'dialect' => 'en-GB-LD',
        ])->assertOk();
        $agent = Agent::where('user_id', $owner->id)->sole();
        $expected = 'en-GB-LD';
        $this->assertSame($expected, $agent->dialect);
        $this->assertStringContainsString(\App\Support\EnglishAccents::ALL[$expected]['prompt'], AgentInstructions::speech($agent));

        // An unknown accent falls back to general American English.
        $this->actingAs($owner)->postJsonLocalizedRoute('voice-agents.store', [
            'business_name' => 'Second Shop', 'name' => 'Emma', 'business_type' => 'retail', 'jobs' => ['answer'], 'language' => 'en', 'dialect' => 'xx',
        ])->assertOk();
        $this->assertSame('en-US', Agent::where('user_id', $owner->id)->latest('id')->first()->dialect);
    }

    public function test_the_english_agents_page_has_english_accents_only_and_both_pages_offer_every_dialect(): void
    {
        app()->setLocale('en');
        $en = (string) $this->withoutLocale()->withSession(['locale' => 'en'])->get($this->localizedRoute('agents-landing'))->assertOk()->getContent();
        $this->assertStringNotContainsString('images/speakers/ar-', $en);
        // The real calls on the English page are English calls only.
        $call = substr($en, strpos($en, 'data-agl-real'), 6000);
        $this->assertDoesNotMatchRegularExpression('/\p{Arabic}/u', strip_tags($call), 'the English call shows Arabic');
        $this->assertStringContainsString(__('agents_landing.real_calls.gb.label'), $en);
        // Every accent is one tap away: each region's tile opens the full list (no separate button).
        $this->assertStringContainsString('data-agl-region=', $en);
        $this->assertStringNotContainsString('agl-speaks-more', $en);
        $this->assertStringContainsString((string) count(\App\Support\EnglishAccents::ALL), strip_tags(substr($en, strpos($en, 'agl-speaks-title'), 1500)));

        app()->setLocale('ar');
        $ar = (string) $this->withoutLocale()->withSession(['locale' => 'ar'])->get($this->localizedRoute('agents-landing'))->assertOk()->getContent();
        $this->assertStringContainsString('data-agl-region=', $ar);
        $this->assertStringContainsString((string) count(\App\Support\ArabicDialects::ALL), strip_tags(substr($ar, strpos($ar, 'agl-speaks-title'), 1500)));
        // The page has one orb at most, in the call; the final call to action shows Listen's mark.
        $this->assertLessThanOrEqual(1, substr_count($ar, 'data-orb="'), 'more than one orb on the page');
    }

    public function test_the_link_page_footer_only_names_listen(): void
    {
        $agent = $this->agent(User::factory()->create());
        app()->setLocale('ar');
        $html = (string) $this->get('/talk/'.$agent->mainWidget()->slug)->assertOk()->getContent();
        // The page opens the website button's own call card, whose footer only says "Powered by Listen"
        // (the page's description for search engines may still say it is an AI assistant).
        $this->assertSame(1, preg_match("#window\.LISTEN_TALK = JSON\.parse\('(.*?)'\);#s", $html, $m), 'the link page has no call card');
        $card = json_decode((string) json_decode('"'.$m[1].'"'), true)['card'];
        $this->assertContains($card['text']['powered'], [trans('agents.powered', [], 'ar'), trans('agents.powered', [], 'en')]);
        $this->assertStringNotContainsString('<p class="ag-hint">', $html);
        // Nothing of the old page's own card is left beside it: no second orb, footer or microphone note.
        foreach (['data-orb="', 'ag-talkpage-foot', 'ag-talkpage-mic', 'ag-talkpage-card'] as $old) {
            $this->assertStringNotContainsString($old, $html);
        }
    }
}
