<?php

namespace Tests\Feature\Agents;

use App\Models\Agent;
use App\Models\Plan;
use App\Models\User;
use App\Models\Voice;
use App\Support\Agents\AgentInstructions;
use App\Support\Agents\HouseAgent;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Tests\TestCase;

/**
 * An agent's name and words follow its voice: a speaker of the other gender brings their name and
 * words written for them; a name of the owner's own that still fits stays; Listen's own agent gets
 * its own words for the voice, and running its command again keeps what the owner chose.
 */
class AgentVoiceNameTest extends TestCase
{
    use RefreshDatabase;

    private const SUMAYA = 'ar-Gemini-HD-Callirrhoe';

    private const FATIMA = 'ar-Gemini-HD-Achernar';

    private const WALEED = 'ar-Gemini-HD-Charon';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.gemini_tts.api_key' => 'test-key']);
        Plan::factory()->free()->create(['agents_enabled' => true]);
        foreach ([[self::SUMAYA, 'Sumaya', 'female', 'Callirrhoe'], [self::FATIMA, 'Fatima', 'female', 'Achernar'], [self::WALEED, 'Waleed', 'male', 'Charon']] as [$id, $name, $gender, $voice]) {
            Voice::query()->create(['language' => 'ar', 'gender' => $gender, 'tier' => 'hd', 'display_name' => $name, 'dialect' => 'ar-EG',
                'internal_voice_id' => $id, 'provider' => 'gemini', 'provider_voice_id' => $voice, 'is_active' => true]);
        }
    }

    private function agent(User $owner, array $attributes = []): Agent
    {
        return Agent::create(array_merge([
            'user_id' => $owner->id, 'name' => 'سارة', 'business_name' => 'عيادة البسمة', 'business_type' => 'healthcare',
            'jobs' => ['answer'], 'language' => 'ar', 'dialect' => 'ar-EG', 'status' => Agent::READY,
            'speaker_id' => self::SUMAYA, 'live_voice' => 'Callirrhoe',
            'first_message' => 'أهلًا بيك في عيادة البسمة، معاك سارة. أقدر أساعدك إزاي؟',
            'script' => ['identity' => 'سارة مساعدة العيادة الصوتية: ودودة وهادئة.', 'never' => ['لا تعطي نصيحة طبية.'], 'steps' => ['اسأل عن الموعد المناسب.'], 'handoff' => 'عند طلب الطبيب.'],
            'settings' => ['texts_gender' => 'female'],
        ], $attributes));
    }

    private function patchAgent(Agent $agent, array $data): TestResponse
    {
        return $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class, LaravelLocalizationViewPath::class, ValidateCsrfToken::class])
            ->patchJson($this->localizedRoute('voice-agents.update', ['agent' => $agent]), $data);
    }

    private function modelAnswers(array $answer): void
    {
        Http::fake(['*generateContent' => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode($answer, JSON_UNESCAPED_UNICODE)]]]]]])]);
    }

    public function test_a_voice_of_the_other_gender_brings_its_name_and_words_for_him(): void
    {
        $owner = User::factory()->create();
        $agent = $this->agent($owner);
        $this->modelAnswers([
            'first_message' => 'أهلًا بيك في عيادة البسمة، معاك وليد. أقدر أساعدك إزاي؟',
            'identity' => 'وليد مساعد العيادة الصوتي: ودود وهادئ.',
            'never' => ['لا تعطي نصيحة طبية.'], 'steps' => ['اسأل عن الموعد المناسب.'], 'handoff' => 'عند طلب الطبيب.',
        ]);

        $this->actingAs($owner)->patchAgent($agent, ['speaker_id' => self::WALEED])->assertOk()
            ->assertJsonPath('agent.name', 'وليد')
            ->assertJsonPath('agent.script.identity', 'وليد مساعد العيادة الصوتي: ودود وهادئ.');

        $agent->refresh();
        $this->assertSame('Charon', $agent->live_voice);
        $this->assertSame('وليد', $agent->name);
        $this->assertStringContainsString('معاك وليد', $agent->first_message);
        $this->assertSame('male', $agent->setting('texts_gender'));
        // Asked for a man's words with the new name, the rest kept as it was, thinking little (the owner waits).
        Http::assertSent(fn (HttpRequest $r) => str_contains($r['contents'][0]['parts'][0]['text'], "a man's voice, and its name is وليد (it was سارة)")
            && str_contains($r['contents'][0]['parts'][0]['text'], 'ودودة وهادئة')
            && ($r['generationConfig']['thinkingConfig']['thinkingLevel'] ?? null) === 'low');
        // The call is told its voice is a man's.
        $this->assertStringContainsString("You speak with a man's voice", AgentInstructions::build($agent));
    }

    public function test_a_model_that_refuses_the_thinking_level_is_asked_again_without_it(): void
    {
        Http::fakeSequence('*generateContent')
            ->push(['error' => ['code' => 400, 'message' => 'Thinking level LOW is not supported for this model.', 'status' => 'INVALID_ARGUMENT']], 400)
            ->push(['candidates' => [['content' => ['parts' => [['text' => json_encode([
                'first_message' => 'أهلًا بيك في عيادة البسمة، معاك وليد. أقدر أساعدك إزاي؟',
                'identity' => 'وليد مساعد العيادة الصوتي: ودود وهادئ.',
                'never' => ['لا تعطي نصيحة طبية.'], 'steps' => ['اسأل عن الموعد المناسب.'], 'handoff' => 'عند طلب الطبيب.',
            ], JSON_UNESCAPED_UNICODE)]]]]]]);
        $owner = User::factory()->create();
        $agent = $this->agent($owner);

        $this->actingAs($owner)->patchAgent($agent, ['speaker_id' => self::WALEED])->assertOk()
            ->assertJsonPath('agent.script.identity', 'وليد مساعد العيادة الصوتي: ودود وهادئ.');

        Http::assertSentCount(2);
        Http::assertSent(fn (HttpRequest $r) => ! isset($r['generationConfig']['thinkingConfig']));
    }

    public function test_without_the_text_model_the_new_name_still_replaces_the_old_one(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'unavailable']], 503)]);
        $owner = User::factory()->create();
        $agent = $this->agent($owner);

        $this->actingAs($owner)->patchAgent($agent, ['speaker_id' => self::WALEED])->assertOk();

        $agent->refresh();
        $this->assertSame('وليد', $agent->name);
        $this->assertSame('أهلًا بيك في عيادة البسمة، معاك وليد. أقدر أساعدك إزاي؟', $agent->first_message);
        $this->assertStringStartsWith('وليد مساعدة', $agent->script['identity']);
        $this->assertSame(['لا تعطي نصيحة طبية.'], $agent->script['never']);
    }

    public function test_a_name_of_the_owners_own_stays_with_a_voice_of_the_same_gender(): void
    {
        Http::fake();
        $owner = User::factory()->create();
        $agent = $this->agent($owner, ['name' => 'ليلى']);

        $this->actingAs($owner)->patchAgent($agent, ['speaker_id' => self::FATIMA])->assertOk()->assertJsonPath('agent.name', 'ليلى');

        $this->assertSame('Achernar', $agent->fresh()->live_voice);
        Http::assertNothingSent();
    }

    public function test_an_agent_called_by_its_voices_name_takes_the_new_speakers_name(): void
    {
        Http::fake();
        $owner = User::factory()->create();
        $agent = $this->agent($owner, ['name' => 'سمية', 'first_message' => 'أهلًا، معاك سمية من عيادة البسمة.']);

        $this->actingAs($owner)->patchAgent($agent, ['speaker_id' => self::FATIMA])->assertOk()->assertJsonPath('agent.name', 'فاطمة');

        $this->assertSame('أهلًا، معاك فاطمة من عيادة البسمة.', $agent->fresh()->first_message);
        Http::assertNothingSent();
    }

    public function test_a_call_button_titled_with_the_old_name_shows_the_new_one(): void
    {
        Http::fake();
        $owner = User::factory()->create();
        $agent = $this->agent($owner, ['name' => 'سمية', 'first_message' => 'أهلًا، معاك سمية من عيادة البسمة.']);
        $widget = $agent->mainWidget();
        // The owner typed the agent's name as the card's title (Design tab).
        $widget->forceFill(['appearance' => ['title' => 'سمية'] + (array) $widget->appearance])->save();

        $this->actingAs($owner)->patchAgent($agent, ['speaker_id' => self::FATIMA])->assertOk()->assertJsonPath('agent.name', 'فاطمة');
        $this->assertSame('فاطمة', $widget->fresh()->appearance['title']);

        // A title of the owner's own (not the old name) stays as it is.
        $widget->forceFill(['appearance' => ['title' => 'عيادة البسمة'] + (array) $widget->fresh()->appearance])->save();
        $this->actingAs($owner)->patchAgent($agent->fresh(), ['name' => 'منى'])->assertOk();
        $this->assertSame('عيادة البسمة', $widget->fresh()->appearance['title']);
    }

    public function test_a_name_sent_with_the_voice_is_the_one_kept(): void
    {
        Http::fake();
        $owner = User::factory()->create();
        $agent = $this->agent($owner);

        $this->actingAs($owner)->patchAgent($agent, ['speaker_id' => self::WALEED, 'name' => 'سارة'])->assertOk()->assertJsonPath('agent.name', 'سارة');
        Http::assertNothingSent();
    }

    public function test_the_first_setup_writes_for_the_voice_and_a_voice_of_the_other_gender_rewrites_it(): void
    {
        $owner = User::factory()->create();
        Http::fake(['*generateContent' => Http::sequence()
            ->push(['candidates' => [['content' => ['parts' => [['text' => json_encode([
                'first_message' => 'أهلًا بيك، معاك سارة من عيادة البسمة.', 'identity' => 'سارة مساعدة العيادة.', 'never' => ['لا تخترع أسعارًا.'],
                'steps' => ['رحّب بالمتصل.'], 'handoff' => 'عند طلب شخص.', 'knowledge' => 'الكشف 300 جنيه.',
                'questions' => [['question' => 'خصم؟', 'options' => ['لا', 'نعم'], 'recommended' => 0], ['question' => 'مدير؟', 'options' => ['رقم', 'لا'], 'recommended' => 0]],
                'assistant_gender' => 'female',
            ], JSON_UNESCAPED_UNICODE)]]]]]])
            ->push(['candidates' => [['content' => ['parts' => [['text' => json_encode([
                'first_message' => 'أهلًا بيك، معاك وليد من عيادة البسمة.', 'identity' => 'وليد مساعد العيادة.', 'never' => ['لا تخترع أسعارًا.'],
                'steps' => ['رحّب بالمتصل.'], 'handoff' => 'عند طلب شخص.',
            ], JSON_UNESCAPED_UNICODE)]]]]]]),
        ]);

        $made = $this->actingAs($owner)->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class, LaravelLocalizationViewPath::class, ValidateCsrfToken::class])
            ->postJson($this->localizedRoute('voice-agents.store'), [
                'business_name' => 'عيادة البسمة', 'name' => 'سارة', 'business_type' => 'healthcare', 'language' => 'ar', 'dialect' => 'ar-EG',
            ])->assertOk();
        $agent = Agent::where('public_id', $made->json('agent.id'))->sole();
        $this->assertSame('female', $agent->setting('texts_gender'));

        // The owner picks a man's voice on the next screen: his name, and words for him.
        $this->actingAs($owner)->patchAgent($agent, ['speaker_id' => self::WALEED])->assertOk()->assertJsonPath('agent.name', 'وليد');
        $agent->refresh();
        $this->assertSame('وليد مساعد العيادة.', $agent->script['identity']);
        $this->assertSame('male', $agent->setting('texts_gender'));
    }

    public function test_writing_again_for_an_agent_with_a_voice_writes_for_that_voice(): void
    {
        $owner = User::factory()->create();
        $agent = $this->agent($owner, ['speaker_id' => self::WALEED, 'live_voice' => 'Charon', 'name' => 'وليد']);
        $this->modelAnswers(['first_message' => 'أهلًا، معاك وليد.', 'identity' => 'وليد مساعد.', 'never' => [], 'steps' => [], 'handoff' => '', 'knowledge' => '', 'questions' => [], 'assistant_gender' => 'male']);

        $this->actingAs($owner)->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class, LaravelLocalizationViewPath::class, ValidateCsrfToken::class])
            ->postJson($this->localizedRoute('voice-agents.write', ['agent' => $agent]), [
                'business_name' => 'عيادة البسمة', 'name' => 'وليد', 'business_type' => 'healthcare', 'language' => 'ar', 'dialect' => 'ar-EG',
            ])->assertOk();

        Http::assertSent(fn (HttpRequest $r) => str_contains($r['contents'][0]['parts'][0]['text'], "The assistant is: a man (its voice is a man's)"));
        $this->assertSame('male', $agent->fresh()->setting('texts_gender'));
    }

    public function test_listens_own_agent_takes_a_mans_name_and_words_and_its_command_keeps_them(): void
    {
        Http::fake();
        $admin = User::factory()->create(['is_admin' => true]);
        $this->artisan('agents:house')->assertSuccessful();
        $sara = Agent::where('user_id', $admin->id)->where('language', 'ar')->sole();
        $this->assertSame('سارة', $sara->name);

        // The owner picks Waleed's voice: Listen's own words for a man, with his name.
        $this->actingAs($admin)->patchAgent($sara, ['speaker_id' => self::WALEED])->assertOk()->assertJsonPath('agent.name', 'وليد');
        $agent = $sara->fresh();
        $this->assertSame('أهلًا بيك في إسمع، معاك وليد. تحب تعرف إيه عن الأصوات أو الوكلاء أو الباقات؟', $agent->first_message);
        $this->assertSame('وليد مساعد إسمع الصوتي: ودود وواضح ومختصر، ويتكلم مصري.', $agent->script['identity']);
        Http::assertNothingSent();

        // Running the command again (after a price change) keeps the voice and the name.
        $this->artisan('agents:house')->assertSuccessful();
        $agent = $agent->fresh();
        $this->assertSame(self::WALEED, $agent->speaker_id);
        $this->assertSame('وليد', $agent->name);

        // Words the owner wrote stay too.
        $agent->forceFill(['first_message' => 'أهلًا، معاك وليد من إسمع.'])->save();
        $this->artisan('agents:house')->assertSuccessful();
        $this->assertSame('أهلًا، معاك وليد من إسمع.', $agent->fresh()->first_message);
    }

    public function test_the_command_gives_a_voice_changed_before_its_own_name_and_words(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->artisan('agents:house')->assertSuccessful();
        // As on live before this: the voice changed to a man's, the name and words still Sara's.
        $sara = Agent::where('user_id', $admin->id)->where('language', 'ar')->sole();
        $settings = $sara->settings;
        unset($settings['house_texts'], $settings['texts_gender']);
        $sara->forceFill(['speaker_id' => self::WALEED, 'live_voice' => 'Charon', 'settings' => $settings])->save();
        $this->assertTrue(HouseAgent::textsUntouched($sara->fresh()));

        $this->artisan('agents:house')->assertSuccessful();

        $agent = $sara->fresh();
        $this->assertSame('وليد', $agent->name);
        $this->assertSame('Charon', $agent->live_voice);
        $this->assertStringStartsWith('وليد مساعد إسمع الصوتي', $agent->script['identity']);
    }

    public function test_the_studio_menu_line_leaves_out_dubbing(): void
    {
        $this->assertSame('توليد الأصوات والاستنساخ', __('agents.switch_studio_text', [], 'ar'));
        $this->assertSame('Voices and cloning', __('agents.switch_studio_text', [], 'en'));
    }
}
