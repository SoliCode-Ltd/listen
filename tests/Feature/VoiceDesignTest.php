<?php

namespace Tests\Feature;

use App\Filament\Pages\VoiceDesignSettings;
use App\Jobs\GenerateAudioJob;
use App\Models\AudioGeneration;
use App\Models\DemoVoiceGeneration;
use App\Models\DesignedVoice;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use App\Services\VoiceDesignService;
use App\Support\PlanPricingLines;
use App\Support\VoiceCloningAccess;
use App\Support\VoiceDesign;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Voice Design: who sees it, a design's takes and what they cost, keeping
 * one, the plan's limits, speaking with a kept voice, the demo's one take,
 * clearing unkept takes, and the admin's switches. The speech service is
 * always faked.
 */
class VoiceDesignTest extends TestCase
{
    use RefreshDatabase;

    private const LINE = 'Ahoy there, landlubber! Mind the deck tonight, or you will be swimming with the fishes.';

    private Plan $designPlan;

    private Plan $plainPlan;

    private Plan $freePlan;

    /** @var list<array{method:string, path:string, body:array}> */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');
        config(['services.gemini_tts.api_key' => 'test-key', 'services.gemini_tts.voice_retry_pause' => false]);

        $this->freePlan = Plan::factory()->free()->create(['voice_tier' => 'standard', 'monthly_chars' => 10000, 'monthly_requests' => 0]);
        $this->designPlan = Plan::factory()->create([
            'slug' => 'creator', 'name' => 'Creator', 'price_cents' => 2200, 'voice_tier' => 'hd',
            'monthly_chars' => 500000, 'monthly_requests' => 0, 'max_chars_per_request' => 5000, 'allow_overage' => false,
            'voice_design_enabled' => true, 'voice_design_max_voices' => 2, 'voice_design_monthly_voices' => 0,
        ]);
        $this->plainPlan = Plan::factory()->create([
            'slug' => 'starter', 'name' => 'Starter', 'price_cents' => 900, 'voice_tier' => 'hd',
            'monthly_chars' => 500000, 'monthly_requests' => 0, 'max_chars_per_request' => 5000, 'allow_overage' => false,
        ]);
        $this->openTo(VoiceDesign::AUDIENCE_EVERYONE);
    }

    /* ------------------------------------------------------------ */
    /*  Who sees it                                                  */
    /* ------------------------------------------------------------ */

    public function test_a_plan_with_voice_design_gets_the_studio_in_its_own_section(): void
    {
        $member = $this->member($this->designPlan);

        $page = $this->actingAs($member)->getLocalizedRoute('dashboard');

        $page->assertOk()
            ->assertSee('id="voiceDesignSection"', false)
            ->assertSee('href="#voiceDesignSection"', false)
            ->assertSee(__('voice_design.prompt_label'))
            ->assertSee(__('voice_design.design_button'))
            ->assertSee('data-vd-submit', false)
            ->assertDontSee('data-vd-upgrade', false);
    }

    public function test_a_plan_without_it_tries_the_tools_and_upgrades_to_the_cheapest_plan_that_has_it(): void
    {
        $page = $this->actingAs($this->member($this->plainPlan))->getLocalizedRoute('dashboard');

        // The form to try and the voices to hear, with the way to a plan that designs in place of the button.
        $page->assertOk()
            ->assertSee('id="voiceDesignSection"', false)
            ->assertSee('data-vd-form', false)
            ->assertSee('data-vd-showcase', false)
            ->assertSee(__('voice_design.write_for_me'))
            ->assertSee(__('voice_design.locked_title', ['plan' => 'Creator']))
            ->assertSee(__('voice_design.upgrade_cta', ['plan' => 'Creator']))
            ->assertSee(route('billing.page', ['plan' => 'creator']), false)
            ->assertDontSee('data-vd-submit', false)
            ->assertDontSee('data-vd-library', false);

        $this->actingAs($this->member($this->plainPlan))
            ->postJsonLocalizedRoute('voice-design.design', $this->designInput())
            ->assertForbidden();
    }

    public function test_it_reaches_members_only_once_the_owner_turns_it_on_for_a_plan(): void
    {
        // Every plan starts without it: "Everyone" still means the admin email alone.
        $this->designPlan->forceFill(['voice_design_enabled' => false])->save();
        $this->assertFalse(VoiceDesign::anyPlanIncludes());
        $member = $this->member($this->plainPlan);
        $admin = $this->member($this->plainPlan, VoiceCloningAccess::PREVIEW_EMAIL);

        $this->actingAs($member)->getLocalizedRoute('dashboard')->assertOk()->assertDontSee('id="voiceDesignSection"', false);
        $this->actingAs($this->member($this->freePlan))->getLocalizedRoute('demo.page')->assertOk()->assertDontSee('data-vd-config', false);
        $this->actingAs($member)->postJsonLocalizedRoute('voice-design.enhance', ['idea' => 'a pirate'])->assertNotFound();
        $this->actingAs($admin)->getLocalizedRoute('dashboard')->assertSee('data-vd-submit', false);

        // Turned on for a plan that is not on sale: its members design, nobody else is asked to upgrade.
        $this->designPlan->forceFill(['voice_design_enabled' => true, 'is_active' => false])->save();
        $this->assertFalse(VoiceDesign::showsUpgrade($member, $this->plainPlan));
        $this->actingAs($member)->getLocalizedRoute('dashboard')->assertDontSee('id="voiceDesignSection"', false);
        $this->actingAs($this->member($this->designPlan))->getLocalizedRoute('dashboard')->assertSee('data-vd-submit', false);

        // On sale: the others try it, with the way to it.
        $this->designPlan->forceFill(['is_active' => true])->save();
        $this->assertTrue(VoiceDesign::showsUpgrade($member, $this->plainPlan));
        $this->actingAs($member)->getLocalizedRoute('dashboard')->assertSee('data-vd-upgrade', false);
    }

    public function test_the_preview_shows_it_to_the_admin_email_only_and_off_hides_it_from_everyone(): void
    {
        $this->openTo(VoiceDesign::AUDIENCE_PREVIEW);
        $admin = $this->member($this->plainPlan, VoiceCloningAccess::PREVIEW_EMAIL);
        $other = $this->member($this->designPlan);

        $this->actingAs($admin)->getLocalizedRoute('dashboard')
            ->assertSee('data-vd-form', false);
        $this->actingAs($other)->getLocalizedRoute('dashboard')
            ->assertDontSee('id="voiceDesignSection"', false);
        $this->actingAs($other)->postJsonLocalizedRoute('voice-design.design', $this->designInput())->assertNotFound();

        $this->openTo(VoiceDesign::AUDIENCE_OFF);
        $this->actingAs($admin)->getLocalizedRoute('dashboard')
            ->assertDontSee('id="voiceDesignSection"', false);
    }

    public function test_the_section_reads_in_arabic(): void
    {
        app()->setLocale('ar');
        $page = $this->actingAs($this->member($this->designPlan))->getLocalizedRoute('dashboard');

        $page->assertOk()->assertSee('تصميم الصوت')->assertSee('صِف الصوت');
        app()->setLocale('en');
    }

    /* ------------------------------------------------------------ */
    /*  A design                                                     */
    /* ------------------------------------------------------------ */

    public function test_a_design_makes_three_takes_that_speak_the_same_line_and_charges_each(): void
    {
        $member = $this->member($this->designPlan);
        $this->fakeEngine();

        $response = $this->actingAs($member)->postJsonLocalizedRoute('voice-design.design', $this->designInput(['projection' => 90]));

        $response->assertOk()->assertJson(['ok' => true, 'line' => self::LINE])->assertJsonCount(3, 'takes');
        $this->assertSame(3, DesignedVoice::query()->drafts()->where('user_id', $member->id)->count());

        $creates = $this->callsTo('POST', '/voices');
        $this->assertCount(3, $creates);
        $this->assertSame('prompted', $creates[0]['body']['voice']['type']);
        $this->assertTrue($creates[0]['body']['store']);
        $this->assertStringContainsString('A gruff old sea captain', $creates[0]['body']['voice']['prompted']['input']);
        // Projection at 90: the takes are told to project.
        $this->assertStringContainsString('Projects powerfully', $creates[0]['body']['voice']['prompted']['input']);
        $this->assertSame('en-US', $creates[0]['body']['voice']['language_code']);
        // Each take speaks the line, with its own voice.
        $spoken = $this->callsTo('POST', '/interactions');
        $this->assertCount(3, $spoken);
        $this->assertSame(['voice_t1', 'voice_t2', 'voice_t3'], collect($spoken)->pluck('body.generation_config.speech_config.0.voice')->sort()->values()->all());
        $this->assertSame(self::LINE, $spoken[0]['body']['input'][0]['content'][0]['text']);

        $this->assertSame(VoiceDesign::estimate(self::LINE, 3)['credits'], $this->creditsUsed($member));
        $this->assertSame(1, (int) UsageCounter::query()->where('user_id', $member->id)->value('total_requests'));
    }

    public function test_each_take_says_how_it_was_made_from_the_description(): void
    {
        $member = $this->member($this->designPlan);
        $this->fakeEngine();

        $takes = $this->actingAs($member)->postJsonLocalizedRoute('voice-design.design', $this->designInput(['variety' => 50]))->assertOk()->json('takes');

        // The first follows the description as written (the one the page recommends); the others lean a way of their own.
        $this->assertSame(
            [1 => 'Closest to your description', 2 => 'A warmer, deeper variation', 3 => 'A brighter, crisper variation'],
            collect($takes)->pluck('note', 'take')->all(),
        );
        $this->assertSame(['closest', 'bolder', 'freer'], [VoiceDesign::takeLean(90, 1), VoiceDesign::takeLean(90, 2), VoiceDesign::takeLean(90, 3)]);
        // Close to the words: the takes after the first are more takes of the same description.
        $this->assertSame(['closest', 'same', 'same'], [VoiceDesign::takeLean(10, 1), VoiceDesign::takeLean(10, 2), VoiceDesign::takeLean(10, 3)]);
    }

    public function test_a_take_that_never_comes_is_not_charged(): void
    {
        $member = $this->member($this->designPlan);
        $this->fakeEngine(['create_fails' => [2]]);

        $this->actingAs($member)->postJsonLocalizedRoute('voice-design.design', $this->designInput())
            ->assertOk()->assertJsonCount(2, 'takes');

        $this->assertSame(VoiceDesign::estimate(self::LINE, 2)['credits'], $this->creditsUsed($member));
    }

    public function test_nothing_is_charged_when_no_take_comes(): void
    {
        $member = $this->member($this->designPlan);
        $this->fakeEngine(['create_fails' => [1, 2, 3]]);

        $this->actingAs($member)->postJsonLocalizedRoute('voice-design.design', $this->designInput())
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'message' => __('voice_design.errors.our_side')]);

        $this->assertSame(0, $this->creditsUsed($member));
        $this->assertSame(0, DesignedVoice::query()->count());
    }

    public function test_the_members_own_line_is_spoken_without_writing_one(): void
    {
        $member = $this->member($this->designPlan);
        $this->fakeEngine();
        $line = 'Every take of this voice should read exactly these words, nothing more.';

        $this->actingAs($member)->postJsonLocalizedRoute('voice-design.design', $this->designInput(['auto_line' => false, 'line' => $line]))
            ->assertOk()->assertJson(['line' => $line]);

        $this->assertSame([], $this->callsTo('POST', ':generateContent'));
        $this->assertSame($line, $this->callsTo('POST', '/interactions')[0]['body']['input'][0]['content'][0]['text']);
    }

    public function test_a_line_is_needed_when_the_member_writes_it(): void
    {
        $this->actingAs($this->member($this->designPlan))
            ->postJsonLocalizedRoute('voice-design.design', $this->designInput(['auto_line' => false]))
            ->assertStatus(422)->assertJsonValidationErrors('line');
    }

    public function test_a_new_design_clears_the_takes_that_were_not_kept(): void
    {
        $member = $this->member($this->designPlan);
        $this->fakeEngine();
        $this->actingAs($member)->postJsonLocalizedRoute('voice-design.design', $this->designInput())->assertOk();
        $firstBatch = DesignedVoice::query()->value('batch');

        $this->actingAs($member)->postJsonLocalizedRoute('voice-design.design', $this->designInput())->assertOk();

        $this->assertSame(0, DesignedVoice::query()->where('batch', $firstBatch)->count());
        $this->assertCount(3, $this->callsTo('DELETE', '/voices/'));
    }

    /* ------------------------------------------------------------ */
    /*  Keeping one                                                  */
    /* ------------------------------------------------------------ */

    public function test_keeping_a_take_names_it_and_deletes_the_other_takes(): void
    {
        $member = $this->member($this->designPlan);
        $this->fakeEngine();
        $takes = $this->actingAs($member)->postJsonLocalizedRoute('voice-design.design', $this->designInput())->json('takes');

        $response = $this->actingAs($member)->postJsonLocalizedRoute('voice-design.keep', ['name' => 'Captain Saltbeard'], ['id' => $takes[1]['id']]);

        $response->assertOk()->assertJson(['ok' => true, 'voice' => ['name' => 'Captain Saltbeard', 'key' => 'designed_'.$takes[1]['id']]]);
        $kept = DesignedVoice::query()->findOrFail($takes[1]['id']);
        $this->assertTrue($kept->isSaved());
        $this->assertNotNull($kept->kept_at);
        $this->assertSame(1, DesignedVoice::query()->count());
        $this->assertCount(2, $this->callsTo('DELETE', '/voices/'));
        // It joins the studio's voice list.
        $this->actingAs($member)->getLocalizedRoute('dashboard')->assertSee('designed_'.$kept->id, false);
    }

    public function test_a_kept_voice_shows_in_my_voices_like_the_other_speakers(): void
    {
        $member = $this->member($this->designPlan);
        $voice = $this->savedVoice($member);

        $listed = $this->actingAs($member)->getLocalizedRoute('dashboard')->viewData('designedVoices');

        $this->assertCount(1, $listed);
        $this->assertSame('designed_'.$voice->id, $listed[0]['key']);
        $this->assertSame('Captain', $listed[0]['name']);
        // Its take to play, its face and its line under the name.
        $this->assertSame(\App\Support\AudioUrl::fromPath($voice->preview_audio_path), $listed[0]['preview']);
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $listed[0]['avatar']);
        $this->assertStringContainsString('<circle', base64_decode(substr($listed[0]['avatar'], strlen('data:image/svg+xml;base64,'))));
        $this->assertSame(__('ui.dashboard.library.speaks', ['language' => __('voice_design.language_en')]), $listed[0]['tag']);
    }

    public function test_a_full_library_cannot_keep_or_design_another(): void
    {
        $member = $this->member($this->designPlan);
        $this->savedVoice($member);
        $this->savedVoice($member);
        $this->fakeEngine();

        $this->actingAs($member)->postJsonLocalizedRoute('voice-design.design', $this->designInput())
            ->assertStatus(422)->assertJson(['message' => __('voice_design.errors.library_full', ['max' => 2])]);
        $this->assertSame([], $this->calls);
    }

    public function test_the_plans_new_voices_a_month_count_deleted_ones_too(): void
    {
        $this->designPlan->forceFill(['voice_design_max_voices' => 10, 'voice_design_monthly_voices' => 1])->save();
        $member = $this->member($this->designPlan);
        $this->savedVoice($member)->delete();
        $this->fakeEngine();

        $this->actingAs($member)->postJsonLocalizedRoute('voice-design.design', $this->designInput())
            ->assertStatus(422)->assertJson(['message' => trans_choice('voice_design.errors.month_used', 1, ['count' => 1])]);
    }

    public function test_the_page_says_what_is_left_this_month_and_stops_designing_once_it_is_used(): void
    {
        $this->designPlan->forceFill(['voice_design_max_voices' => 2, 'voice_design_monthly_voices' => 2])->save();
        $member = $this->member($this->designPlan);
        $this->savedVoice($member, DesignedVoice::SAVED, 'voice_one');
        app()->setLocale('en');

        $page = $this->actingAs($member)->getLocalizedRoute('dashboard')->assertOk();
        $page->assertSee('1 of 2 new voices left this month', false);
        $this->assertMatchesRegularExpression('/data-vd-limit role="status"\s+hidden/', $page->getContent());
        $this->assertDoesNotMatchRegularExpression('/data-vd-submit\s+hidden disabled/', $page->getContent());

        // A second one kept, then deleted: room in the library, none in the month.
        $this->savedVoice($member, DesignedVoice::SAVED, 'voice_two')->delete();
        $page = $this->actingAs($member)->getLocalizedRoute('dashboard')->assertOk();
        $page->assertSee('0 of 2 new voices left this month', false);
        $page->assertSee(__('voice_design.limit_month_title'), false);
        $this->assertDoesNotMatchRegularExpression('/data-vd-limit role="status"\s+hidden/', $page->getContent());
        $this->assertMatchesRegularExpression('/data-vd-submit\s+hidden disabled/', $page->getContent());
        // The library's own count is gone: the pill above says what is left.
        $page->assertDontSee('data-vd-library-count', false);
    }

    public function test_deleting_on_a_plan_with_a_monthly_number_says_it_does_not_give_one_back(): void
    {
        $this->designPlan->forceFill(['voice_design_monthly_voices' => 2])->save();
        app()->setLocale('en');
        $html = $this->actingAs($this->member($this->designPlan))->getLocalizedRoute('dashboard')->assertOk()->getContent();

        $this->assertStringContainsString(json_encode(__('voice_design.delete_month_note')), $html);
        $this->assertStringContainsString("t.delete_confirm + (Number(studio.monthly || 0) > 0 ? '\\n\\n' + t.delete_month_note : '')", file_get_contents(public_path('js/voice-design.js')));
    }

    public function test_the_public_pages_play_the_designed_voices_in_their_own_language(): void
    {
        app()->setLocale('en');
        $samples = $this->getLocalizedRoute('samples')->assertOk();
        $samples->assertSee('id="designed-voices"', false)->assertSee(__('voice_design.examples.evil_ogre.name'), false);
        $samples->assertSee('audio/voice-design/examples/evil_ogre.mp3', false);
        // Arabic voices on the Arabic page only.
        $samples->assertDontSee('audio/voice-design/examples/cairo_grandma.mp3', false);
        // The homepage tour: the Evil Ogre, as a designed voice.
        $home = $this->getLocalizedRoute('home')->assertOk();
        $home->assertSee(__('ui.landing.tour_designed_title'), false);
        $this->assertMatchesRegularExpression('/data-tour-item="4"[^>]*data-src="[^"]*evil_ogre\.mp3/', $home->getContent());

        app()->setLocale('ar');
        $this->getLocalizedRoute('samples')->assertOk()
            ->assertSee('audio/voice-design/examples/cairo_grandma.mp3', false)
            ->assertDontSee('audio/voice-design/examples/evil_ogre.mp3', false);
    }

    public function test_the_public_pages_leave_designed_voices_out_until_voice_design_opens(): void
    {
        app()->setLocale('en');
        $this->openTo(VoiceDesign::AUDIENCE_PREVIEW);
        $this->getLocalizedRoute('samples')->assertOk()->assertDontSee('id="designed-voices"', false);
        $this->getLocalizedRoute('home')->assertOk()->assertDontSee('evil_ogre.mp3', false);

        // Open to everyone, but no plan has it yet: still out.
        $this->openTo(VoiceDesign::AUDIENCE_EVERYONE);
        $this->designPlan->forceFill(['voice_design_enabled' => false])->save();
        $this->getLocalizedRoute('samples')->assertOk()->assertDontSee('id="designed-voices"', false);

        // The admin email sees them first.
        $admin = User::factory()->create(['email' => \App\Support\VoiceCloningAccess::PREVIEW_EMAIL]);
        $this->actingAs($admin)->getLocalizedRoute('samples')->assertOk()->assertSee('id="designed-voices"', false);
    }

    public function test_keeping_a_voice_returns_the_months_new_voices_so_far(): void
    {
        $this->designPlan->forceFill(['voice_design_max_voices' => 5, 'voice_design_monthly_voices' => 3])->save();
        $member = $this->member($this->designPlan);
        $this->savedVoice($member, DesignedVoice::SAVED, 'voice_one')->delete();
        $take = $this->savedVoice($member, DesignedVoice::DRAFT, 'voice_take');

        $this->actingAs($member)->postJsonLocalizedRoute('voice-design.keep', ['name' => 'Captain Two'], ['id' => $take->id])
            ->assertOk()->assertJson(['kept_month' => 2]);
    }

    public function test_a_member_cannot_keep_or_delete_someone_elses_voice(): void
    {
        $owner = $this->member($this->designPlan);
        $voice = $this->savedVoice($owner, DesignedVoice::DRAFT);
        $other = $this->member($this->designPlan);
        $this->fakeEngine();

        $this->actingAs($other)->postJsonLocalizedRoute('voice-design.keep', ['name' => 'Mine now'], ['id' => $voice->id])->assertNotFound();
        $this->actingAs($other)->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class)
            ->deleteJson($this->localizedRoute('voice-design.destroy', ['id' => $voice->id]))->assertNotFound();
        $this->assertNotNull($voice->fresh());
    }

    public function test_deleting_a_kept_voice_removes_it_there_and_keeps_its_name_for_the_history(): void
    {
        $member = $this->member($this->designPlan);
        $voice = $this->savedVoice($member);
        $this->fakeEngine();

        $this->actingAs($member)->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class)
            ->deleteJson($this->localizedRoute('voice-design.destroy', ['id' => $voice->id]))->assertOk();

        $this->assertSoftDeleted($voice);
        $this->assertCount(1, $this->callsTo('DELETE', '/voices/voice_saved'));
    }

    /* ------------------------------------------------------------ */
    /*  Speaking with a kept voice                                   */
    /* ------------------------------------------------------------ */

    public function test_the_studio_speaks_with_a_kept_voice_on_the_key_that_holds_it(): void
    {
        $member = $this->member($this->designPlan);
        $voice = $this->savedVoice($member);
        $this->fakeEngine();

        $response = $this->actingAs($member)->postJsonLocalizedRoute('dashboard.queue', [
            'content' => 'Welcome aboard, crew. Today we sail for the islands.',
            'target_language' => 'en',
            'voice_key' => $voice->voiceKey(),
            'voice_tier' => 'hd',
        ]);

        $response->assertOk();
        $generation = AudioGeneration::query()->latest('id')->firstOrFail();
        $this->assertSame('designed_'.$voice->id, $generation->voice_name);
        $this->assertSame('done', $generation->status);
        $spoken = $this->callsTo('POST', '/interactions');
        $this->assertSame('voice_saved', $spoken[0]['body']['generation_config']['speech_config'][0]['voice']);
        $this->assertNotNull($voice->fresh()->last_used_at);
    }

    public function test_a_plan_without_voice_design_cannot_speak_with_a_designed_voice(): void
    {
        $member = $this->member($this->plainPlan);
        $voice = $this->savedVoice($member);

        $this->actingAs($member)->postJsonLocalizedRoute('dashboard.queue', [
            'content' => 'Welcome aboard, crew.',
            'target_language' => 'en',
            'voice_key' => $voice->voiceKey(),
            'voice_tier' => 'hd',
        ])->assertStatus(422)->assertJsonValidationErrors('voice_key');
    }

    public function test_a_failed_designed_voice_is_told_as_a_limit_on_our_side(): void
    {
        $member = $this->member($this->designPlan);
        $voice = $this->savedVoice($member);
        app(\App\Services\Tts\GeminiKeyManager::class)->markOutForToday('GEMINI_TTS_API_KEY', VoiceDesignService::MODEL);
        $this->fakeEngine();

        $this->actingAs($member)->postJsonLocalizedRoute('dashboard.queue', [
            'content' => 'Welcome aboard, crew.',
            'target_language' => 'en',
            'voice_key' => $voice->voiceKey(),
            'voice_tier' => 'hd',
        ]);

        $generation = AudioGeneration::query()->latest('id')->firstOrFail();
        $this->assertSame('failed', $generation->status);
        $this->assertSame(\App\Support\GenerationMessage::stored('ui.backend.designed_voices_our_limit'), $generation->error_message);
        $this->assertSame([], $this->callsTo('POST', '/interactions'));
    }

    /* ------------------------------------------------------------ */
    /*  The demo                                                     */
    /* ------------------------------------------------------------ */

    public function test_the_demo_offers_voice_design_beside_text_to_speech(): void
    {
        $page = $this->actingAs($this->member($this->freePlan))->getLocalizedRoute('demo.page');

        $page->assertOk()
            ->assertSee('data-studio-tab="design"', false)
            ->assertSee(__('voice_design.demo_tab'))
            ->assertSee('data-studio-pane="design"', false);
    }

    public function test_the_demo_makes_one_take_paid_from_the_demo_characters(): void
    {
        $member = $this->member($this->freePlan);
        $this->fakeEngine();

        $response = $this->actingAs($member)->postJsonLocalizedRoute('demo.voice-design', $this->designInput());

        $chars = \App\Support\UsageEstimator::estimate(self::LINE)['chars'];
        $response->assertOk()->assertJson(['ok' => true, 'can_keep' => false, 'estimated_chars' => $chars]);
        $this->assertCount(1, $this->callsTo('POST', '/voices'));
        $this->assertCount(1, $this->callsTo('POST', '/interactions'));
        $demo = DemoVoiceGeneration::query()->where('user_id', $member->id)->firstOrFail();
        $this->assertSame($chars, (int) $demo->billable_chars);
        $this->assertSame(__('voice_design.demo_voice_label'), $demo->voice_label);
        $this->assertStringStartsWith('audio/demo/', (string) $demo->audio_path);
        // The take cannot be kept on a free plan: its voice is gone at once, its audio stays in the history.
        $this->assertSame(0, DesignedVoice::withTrashed()->count());
        $this->assertCount(1, $this->callsTo('DELETE', '/voices/voice_t1'));
        Storage::disk('public')->assertExists((string) $demo->audio_path);
        $this->assertStringContainsString('audio/demo/', (string) $response->json('take.url'));
        $this->assertSame(\App\Support\DemoAllowance::chars() - $chars, $response->json('quota.remaining_chars'));
        // The member's plan credits are not touched.
        $this->assertSame(0, $this->creditsUsed($member));
    }

    public function test_the_demo_refuses_a_line_longer_than_the_demo_allows(): void
    {
        $this->actingAs($this->member($this->freePlan))
            ->postJsonLocalizedRoute('demo.voice-design', $this->designInput(['auto_line' => false, 'line' => str_repeat('A long line that runs on. ', 20)]))
            ->assertStatus(422)->assertJson(['ok' => false]);
    }

    /* ------------------------------------------------------------ */
    /*  Clearing unkept takes                                        */
    /* ------------------------------------------------------------ */

    public function test_prune_deletes_old_unkept_takes_and_lost_ones_but_not_new_ones(): void
    {
        $member = $this->member($this->designPlan);
        $old = $this->savedVoice($member, DesignedVoice::DRAFT, 'voice_old');
        $old->forceFill(['created_at' => now()->subHours(3)])->save();
        $fresh = $this->savedVoice($member, DesignedVoice::DRAFT, 'voice_fresh');
        $kept = $this->savedVoice($member, DesignedVoice::SAVED, 'voice_kept');
        $here = VoiceDesignService::DISPLAY_TAG.' · '.VoiceDesignService::siteTag();
        $this->fakeEngine(['listed' => [
            ['id' => 'voice_lost', 'type' => 'prompted', 'display_name' => $here.' · U1 · abcd1234 · 1 · '.now()->subDays(2)->utc()->format('YmdHi')],
            ['id' => 'voice_making', 'type' => 'prompted', 'display_name' => $here.' · U1 · abcd1234 · 2 · '.now()->utc()->format('YmdHi')],
            // Another server's (live's, seen from staging): never this server's to delete.
            ['id' => 'voice_live', 'type' => 'prompted', 'display_name' => VoiceDesignService::DISPLAY_TAG.' · listenai.studio · U1 · abcd1234 · 1 · '.now()->subDays(2)->utc()->format('YmdHi')],
            ['id' => 'voice_theirs', 'type' => 'prompted', 'display_name' => 'Someone else'],
        ]]);

        $this->artisan('voice-design:prune')->assertSuccessful();

        $this->assertNull(DesignedVoice::withTrashed()->find($old->id));
        $this->assertNotNull($fresh->fresh());
        $this->assertNotNull($kept->fresh());
        $deleted = collect($this->callsTo('DELETE', '/voices/'))->pluck('path')->map(fn ($p) => basename($p))->sort()->values()->all();
        $this->assertSame(['voice_lost', 'voice_old'], $deleted);
    }

    public function test_a_voice_another_server_made_is_never_deleted_on_the_engine(): void
    {
        // Staging holds a copy of live's members and uses the same speech keys.
        $member = $this->member($this->designPlan);
        $live = $this->savedVoice($member, DesignedVoice::DRAFT, 'voice_live', 'listenai.studio');
        $live->forceFill(['created_at' => now()->subHours(3)])->save();
        $this->fakeEngine();

        $this->artisan('voice-design:prune')->assertSuccessful();

        $this->assertNull(DesignedVoice::withTrashed()->find($live->id));
        $this->assertSame([], $this->callsTo('DELETE', '/voices/'));
    }

    /* ------------------------------------------------------------ */
    /*  The example characters                                       */
    /* ------------------------------------------------------------ */

    public function test_the_examples_command_records_each_character_and_deletes_its_voice(): void
    {
        $dir = public_path(VoiceDesign::EXAMPLES_DIR);
        // The real clip, committed with the code, is put back afterwards.
        $real = is_file($dir.'/evil_ogre.mp3') ? file_get_contents($dir.'/evil_ogre.mp3') : null;
        $this->fakeEngine();

        try {
            $this->artisan('voice-design:examples', ['--key' => 'ci-key', '--only' => ['evil_ogre'], '--force' => true])->assertSuccessful();

            $this->assertFileExists($dir.'/evil_ogre.mp3');
            $create = $this->callsTo('POST', '/voices')[0];
            $this->assertStringContainsString('massive ogre', $create['body']['voice']['prompted']['input']);
            $this->assertStringContainsString(' · example · ', $create['body']['voice']['display_name']);
            $this->assertSame(trans('voice_design.examples.evil_ogre.line', [], 'en'), $this->callsTo('POST', '/interactions')[0]['body']['input'][0]['content'][0]['text']);
            $this->assertCount(1, $this->callsTo('DELETE', '/voices/voice_t1'));
            Http::assertSent(fn (Request $r) => $r->hasHeader('x-goog-api-key', 'ci-key'));
            // The card now plays it, and picking the character can too.
            $this->assertContains('evil_ogre', array_column(VoiceDesign::showcase(), 'key'));
        } finally {
            $real !== null ? file_put_contents($dir.'/evil_ogre.mp3', $real) : @unlink($dir.'/evil_ogre.mp3');
        }
    }

    public function test_the_card_plays_the_admins_chosen_voices_before_the_examples(): void
    {
        $member = $this->member($this->designPlan);
        $this->savedVoice($member)->forceFill(['is_showcase' => true, 'name' => 'Grandma Zeinab'])->save();

        $names = array_column(VoiceDesign::showcase(), 'name');

        $this->assertSame('Grandma Zeinab', $names[0]);
    }

    /* ------------------------------------------------------------ */
    /*  The admin                                                    */
    /* ------------------------------------------------------------ */

    public function test_the_admin_settings_page_saves_who_sees_it_and_the_takes(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        Livewire::actingAs($admin)->test(VoiceDesignSettings::class)
            ->set('data.voice_design_audience', VoiceDesign::AUDIENCE_OFF)
            ->set('data.voice_design_takes', 2)
            ->set('data.voice_design_max_per_user', 4)
            ->set('data.voice_design_hourly_limit', 6)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(VoiceDesign::AUDIENCE_OFF, VoiceDesign::audience());
        $this->assertSame(2, VoiceDesign::takes());
        $this->assertSame(4, VoiceDesign::maxVoices($this->plainPlan));
        $this->assertSame(6, VoiceDesign::hourlyLimit());
    }

    public function test_a_plans_own_number_of_voices_wins_over_the_site_default(): void
    {
        SiteSetting::set('voice_design_max_per_user', 7, VoiceDesign::GROUP);

        $this->assertSame(2, VoiceDesign::maxVoices($this->designPlan));
        $this->assertSame(7, VoiceDesign::maxVoices($this->plainPlan));
    }

    public function test_the_pricing_card_names_it_once_it_is_open_to_everyone(): void
    {
        $this->assertSame(trans_choice('ui.pricing.lines.design_voices', 2, [], 'en'), PlanPricingLines::value($this->designPlan, 'voice_design', 'en'));
        $this->assertNull(PlanPricingLines::value($this->plainPlan, 'voice_design', 'en'));

        $this->openTo(VoiceDesign::AUDIENCE_PREVIEW);
        $this->assertNull(PlanPricingLines::value($this->designPlan, 'voice_design', 'en'));
    }

    public function test_the_showcase_plays_only_kept_voices_the_admin_put_on_the_card(): void
    {
        $member = $this->member($this->designPlan);
        $this->savedVoice($member)->forceFill(['is_showcase' => true, 'name' => 'Grandma Zeinab'])->save();
        $this->savedVoice($member, DesignedVoice::DRAFT)->forceFill(['is_showcase' => true])->save();

        $chosen = array_values(array_filter(VoiceDesign::showcase(), fn (array $v): bool => $v['key'] === null));
        $this->assertSame(['Grandma Zeinab'], array_column($chosen, 'name'));
    }

    public function test_the_studio_never_names_another_company(): void
    {
        $page = $this->actingAs($this->member($this->designPlan))->getLocalizedRoute('dashboard');
        $section = $this->sectionOf($page->getContent());

        $this->assertStringContainsString(e(__('voice_design.prompt_label')), $section);
        $this->assertStringContainsString('data-vd-library', $section);
        foreach (['ElevenLabs', 'Eleven Labs', 'Gemini', 'Google'] as $name) {
            $this->assertStringNotContainsString($name, $section, $name);
        }
    }

    /* ------------------------------------------------------------ */
    /*  Helpers                                                      */
    /* ------------------------------------------------------------ */

    private function openTo(string $audience): void
    {
        SiteSetting::set('voice_design_audience', $audience, VoiceDesign::GROUP);
    }

    private function member(Plan $plan, ?string $email = null): User
    {
        $user = User::factory()->create($email ? ['email' => $email] : []);
        Subscription::create(['user_id' => $user->id, 'plan_id' => $plan->id, 'status' => 'active']);

        return $user;
    }

    /** @return array<string, mixed> */
    private function designInput(array $overrides = []): array
    {
        return $overrides + [
            'description' => 'A gruff old sea captain in his sixties, his voice worn rough by salt and shouting.',
            'language' => 'en',
            'auto_line' => true,
        ];
    }

    private function savedVoice(User $user, string $status = DesignedVoice::SAVED, string $voiceId = 'voice_saved', ?string $site = null): DesignedVoice
    {
        Storage::disk('public')->put('audio/designed/t/'.$voiceId.'.wav', $this->wav());

        return DesignedVoice::query()->create([
            'user_id' => $user->id, 'status' => $status, 'batch' => 'b-'.$voiceId, 'name' => $status === DesignedVoice::SAVED ? 'Captain' : null,
            'description' => 'A gruff old sea captain.', 'language' => 'en', 'preview_text' => self::LINE,
            'preview_audio_path' => 'audio/designed/t/'.$voiceId.'.wav', 'provider_voice_key' => $voiceId,
            'key_name' => 'GEMINI_TTS_API_KEY', 'take' => 1, 'kept_at' => $status === DesignedVoice::SAVED ? now() : null,
            'metadata' => ['site' => $site ?? VoiceDesignService::siteTag()],
        ]);
    }

    private function creditsUsed(User $user): int
    {
        return (int) UsageCounter::query()->where('user_id', $user->id)->sum('total_chars');
    }

    /** @return list<array{method:string, path:string, body:array}> */
    private function callsTo(string $method, string $path): array
    {
        return array_values(array_filter($this->calls, fn (array $call): bool => $call['method'] === $method && str_contains($call['path'], $path)));
    }

    /**
     * The speech service: each new voice (but the takes in create_fails),
     * the line each take speaks, the line written for the character, deletes,
     * and the list of stored voices.
     */
    private function fakeEngine(array $options = []): void
    {
        $wav = $this->wav();
        Http::fake(function (Request $request) use ($wav, $options) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            $this->calls[] = ['method' => $request->method(), 'path' => $path, 'body' => $request->data()];

            if (str_contains($path, ':generateContent')) {
                return Http::response(['candidates' => [['content' => ['parts' => [['text' => self::LINE]]]]]]);
            }
            if ($request->method() === 'POST' && str_ends_with($path, '/voices')) {
                $take = (int) (explode(' · ', (string) data_get($request->data(), 'voice.display_name'))[4] ?? 1);
                if (in_array($take, $options['create_fails'] ?? [], true)) {
                    return Http::response(['error' => ['message' => 'Internal error.', 'status' => 'INTERNAL']], 500);
                }

                return Http::response(['id' => 'voice_t'.$take, 'sample_audio' => ['mime_type' => 'audio/wav', 'data' => base64_encode($wav)]]);
            }
            if (str_ends_with($path, '/interactions')) {
                return Http::response(['steps' => [['type' => 'model_output', 'content' => [['type' => 'audio', 'data' => base64_encode($wav)]]]]]);
            }
            if ($request->method() === 'DELETE') {
                return Http::response([], 200);
            }
            if ($request->method() === 'GET' && str_ends_with($path, '/voices')) {
                return Http::response(['voices' => $options['listed'] ?? []]);
            }

            return Http::response(['error' => ['message' => 'Not faked: '.$path]], 404);
        });
    }

    /** Two seconds of a quiet tone, as a 24 kHz mono WAV. */
    private function wav(): string
    {
        $rate = 24000;
        $pcm = '';
        for ($i = 0; $i < $rate * 2; $i++) {
            $pcm .= pack('v', ((int) (sin($i / 12) * 3000)) & 0xFFFF);
        }

        return 'RIFF'.pack('V', 36 + strlen($pcm)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, $rate, $rate * 2, 2, 16).'data'.pack('V', strlen($pcm)).$pcm;
    }

    private function sectionOf(string $html): string
    {
        $start = strpos($html, 'id="voiceDesignSection"');

        return $start === false ? '' : substr($html, $start, (int) strpos($html, '</section>', strpos($html, 'data-vd-library') ?: $start) - $start);
    }
}
