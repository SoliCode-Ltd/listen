<?php

namespace Tests\Feature;

use App\Filament\Resources\AudioGenerationResource;
use App\Filament\Resources\AudioGenerationResource\Pages\ListAudioGenerations;
use App\Filament\Resources\DemoVoiceGenerationResource;
use App\Filament\Resources\DemoVoiceGenerationResource\Pages\ListDemoVoiceGenerations;
use App\Filament\Resources\GenerationErrorResource;
use App\Filament\Resources\GenerationErrorResource\Pages\ListGenerationErrors;
use App\Jobs\GenerateAudioJob;
use App\Jobs\ProcessVoiceCloningJob;
use App\Models\AudioGeneration;
use App\Models\ClonedVoice;
use App\Models\DemoVoiceGeneration;
use App\Models\GenerationError;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\TextItem;
use App\Models\User;
use App\Models\Voice;
use App\Services\GenerationErrorRecorder;
use App\Services\Tts\GeminiKeyManager;
use App\Services\Tts\PremiumVoiceUnavailableException;
use App\Services\Tts\TtsRoutingService;
use App\Services\TranslationService;
use App\Services\TtsService;
use App\Services\VoiceCloningService;
use App\Support\GenerationChoiceLabels;
use App\Support\VoiceCatalog;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Admins see each generation's choices (accent, style, emotion, voice) by
 * the names the site offers, and every failure members hit is kept in
 * Generation Errors. Speech is always faked: no real audio is generated.
 */
class GenerationErrorsAndChoicesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Plan::query()->firstOrCreate(['slug' => 'free'], [
            'name' => 'Free', 'price_cents' => 0, 'monthly_minutes' => 10, 'monthly_chars' => 10000,
            'monthly_requests' => 60, 'max_chars_per_request' => 1200, 'voice_tier' => 'standard',
            'tts_provider_policy' => 'google_cloud', 'dialogue_enabled' => false,
            'custom_prompt_enabled' => false, 'is_active' => true,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────
    //  Choice labels
    // ──────────────────────────────────────────────────────────────────

    public function test_accent_is_named_in_arabic_and_english(): void
    {
        $this->assertSame('مصري (قاهري) · Egyptian (Cairene) — ar-EG', GenerationChoiceLabels::accent('ar-EG', 'ar', 'hd'));
        $this->assertSame('سعودي (حجازي) · Saudi (Hejazi) — ar-SA-HJ', GenerationChoiceLabels::accent('ar-sa-hj', 'ar', 'hd'));
        $this->assertSame('أمريكي (جنوبي) · American (Southern) — en-US-SO', GenerationChoiceLabels::accent('en-US-SO', 'en', 'hd'));
    }

    public function test_missing_or_unknown_accent_says_so(): void
    {
        $this->assertSame('None chosen (no accent direction sent)', GenerationChoiceLabels::accent(null, 'ar', 'hd'));
        $this->assertSame('Not used (Standard voices have no accent choice)', GenerationChoiceLabels::accent('', 'ar', 'standard'));
        $this->assertSame('Unknown accent — ar-ZZ', GenerationChoiceLabels::accent('ar-ZZ', 'ar', 'hd'));
    }

    public function test_accent_an_admin_added_is_named_too(): void
    {
        SiteSetting::set('tts_dialects_catalog_ar', json_encode([
            ['value' => 'ar-EG-XX', 'label' => 'Egyptian (Test City)', 'label_ar' => 'مصري (تجريبي)'],
        ]), 'tts');

        $this->assertSame('مصري (تجريبي) · Egyptian (Test City) — ar-EG-XX', GenerationChoiceLabels::accent('ar-EG-XX', 'ar', 'hd'));
        $this->assertArrayHasKey('ar-EG-XX', GenerationChoiceLabels::accentOptions());
    }

    public function test_style_emotion_and_tier_use_the_site_names(): void
    {
        $this->assertStringContainsString('Promo', GenerationChoiceLabels::performanceStyle('promo'));
        $this->assertStringContainsString('Excited', GenerationChoiceLabels::emotion('excited'));
        $this->assertSame('—', GenerationChoiceLabels::emotion(null));
        $this->assertSame('bogus', GenerationChoiceLabels::emotion('bogus'));
        $this->assertStringEndsWith('— hd', GenerationChoiceLabels::tier('hd'));
        $this->assertStringEndsWith('— hd', GenerationChoiceLabels::tier('hd_plus'), 'old tier names still read correctly');
    }

    public function test_cloned_voice_is_named(): void
    {
        $voice = ClonedVoice::factory()->ready()->create(['name' => 'My Studio Voice']);

        $this->assertSame("Cloned voice: My Studio Voice (#{$voice->id})", GenerationChoiceLabels::voice('cloned_'.$voice->id));

        $voice->delete();
        $this->assertSame("Cloned voice: My Studio Voice (#{$voice->id})", GenerationChoiceLabels::voice('cloned_'.$voice->id), 'deleted voices keep their name');
    }

    // ──────────────────────────────────────────────────────────────────
    //  Admin generation pages
    // ──────────────────────────────────────────────────────────────────

    public function test_demo_generation_page_shows_the_chosen_accent(): void
    {
        $generation = $this->demoGeneration(['dialect' => 'ar-EG', 'performance_style' => 'promo', 'emotion' => 'excited']);
        $this->actingAsAdmin();

        $this->get(DemoVoiceGenerationResource::getUrl('view', ['record' => $generation]))
            ->assertOk()
            ->assertSee('مصري (قاهري) · Egyptian (Cairene) — ar-EG', false)
            ->assertSee('Excited', false);
    }

    public function test_demo_generations_can_be_filtered_by_accent(): void
    {
        $cairo = $this->demoGeneration(['dialect' => 'ar-EG']);
        $riyadh = $this->demoGeneration(['dialect' => 'ar-SA']);
        $this->actingAsAdmin();

        Livewire::test(ListDemoVoiceGenerations::class)
            ->filterTable('dialect', 'ar-EG')
            ->assertCanSeeTableRecords([$cairo])
            ->assertCanNotSeeTableRecords([$riyadh]);
    }

    public function test_dashboard_generation_page_shows_choices_and_the_real_error(): void
    {
        $user = User::factory()->create();
        $generation = AudioGeneration::create([
            'user_id' => $user->id,
            'input_text' => 'مرحبا',
            'source_language' => 'ar',
            'target_language' => 'ar',
            'voice_tier' => 'hd',
            'voice_name' => 'ar-test-voice',
            'translate' => false,
            'status' => 'failed',
            'error_message' => 'Voice generation failed. Please try again.',
            'dialect' => 'ar-EG-AL',
            'performance_style' => 'podcast',
            'emotion' => 'warm',
            'speed' => 1.1,
        ]);
        GenerationError::create([
            'source' => 'dashboard', 'severity' => 'error', 'user_id' => $user->id,
            'subject_type' => 'audio_generation', 'subject_id' => $generation->id,
            'message' => 'Gemini TTS API returned HTTP 503: model overloaded',
        ]);
        $this->actingAsAdmin();

        $this->get(AudioGenerationResource::getUrl('view', ['record' => $generation]))
            ->assertOk()
            ->assertSee('مصري (إسكندراني) · Egyptian (Alexandrian) — ar-EG-AL', false)
            ->assertSee('Podcast', false)
            ->assertSee('Warm', false)
            ->assertSee('1.1x', false)
            ->assertSee('HTTP 503: model overloaded', false);
    }

    public function test_dialogue_shows_the_voices_and_dialects_actually_spoken(): void
    {
        $egypt = 'مصري (قاهري) · Egyptian (Cairene) — ar-EG';
        $user = User::factory()->create();
        $dialogue = fn (array $speakers) => new AudioGeneration($this->audioRow($user, [
            'dialect' => 'ar-EG', 'target_language' => 'ar', 'voice_tier' => 'hd', 'voice_name' => 'ar-main-voice',
            'is_dialogue' => true, 'dialogue_metadata' => ['speakers' => $speakers],
        ]));

        // Nobody has their own dialect: everyone spoke the main one.
        $same = $dialogue([['name' => 'حمد', 'voice_name' => 'ar-voice-a'], ['name' => 'حمدة', 'voice_name' => 'ar-voice-b', 'dialect' => '']]);
        $this->assertSame($egypt, GenerationChoiceLabels::spokenAccent($same));
        $this->assertSame(['حمد: ar-voice-a', 'حمدة: ar-voice-b'], GenerationChoiceLabels::spokenVoices($same), 'the speakers spoke, not the main voice');

        // Both Emirati: one accent, and the unused main one is not mentioned.
        $emirati = $dialogue([['name' => 'حمد', 'voice_name' => 'ar-voice-a', 'dialect' => 'ar-AE'], ['name' => 'حمدة', 'voice_name' => 'ar-voice-b', 'dialect' => 'ar-AE']]);
        $this->assertStringEndsWith('— ar-AE', GenerationChoiceLabels::spokenAccent($emirati));
        $this->assertStringNotContainsString('Cairene', GenerationChoiceLabels::spokenAccent($emirati));

        // Different dialects: one per speaker.
        $mixed = $dialogue([['name' => 'حمد', 'voice_name' => 'ar-voice-a', 'dialect' => 'ar-AE'], ['name' => 'حمدة', 'voice_name' => 'ar-voice-b']]);
        $this->assertStringStartsWith('حمد: ', GenerationChoiceLabels::spokenAccent($mixed));
        $this->assertStringEndsWith('حمدة: '.$egypt, GenerationChoiceLabels::spokenAccent($mixed));

        $saved = AudioGeneration::create($this->audioRow($user, [
            'dialect' => 'ar-EG', 'target_language' => 'ar', 'voice_tier' => 'hd', 'voice_name' => 'ar-main-voice',
            'is_dialogue' => true, 'dialogue_metadata' => $emirati->dialogue_metadata,
        ]));
        $this->actingAsAdmin();
        Livewire::test(ListAudioGenerations::class)
            ->assertSee('حمدة: ar-voice-b', false)
            ->assertDontSee('Main setting not used', false);
        $this->get(AudioGenerationResource::getUrl('view', ['record' => $saved]))
            ->assertOk()
            ->assertSee('حمد — voice: ar-voice-a — accent: ', false)
            ->assertSee('— ar-AE', false);
    }

    public function test_every_setting_the_audio_was_made_with_is_shown(): void
    {
        $generation = AudioGeneration::create($this->audioRow(User::factory()->create(), [
            'target_language' => 'ar', 'voice_tier' => 'hd', 'voice_name' => 'ar-main-voice', 'dialect' => 'ar-EG',
            'performance_style' => 'podcast', 'emotion' => 'warm', 'speed' => 1.2, 'pitch' => -4.0,
            'style_prompt' => 'Like a late-night radio host', 'settings_snapshot' => ['audio_effect' => 'stadium'],
        ]));
        $settings = GenerationChoiceLabels::settings($generation);
        $this->assertCount(6, $settings);
        $this->assertStringStartsWith('Style: ', $settings[0]);
        $this->assertStringStartsWith('Emotion: ', $settings[1]);
        $this->assertSame('Speed: 1.2x', $settings[2]);
        $this->assertSame('Pitch: -4', $settings[3]);
        $this->assertSame('Direction: Like a late-night radio host', $settings[4]);
        $this->assertSame('Environment: ملعب · Stadium — stadium', $settings[5]);

        $plain = AudioGeneration::create($this->audioRow(User::factory()->create(), ['performance_style' => 'neutral', 'emotion' => 'neutral', 'speed' => 1.0, 'pitch' => 0.0]));
        $this->assertSame([], GenerationChoiceLabels::settings($plain));
        $this->assertSame('None', GenerationChoiceLabels::environment(null));
        $this->actingAsAdmin();

        Livewire::test(ListAudioGenerations::class)
            ->assertSee('Environment: ملعب · Stadium — stadium', false)
            ->assertSee('Speed: 1.2x', false);
        $this->get(AudioGenerationResource::getUrl('view', ['record' => $generation]))
            ->assertOk()
            ->assertSee('ملعب · Stadium — stadium', false)
            ->assertSee('Like a late-night radio host', false);
        $this->get(AudioGenerationResource::getUrl('view', ['record' => $plain]))
            ->assertOk()
            ->assertSee('A Standard voice: no accent, style, emotion, speed or pitch is applied.', false);
    }

    public function test_dashboard_generation_edit_and_create_pages_still_open(): void
    {
        $generation = AudioGeneration::create($this->audioRow(User::factory()->create(), ['dialect' => 'ar-EG', 'target_language' => 'ar', 'voice_tier' => 'hd']));
        $this->actingAsAdmin();

        $this->get(AudioGenerationResource::getUrl('edit', ['record' => $generation]))->assertOk()->assertSee('Egyptian (Cairene)', false);
        $this->get(AudioGenerationResource::getUrl('create'))->assertOk();
        $this->get(GenerationErrorResource::getUrl('index'))->assertOk();
    }

    public function test_dashboard_generations_filter_by_the_real_statuses(): void
    {
        $user = User::factory()->create();
        $done = AudioGeneration::create($this->audioRow($user, ['status' => 'done']));
        $failed = AudioGeneration::create($this->audioRow($user, ['status' => 'failed']));
        $this->actingAsAdmin();

        Livewire::test(ListAudioGenerations::class)
            ->filterTable('status', 'done')
            ->assertCanSeeTableRecords([$done])
            ->assertCanNotSeeTableRecords([$failed]);
    }

    // ──────────────────────────────────────────────────────────────────
    //  Errors are recorded
    // ──────────────────────────────────────────────────────────────────

    public function test_failed_dashboard_generation_is_recorded_with_the_real_reason(): void
    {
        $user = User::factory()->create();
        $generation = AudioGeneration::create($this->audioRow($user, ['status' => 'queued', 'dialect' => 'ar-EG', 'target_language' => 'ar', 'voice_tier' => 'hd']));
        $this->fakeSpeechFailure('Gemini TTS API returned HTTP 500: internal');

        $this->runJob($generation);
        $this->runJob($generation); // the same generation failing again

        $this->assertSame(1, GenerationError::count(), 'a retry updates the same row');
        $error = GenerationError::first();
        $this->assertSame('dashboard', $error->source);
        $this->assertSame('error', $error->severity);
        $this->assertSame($user->id, $error->user_id);
        $this->assertSame('ar-EG', $error->dialect);
        $this->assertSame(2, $error->occurrences);
        $this->assertStringContainsString('HTTP 500: internal', $error->message);
        $this->assertSame("Your voice couldn't be created this time, and no credits were used. Please try again.", $error->user_message);
        $this->assertTrue($error->context['final']);
    }

    public function test_failed_extension_generation_is_labelled_extension(): void
    {
        $user = User::factory()->create();
        $item = TextItem::create(['user_id' => $user->id, 'source_type' => 'extension', 'content' => 'Hi', 'language' => 'en', 'status' => 'queued']);
        $generation = AudioGeneration::create($this->audioRow($user, ['status' => 'queued', 'text_item_id' => $item->id]));
        $this->fakeSpeechFailure('boom');

        $this->runJob($generation);

        $this->assertSame('extension', GenerationError::first()->source);
    }

    public function test_failed_studio_generation_is_labelled_dashboard(): void
    {
        $user = User::factory()->create();
        $item = TextItem::create(['user_id' => $user->id, 'source_type' => 'message', 'content' => 'Hi', 'language' => 'en', 'status' => 'queued']);
        $generation = AudioGeneration::create($this->audioRow($user, ['status' => 'queued', 'text_item_id' => $item->id]));
        $this->fakeSpeechFailure('boom');

        $this->runJob($generation);

        $this->assertSame('dashboard', GenerationError::first()->source);
    }

    public function test_job_killed_by_a_timeout_is_recorded(): void
    {
        $user = User::factory()->create();
        $generation = AudioGeneration::create($this->audioRow($user, ['status' => 'processing']));

        $job = new GenerateAudioJob($generation->id);
        $job->connection = 'database';
        $job->failed(new \Illuminate\Queue\TimeoutExceededException('Job has timed out.'));

        $this->assertStringContainsString('timed out', GenerationError::first()->message);
    }

    public function test_failed_demo_preview_is_recorded(): void
    {
        $user = User::factory()->create(['registration_ip' => '203.0.113.90']);
        $voice = Voice::query()->create([
            'language' => 'en', 'gender' => 'female', 'tier' => 'standard', 'display_name' => 'Emma',
            'internal_voice_id' => 'en-US-Wavenet-C', 'provider' => 'google_cloud',
            'provider_voice_id' => 'en-US-Wavenet-C', 'is_active' => true,
        ]);
        $translation = $this->createMock(TranslationService::class);
        $translation->method('maybeTranslate')->willReturnArgument(0);
        $this->app->instance(TranslationService::class, $translation);
        $tts = $this->createMock(TtsService::class);
        $tts->method('synthesizeAdvanced')->willThrowException(new \RuntimeException('Google TTS failed with HTTP 403'));
        $this->app->instance(TtsService::class, $tts);

        $this->actingAs($user)->postJsonLocalizedRoute('demo.preview', [
            'text' => 'Hello there',
            'target_language' => 'en',
            'voice_tier' => 'standard',
            'voice_key' => VoiceCatalog::keyFromId((int) $voice->id),
        ])->assertStatus(422);

        $error = GenerationError::first();
        $this->assertNotNull($error);
        $this->assertSame('demo', $error->source);
        $this->assertSame($user->id, $error->user_id);
        $this->assertSame('en-US-Wavenet-C', $error->voice_name);
        $this->assertStringContainsString('HTTP 403', $error->message);
    }

    public function test_a_failed_premium_voice_uses_no_backup_engine(): void
    {
        config([
            'services.google_tts.api_key' => 'test-google-key',
            'services.google_tts.endpoint' => 'https://texttospeech.googleapis.com/v1/text:synthesize',
            'services.google_tts.audio_encoding' => 'MP3',
            'services.gemini_tts.retry_pause_ms' => 0,
            'services.gemini_tts.transient_retries' => 0,
        ]);
        SiteSetting::set('tts_mode', TtsRoutingService::MODE_HYBRID, 'tts');
        SiteSetting::set('tts_fallback_enabled', '1', 'tts');
        $key = ['key' => 'test-gemini-key', 'name' => 'GEMINI_TTS_API_KEY', 'index' => 1];
        $keys = \Mockery::mock(GeminiKeyManager::class);
        $keys->shouldReceive('getAllKeys')->andReturn([$key]);
        $keys->shouldReceive('getAvailableKey')->andReturn($key);
        $keys->shouldReceive('recordUsage')->andReturnNull();
        $keys->shouldReceive('markKeyExhausted')->andReturnNull();
        $this->app->instance(GeminiKeyManager::class, $keys);
        Http::fake(fn ($request) => str_contains($request->url(), 'texttospeech')
            ? Http::response(['audioContent' => base64_encode('google-audio')])
            : Http::response(['error' => ['message' => 'Bad request']], 400));
        $user = User::factory()->create();
        $plan = Plan::factory()->create(['slug' => 'starter-x', 'price_cents' => 900, 'tts_provider_policy' => 'gemini', 'voice_tier' => 'hd', 'max_chars_per_request' => 5000]);

        try {
            app(TtsService::class)->synthesizeAdvanced('Hello there', 'en', [
                'voice_name' => 'Kore', 'provider' => 'gemini', 'tier' => 'hd', 'gender' => 'female',
                'performance_style' => 'neutral', 'emotion' => 'neutral', 'dialect' => 'en-GB',
            ], $plan, $user->id);
            $this->fail('A premium voice that fails is not read by a backup engine.');
        } catch (PremiumVoiceUnavailableException $e) {
            $this->assertStringContainsString('HTTP 400', $e->getMessage());
        }

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'texttospeech'));
        $this->assertSame(0, GenerationError::query()->where('source', 'fallback')->count(), 'no "backup engine used" warning');
    }

    public function test_voice_cloning_failure_is_recorded(): void
    {
        $voice = ClonedVoice::factory()->create(['source_audio_duration_seconds' => 1, 'status' => 'pending']);

        (new ProcessVoiceCloningJob($voice->id))->handle(app(VoiceCloningService::class));

        $error = GenerationError::first();
        $this->assertSame('voice_cloning', $error->source);
        $this->assertSame('warning', $error->severity, 'a too-short sample is the member\'s to fix');
        $this->assertSame($voice->user_id, $error->user_id);
        $this->assertSame('duration', $error->context['failure_code']);
    }

    public function test_the_same_failure_within_an_hour_counts_on_one_row(): void
    {
        $user = User::factory()->create();
        $recorder = app(GenerationErrorRecorder::class);

        $recorder->record(['source' => 'fallback', 'severity' => 'warning', 'user_id' => $user->id, 'message' => 'HTTP 503']);
        $recorder->record(['source' => 'fallback', 'severity' => 'warning', 'user_id' => $user->id, 'message' => 'HTTP 503']);
        $recorder->record(['source' => 'fallback', 'severity' => 'warning', 'user_id' => $user->id, 'message' => 'HTTP 500']);
        $recorder->record(['source' => 'fallback', 'severity' => 'warning', 'user_id' => null, 'message' => 'HTTP 503']);

        $this->assertSame(3, GenerationError::count());
        $this->assertSame(2, GenerationError::where('user_id', $user->id)->where('message', 'HTTP 503')->value('occurrences'));

        $this->travel(2)->hours();
        $recorder->record(['source' => 'fallback', 'severity' => 'warning', 'user_id' => $user->id, 'message' => 'HTTP 503']);
        $this->assertSame(4, GenerationError::count(), 'a later outage gets its own row');
    }

    public function test_keys_never_reach_the_error_log(): void
    {
        app(GenerationErrorRecorder::class)->record([
            'source' => 'demo',
            'message' => 'GET https://x.test/v1?key=AIzaSyA1234567890abcdefghijklmnop&alt=json failed; Bearer abc.def-123',
        ]);

        $message = GenerationError::first()->message;
        $this->assertStringNotContainsString('AIzaSy', $message);
        $this->assertStringNotContainsString('abc.def-123', $message);
    }

    public function test_recording_never_breaks_a_generation_before_the_migration_runs(): void
    {
        Schema::drop('generation_errors');

        $this->assertNull(app(GenerationErrorRecorder::class)->record(['source' => 'demo', 'message' => 'x']));
        Cache::forget('admin:generation_errors_open');
        $this->assertNull(GenerationErrorResource::getNavigationBadge());
    }

    // ──────────────────────────────────────────────────────────────────
    //  Generation Errors tab
    // ──────────────────────────────────────────────────────────────────

    public function test_errors_tab_lists_open_errors_and_counts_them_in_the_menu(): void
    {
        $open = GenerationError::create(['source' => 'dashboard', 'severity' => 'error', 'message' => 'open one', 'last_seen_at' => now()]);
        $warning = GenerationError::create(['source' => 'fallback', 'severity' => 'warning', 'message' => 'backup used', 'last_seen_at' => now()]);
        $resolved = GenerationError::create(['source' => 'demo', 'severity' => 'error', 'message' => 'fixed', 'resolved_at' => now(), 'last_seen_at' => now()]);
        $this->actingAsAdmin();

        Livewire::test(ListGenerationErrors::class)
            ->assertCanSeeTableRecords([$open, $warning])
            ->assertCanNotSeeTableRecords([$resolved]);

        Cache::forget('admin:generation_errors_open');
        $this->assertSame('1', GenerationErrorResource::getNavigationBadge(), 'only open errors, not warnings');
    }

    public function test_marking_resolved_clears_it_from_the_open_list(): void
    {
        $error = GenerationError::create(['source' => 'dashboard', 'severity' => 'error', 'message' => 'boom', 'last_seen_at' => now()]);
        $this->actingAsAdmin();

        Livewire::test(ListGenerationErrors::class)
            ->callTableAction('resolve', $error)
            ->assertCanNotSeeTableRecords([$error]);

        $this->assertNotNull($error->fresh()->resolved_at);
        $this->assertNull(GenerationErrorResource::getNavigationBadge());
    }

    public function test_a_resolved_error_happening_again_opens_a_new_row(): void
    {
        $user = User::factory()->create();
        $generation = AudioGeneration::create($this->audioRow($user, ['status' => 'queued']));
        $this->fakeSpeechFailure('boom');

        $this->runJob($generation);
        GenerationError::query()->update(['resolved_at' => now()]);
        $this->runJob($generation);

        $this->assertSame(2, GenerationError::count());
        $this->assertSame(1, GenerationError::query()->unresolved()->count());
    }

    public function test_error_page_shows_member_and_choices(): void
    {
        $user = User::factory()->create(['email' => 'member@example.com']);
        $error = GenerationError::create([
            'source' => 'demo', 'severity' => 'error', 'user_id' => $user->id,
            'target_language' => 'ar', 'voice_tier' => 'hd', 'dialect' => 'ar-EG',
            'message' => 'Gemini TTS API returned HTTP 500', 'user_message' => 'Try again',
            'context' => ['emotion' => 'warm', 'performance_style' => 'news'], 'last_seen_at' => now(),
        ]);
        $this->actingAsAdmin();

        $this->get(GenerationErrorResource::getUrl('view', ['record' => $error]))
            ->assertOk()
            ->assertSee('member@example.com')
            ->assertSee('مصري (قاهري) · Egyptian (Cairene) — ar-EG', false)
            ->assertSee('Gemini TTS API returned HTTP 500')
            ->assertSee('Try again');
    }

    // ──────────────────────────────────────────────────────────────────
    //  Helpers
    // ──────────────────────────────────────────────────────────────────

    private function actingAsAdmin(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function runJob(AudioGeneration $generation): void
    {
        // Queued again in the database, as a queue retry finds it (a failed one is never made again).
        $generation->refresh()->update(['status' => 'queued']);

        try {
            GenerateAudioJob::dispatchSync($generation->id);
        } catch (\Throwable) {
        }
    }

    private function fakeSpeechFailure(string $message): void
    {
        $translation = $this->createMock(TranslationService::class);
        $translation->method('maybeTranslate')->willReturnArgument(0);
        $this->app->instance(TranslationService::class, $translation);

        $tts = $this->createMock(TtsService::class);
        $tts->method('synthesizeAdvanced')->willThrowException(new \RuntimeException($message));
        $this->app->instance(TtsService::class, $tts);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function audioRow(User $user, array $overrides = []): array
    {
        return array_merge([
            'user_id' => $user->id,
            'input_text' => 'Hello there',
            'source_language' => 'en',
            'target_language' => 'en',
            'voice_tier' => 'standard',
            'voice_name' => 'en-US-Wavenet-C',
            'translate' => false,
            'status' => 'done',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function demoGeneration(array $overrides = []): DemoVoiceGeneration
    {
        return DemoVoiceGeneration::query()->create(array_merge([
            'user_id' => User::factory()->create()->id,
            'primary_identity_hash' => 'fp:test',
            'input_text' => 'مرحبا',
            'speech_text' => 'مرحبا',
            'source_language' => 'ar',
            'target_language' => 'ar',
            'translate' => false,
            'voice_name' => 'ar-test-voice',
            'voice_provider' => 'gemini',
            'voice_tier' => 'hd',
            'billable_chars' => 5,
        ], $overrides));
    }
}
