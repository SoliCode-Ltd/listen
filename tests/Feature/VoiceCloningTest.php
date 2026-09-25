<?php

namespace Tests\Feature;

use App\Jobs\ProcessVoiceCloningJob;
use App\Models\ClonedVoice;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\Subscription;
use App\Models\User;
use App\Services\VoiceCloningService;
use App\Support\VoiceCloningAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VoiceCloningTest extends TestCase
{
    use RefreshDatabase;

    private User $freeUser;
    private User $paidUser;
    private Plan $freePlan;
    private Plan $paidPlan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freePlan = Plan::factory()->free()->create(['slug' => 'free']);
        $this->paidPlan = Plan::factory()->paid()->create([
            'slug' => 'creator',
            'price_cents' => 1999,
            'voice_tier' => 'hd',
            'voice_cloning_enabled' => true,
            'voice_cloning_max_voices' => 5,
        ]);

        $this->freeUser = User::factory()->create();
        Subscription::create([
            'user_id' => $this->freeUser->id,
            'plan_id' => $this->freePlan->id,
            'status' => 'active',
        ]);

        $this->paidUser = User::factory()->create([
            'email' => VoiceCloningAccess::PREVIEW_EMAIL,
        ]);
        Subscription::create([
            'user_id' => $this->paidUser->id,
            'plan_id' => $this->paidPlan->id,
            'status' => 'active',
        ]);

        Storage::fake('local');
        Storage::fake('public');
    }

    /* ------------------------------------------------------------ */
    /*  Access control tests                                         */
    /* ------------------------------------------------------------ */

    public function test_guest_cannot_access_voice_cloning_page(): void
    {
        $response = $this->getLocalizedRoute('voice-cloning.index');
        $response->assertRedirect();
    }

    public function test_free_user_sees_upgrade_prompt_instead_of_the_studio(): void
    {
        $response = $this->actingAs($this->freeUser)->getLocalizedRoute('voice-cloning.index');

        $response->assertRedirect(route('dashboard') . '#voiceCloningSection');

        app()->setLocale('en');
        $page = $this->actingAs($this->freeUser)->getLocalizedRoute('dashboard');

        $page->assertOk();
        $page->assertSee(__('ui.nav.voice_cloning'), false);
        $page->assertSee(__('ui.voice_cloning.upgrade_title'), false);
        $page->assertSee(__('ui.voice_cloning.upgrade_cta'), false);
        $page->assertSee(route('billing.page'), false);
        $page->assertDontSee(__('ui.voice_cloning.create_title'), false);
        $page->assertDontSee(__('ui.voice_cloning.preview_badge'), false);
        $page->assertDontSee(VoiceCloningService::CONSENT_SCRIPTS['en'], false);

        app()->setLocale('ar');
        $arabic = $this->actingAs($this->freeUser)->getLocalizedRoute('dashboard');
        $arabic->assertOk();
        $arabic->assertSee(__('ui.voice_cloning.upgrade_title'), false);
        $arabic->assertSee(__('ui.voice_cloning.upgrade_cta'), false);
        app()->setLocale('en');
    }

    public function test_paid_user_outside_preview_cannot_see_voice_cloning(): void
    {
        $outsider = User::factory()->create(['email' => 'listener@example.com']);
        Subscription::create([
            'user_id' => $outsider->id,
            'plan_id' => $this->paidPlan->id,
            'status' => 'active',
        ]);

        $response = $this->actingAs($outsider)->getLocalizedRoute('voice-cloning.index');
        $response->assertRedirect(route('dashboard'));

        $page = $this->actingAs($outsider)->getLocalizedRoute('dashboard');
        $page->assertOk();
        $page->assertDontSee(__('ui.voice_cloning.page_title'), false);
        $page->assertDontSee(__('ui.voice_cloning.cloned_voice_group'), false);
        $page->assertDontSee(VoiceCloningService::CONSENT_SCRIPTS['en'], false);
    }

    public function test_preview_admin_sees_voice_cloning_studio_in_english_and_arabic(): void
    {
        $response = $this->actingAs($this->paidUser)->getLocalizedRoute('voice-cloning.index');
        $response->assertRedirect(route('dashboard') . '#voiceCloningSection');

        app()->setLocale('en');
        $english = $this->actingAs($this->paidUser)->getLocalizedRoute('dashboard');
        $english->assertOk();
        $english->assertSee(__('ui.voice_cloning.page_title'), false);
        $english->assertSee(__('ui.voice_cloning.step_consent'), false);
        $english->assertSee(__('ui.voice_cloning.consent_upload_label'), false);
        $english->assertSee(VoiceCloningService::CONSENT_SCRIPTS['en-US'], false);

        app()->setLocale('ar');
        $arabic = $this->actingAs($this->paidUser)->getLocalizedRoute('dashboard');
        $arabic->assertOk();
        $arabic->assertSee(__('ui.voice_cloning.page_title'), false);
        $arabic->assertSee(__('ui.voice_cloning.step_sample'), false);
        $arabic->assertSee(__('ui.voice_cloning.consent_upload_label'), false);
        $arabic->assertSee(VoiceCloningService::CONSENT_SCRIPTS['ar-XA'], false);
        app()->setLocale('en');
    }

    public function test_free_user_cannot_submit_voice_clone(): void
    {
        $file = UploadedFile::fake()->create('sample.wav', 1024, 'audio/wav');

        $response = $this->actingAs($this->freeUser)
            ->postJsonLocalizedRoute('voice-cloning.store', [
                'name' => 'Test Voice',
                'language_code' => 'ar-XA',
                'source_type' => 'upload',
                'audio_file' => $file,
                'consent_audio' => UploadedFile::fake()->create('consent.wav', 800, 'audio/wav'),
                'consent_confirmed' => '1',
                'consent_duration' => 8,
                'audio_duration' => 15,
            ]);

        $response->assertNotFound();
        $this->assertDatabaseCount('cloned_voices', 0);
    }

    public function test_guest_cannot_submit_voice_clone(): void
    {
        $file = UploadedFile::fake()->create('sample.wav', 1024, 'audio/wav');

        $response = $this->postJsonLocalizedRoute('voice-cloning.store', [
            'name' => 'Test Voice',
            'language_code' => 'ar-XA',
            'source_type' => 'upload',
            'audio_file' => $file,
            'consent_confirmed' => '1',
            'audio_duration' => 15,
        ]);

        $response->assertUnauthorized();
    }

    /* ------------------------------------------------------------ */
    /*  Upload validation tests                                      */
    /* ------------------------------------------------------------ */

    public function test_valid_upload_is_accepted(): void
    {
        Bus::fake([ProcessVoiceCloningJob::class]);

        $file = UploadedFile::fake()->create('sample.wav', 2048, 'audio/wav');

        $response = $this->actingAs($this->paidUser)
            ->postJsonLocalizedRoute('voice-cloning.store', [
                'name' => 'My Custom Voice',
                'language_code' => 'ar-XA',
                'source_type' => 'upload',
                'audio_file' => $file,
                'consent_audio' => UploadedFile::fake()->create('consent.wav', 800, 'audio/wav'),
                'consent_confirmed' => '1',
                'consent_duration' => 8,
                'audio_duration' => 12,
            ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);
        $this->assertDatabaseHas('cloned_voices', [
            'user_id' => $this->paidUser->id,
            'name' => 'My Custom Voice',
            'language_code' => 'ar-XA',
            'source_type' => 'upload',
            'status' => 'pending',
            'provider' => 'gemini',
            'consent_confirmed' => true,
        ]);
        $this->assertNotNull(ClonedVoice::first()?->consent_audio_path);

        Bus::assertDispatched(ProcessVoiceCloningJob::class);
    }

    public function test_webm_upload_is_accepted(): void
    {
        Bus::fake([ProcessVoiceCloningJob::class]);

        $file = UploadedFile::fake()->create('sample.webm', 2048, 'video/webm');

        $response = $this->actingAs($this->paidUser)
            ->postJsonLocalizedRoute('voice-cloning.store', [
                'name' => 'WebM Voice',
                'language_code' => 'ar-XA',
                'source_type' => 'upload',
                'audio_file' => $file,
                'consent_audio' => UploadedFile::fake()->create('consent.wav', 800, 'audio/wav'),
                'consent_confirmed' => '1',
                'consent_duration' => 8,
                'audio_duration' => 12,
            ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);
        $this->assertDatabaseHas('cloned_voices', [
            'user_id' => $this->paidUser->id,
            'name' => 'WebM Voice',
            'source_type' => 'upload',
        ]);
    }

    public function test_whatsapp_like_mp4_with_3gpp_mime_is_accepted(): void
    {
        Bus::fake([ProcessVoiceCloningJob::class]);

        $file = UploadedFile::fake()->create('whatsapp-audio.mp4', 512, 'video/3gpp');

        $response = $this->actingAs($this->paidUser)
            ->postJsonLocalizedRoute('voice-cloning.store', [
                'name' => 'WhatsApp Audio',
                'language_code' => 'ar-XA',
                'source_type' => 'upload',
                'audio_file' => $file,
                'consent_audio' => UploadedFile::fake()->create('consent.wav', 800, 'audio/wav'),
                'consent_confirmed' => '1',
                'consent_duration' => 8,
                'audio_duration' => 12,
            ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);
        $this->assertDatabaseHas('cloned_voices', [
            'user_id' => $this->paidUser->id,
            'name' => 'WhatsApp Audio',
        ]);
    }

    public function test_short_audio_is_rejected(): void
    {
        $file = UploadedFile::fake()->create('short.wav', 512, 'audio/wav');

        $response = $this->actingAs($this->paidUser)
            ->postJsonLocalizedRoute('voice-cloning.store', [
                'name' => 'Short Voice',
                'language_code' => 'ar-XA',
                'source_type' => 'upload',
                'audio_file' => $file,
                'consent_audio' => UploadedFile::fake()->create('consent.wav', 800, 'audio/wav'),
                'consent_confirmed' => '1',
                'consent_duration' => 8,
                'audio_duration' => 5, // Less than minimum 10 seconds
            ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('cloned_voices', 0);
    }

    public function test_invalid_mime_type_is_rejected(): void
    {
        $file = UploadedFile::fake()->create('document.pdf', 2048, 'application/pdf');

        $response = $this->actingAs($this->paidUser)
            ->postJsonLocalizedRoute('voice-cloning.store', [
                'name' => 'Bad File',
                'language_code' => 'ar-XA',
                'source_type' => 'upload',
                'audio_file' => $file,
                'consent_audio' => UploadedFile::fake()->create('consent.wav', 800, 'audio/wav'),
                'consent_confirmed' => '1',
                'consent_duration' => 8,
                'audio_duration' => 15,
            ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('cloned_voices', 0);
    }

    public function test_consent_is_required(): void
    {
        $file = UploadedFile::fake()->create('sample.wav', 1024, 'audio/wav');

        $response = $this->actingAs($this->paidUser)
            ->postJsonLocalizedRoute('voice-cloning.store', [
                'name' => 'No Consent Voice',
                'language_code' => 'ar-XA',
                'source_type' => 'upload',
                'audio_file' => $file,
                'consent_confirmed' => '0',
                'audio_duration' => 15,
            ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('cloned_voices', 0);
    }

    public function test_voice_name_is_required(): void
    {
        $file = UploadedFile::fake()->create('sample.wav', 1024, 'audio/wav');

        $response = $this->actingAs($this->paidUser)
            ->postJsonLocalizedRoute('voice-cloning.store', [
                'name' => '',
                'language_code' => 'ar-XA',
                'source_type' => 'upload',
                'audio_file' => $file,
                'consent_audio' => UploadedFile::fake()->create('consent.wav', 800, 'audio/wav'),
                'consent_confirmed' => '1',
                'consent_duration' => 8,
                'audio_duration' => 15,
            ]);

        $response->assertStatus(422);
    }

    /* ------------------------------------------------------------ */
    /*  Ownership and isolation tests                                */
    /* ------------------------------------------------------------ */

    public function test_cloned_voice_belongs_to_correct_user(): void
    {
        Bus::fake([ProcessVoiceCloningJob::class]);

        $file = UploadedFile::fake()->create('sample.wav', 1024, 'audio/wav');

        $this->actingAs($this->paidUser)
            ->postJsonLocalizedRoute('voice-cloning.store', [
                'name' => 'User Voice',
                'language_code' => 'en-US',
                'source_type' => 'upload',
                'audio_file' => $file,
                'consent_audio' => UploadedFile::fake()->create('consent.wav', 800, 'audio/wav'),
                'consent_confirmed' => '1',
                'consent_duration' => 8,
                'audio_duration' => 12,
            ]);

        $voice = ClonedVoice::first();
        $this->assertNotNull($voice);
        $this->assertEquals($this->paidUser->id, $voice->user_id);
    }

    public function test_user_cannot_see_another_users_cloned_voice(): void
    {
        $otherUser = User::factory()->create();
        Subscription::create([
            'user_id' => $otherUser->id,
            'plan_id' => $this->paidPlan->id,
            'status' => 'active',
        ]);

        $voice = ClonedVoice::factory()->ready()->create([
            'user_id' => $otherUser->id,
            'name' => 'Secret Voice',
        ]);

        // Try to get status of another user's voice
        $response = $this->actingAs($this->paidUser)
            ->withoutMiddleware([
                \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
                \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
                \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath::class,
            ])
            ->getJson($this->localizedRoute('voice-cloning.status', ['id' => $voice->id]));

        $response->assertStatus(404);
    }

    public function test_user_cannot_delete_another_users_voice(): void
    {
        $otherUser = User::factory()->create();
        $voice = ClonedVoice::factory()->ready()->create([
            'user_id' => $otherUser->id,
        ]);

        $response = $this->actingAs($this->paidUser)
            ->withoutMiddleware([
                \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
                \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
                \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath::class,
                \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            ])
            ->deleteJson($this->localizedRoute('voice-cloning.destroy', ['id' => $voice->id]));

        $response->assertStatus(404);
        $this->assertDatabaseHas('cloned_voices', ['id' => $voice->id]);
    }

    /* ------------------------------------------------------------ */
    /*  Limit enforcement tests                                      */
    /* ------------------------------------------------------------ */

    public function test_per_user_limit_is_enforced(): void
    {
        // Set plan-specific max to 2
        $this->paidPlan->update(['voice_cloning_max_voices' => 2]);

        ClonedVoice::factory()->count(2)->create([
            'user_id' => $this->paidUser->id,
        ]);

        $dashboard = $this->actingAs($this->paidUser)
            ->getLocalizedRoute('dashboard');

        $dashboard->assertOk();
        $dashboard->assertSee(__('ui.voice_cloning.voice_count', ['count' => 2, 'max' => 2]), false);
        $dashboard->assertSee(__('ui.voice_cloning.limit_reached', ['max' => 2]), false);
        $dashboard->assertSee('class="vc-notice" id="vcLimitNotice"', false);
        $html = $dashboard->getContent();
        $this->assertMatchesRegularExpression('/id="vcLimitNotice"(?!\s+style="display:none")/', $html);
        $this->assertMatchesRegularExpression('/id="vcCreatePanel"\s+style="display:none"/', $html);

        $file = UploadedFile::fake()->create('sample.wav', 1024, 'audio/wav');

        $response = $this->actingAs($this->paidUser)
            ->postJsonLocalizedRoute('voice-cloning.store', [
                'name' => 'Third Voice',
                'language_code' => 'ar-XA',
                'source_type' => 'upload',
                'audio_file' => $file,
                'consent_audio' => UploadedFile::fake()->create('consent.wav', 800, 'audio/wav'),
                'consent_confirmed' => '1',
                'consent_duration' => 8,
                'audio_duration' => 15,
            ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['error' => __('ui.voice_cloning.limit_reached', ['max' => 2])]);
    }

    public function test_plan_max_of_zero_uses_the_site_default_for_the_preview_account(): void
    {
        SiteSetting::set('voice_cloning_max_per_user', 5);
        $this->paidPlan->update([
            'voice_cloning_enabled' => true,
            'voice_cloning_max_voices' => 0,
        ]);

        $dashboard = $this->actingAs($this->paidUser)
            ->getLocalizedRoute('dashboard');

        $dashboard->assertOk();
        $dashboard->assertSee(__('ui.voice_cloning.voice_count', ['count' => 0, 'max' => 5]), false);
        $dashboard->assertSee(__('ui.voice_cloning.create_title'), false);
        $dashboard->assertDontSee(__('ui.voice_cloning.limit_reached', ['max' => 0]), false);
        $html = $dashboard->getContent();
        $this->assertDoesNotMatchRegularExpression('/id="vcCreatePanel"\s+style="display:none"/', $html);
        $this->assertMatchesRegularExpression('/id="vcLimitNotice"\s+style="display:none"/', $html);

        Bus::fake([ProcessVoiceCloningJob::class]);
        $file = UploadedFile::fake()->create('sample.wav', 1024, 'audio/wav');
        $submit = $this->actingAs($this->paidUser)
            ->postJsonLocalizedRoute('voice-cloning.store', [
                'name' => 'Preview Voice',
                'language_code' => 'ar-XA',
                'source_type' => 'upload',
                'audio_file' => $file,
                'consent_audio' => UploadedFile::fake()->create('consent.wav', 800, 'audio/wav'),
                'consent_confirmed' => '1',
                'consent_duration' => 8,
                'audio_duration' => 15,
            ]);

        $submit->assertOk();
        $submit->assertJson(['success' => true]);
        Bus::assertDispatched(ProcessVoiceCloningJob::class);
    }

    public function test_plan_toggle_off_blocks_cloning_even_for_the_preview_account(): void
    {
        $this->paidPlan->update([
            'voice_cloning_enabled' => false,
            'voice_cloning_max_voices' => 5,
        ]);

        $dashboard = $this->actingAs($this->paidUser)
            ->getLocalizedRoute('dashboard');

        $dashboard->assertOk();
        $dashboard->assertSee(__('ui.voice_cloning.plan_disabled'), false);
        $dashboard->assertDontSee(__('ui.voice_cloning.create_title'), false);

        $file = UploadedFile::fake()->create('sample.wav', 1024, 'audio/wav');
        $submit = $this->actingAs($this->paidUser)
            ->postJsonLocalizedRoute('voice-cloning.store', [
                'name' => 'Blocked Voice',
                'language_code' => 'ar-XA',
                'source_type' => 'upload',
                'audio_file' => $file,
                'consent_audio' => UploadedFile::fake()->create('consent.wav', 800, 'audio/wav'),
                'consent_confirmed' => '1',
                'consent_duration' => 8,
                'audio_duration' => 15,
            ]);

        $submit->assertStatus(403);
        $submit->assertJsonFragment(['error' => __('ui.voice_cloning.plan_disabled')]);
    }

    public function test_pending_status_progress_moves_forward_and_stays_in_range(): void
    {
        $voice = ClonedVoice::factory()->create([
            'user_id' => $this->paidUser->id,
            'status' => 'pending',
        ]);
        $voice->forceFill(['created_at' => now()->subMinutes(3)])->save();

        $request = fn () => $this->actingAs($this->paidUser)
            ->withoutMiddleware([
                \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
                \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
                \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath::class,
            ])
            ->getJson($this->localizedRoute('voice-cloning.status', ['id' => $voice->id]));

        $first = $request();
        $second = $request();

        $first->assertOk();
        $second->assertOk();
        $firstProgress = (int) $first->json('progress_percent');
        $secondProgress = (int) $second->json('progress_percent');

        $this->assertGreaterThanOrEqual(0, (int) $first->json('elapsed_seconds'));
        $this->assertGreaterThanOrEqual(12, $firstProgress);
        $this->assertLessThanOrEqual(36, $firstProgress);
        $this->assertGreaterThanOrEqual($firstProgress, $secondProgress);
        $this->assertSame('pending', $first->json('status'));
    }

    public function test_sample_longer_than_thirty_seconds_is_rejected(): void
    {
        $file = UploadedFile::fake()->create('long.wav', 1024, 'audio/wav');

        $response = $this->actingAs($this->paidUser)
            ->postJsonLocalizedRoute('voice-cloning.store', [
                'name' => 'Long Voice',
                'language_code' => 'en-US',
                'source_type' => 'upload',
                'audio_file' => $file,
                'consent_audio' => UploadedFile::fake()->create('consent.wav', 800, 'audio/wav'),
                'consent_confirmed' => '1',
                'consent_duration' => 8,
                'audio_duration' => 45,
            ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('cloned_voices', 0);
    }

    public function test_consent_recording_is_required(): void
    {
        $file = UploadedFile::fake()->create('sample.wav', 1024, 'audio/wav');

        $response = $this->actingAs($this->paidUser)
            ->postJsonLocalizedRoute('voice-cloning.store', [
                'name' => 'Missing Consent Audio',
                'language_code' => 'ar-XA',
                'source_type' => 'upload',
                'audio_file' => $file,
                'consent_confirmed' => '1',
                'audio_duration' => 15,
                'consent_duration' => 8,
            ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('cloned_voices', 0);
    }

    /* ------------------------------------------------------------ */
    /*  Delete test                                                  */
    /* ------------------------------------------------------------ */

    public function test_user_can_delete_own_voice(): void
    {
        $voice = ClonedVoice::factory()->ready()->create([
            'user_id' => $this->paidUser->id,
            'source_audio_path' => 'voice-cloning/test/sample.wav',
        ]);

        $response = $this->actingAs($this->paidUser)
            ->withoutMiddleware([
                \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
                \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
                \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath::class,
                \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            ])
            ->deleteJson($this->localizedRoute('voice-cloning.destroy', ['id' => $voice->id]));

        $response->assertOk();
        $response->assertJson(['success' => true]);
        // Soft deleted
        $this->assertSoftDeleted('cloned_voices', ['id' => $voice->id]);
    }

    /* ------------------------------------------------------------ */
    /*  Status polling test                                          */
    /* ------------------------------------------------------------ */

    public function test_status_endpoint_returns_correct_data(): void
    {
        $voice = ClonedVoice::factory()->ready()->create([
            'user_id' => $this->paidUser->id,
            'name' => 'My Voice',
        ]);

        $response = $this->actingAs($this->paidUser)
            ->withoutMiddleware([
                \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
                \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
                \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath::class,
            ])
            ->getJson($this->localizedRoute('voice-cloning.status', ['id' => $voice->id]));

        $response->assertOk();
        $response->assertJson([
            'id' => $voice->id,
            'name' => 'My Voice',
            'status' => 'ready',
            'is_ready' => true,
        ]);
    }

    /* ------------------------------------------------------------ */
    /*  Feature toggle test                                          */
    /* ------------------------------------------------------------ */

    public function test_feature_disabled_blocks_submission(): void
    {
        \App\Models\SiteSetting::set('voice_cloning_enabled', '0');

        $file = UploadedFile::fake()->create('sample.wav', 1024, 'audio/wav');

        $response = $this->actingAs($this->paidUser)
            ->postJsonLocalizedRoute('voice-cloning.store', [
                'name' => 'Disabled Feature Voice',
                'language_code' => 'ar-XA',
                'source_type' => 'upload',
                'audio_file' => $file,
                'consent_audio' => UploadedFile::fake()->create('consent.wav', 800, 'audio/wav'),
                'consent_confirmed' => '1',
                'consent_duration' => 8,
                'audio_duration' => 15,
            ]);

        $response->assertStatus(403);
        $this->assertDatabaseCount('cloned_voices', 0);
    }

    /* ------------------------------------------------------------ */
    /*  Dashboard integration test                                   */
    /* ------------------------------------------------------------ */

    public function test_dashboard_shows_cloned_voices_for_paid_user(): void
    {
        ClonedVoice::factory()->ready()->create([
            'user_id' => $this->paidUser->id,
            'name' => 'My Custom Arabic Voice',
            'language_code' => 'ar-XA',
        ]);

        $response = $this->actingAs($this->paidUser)
            ->getLocalizedRoute('dashboard');

        $response->assertOk();
        // The cloned voice data should be present in the page's JS
        $response->assertSee('My Custom Arabic Voice');
        $response->assertSee('clonedVoiceGroupLabel');
    }

    public function test_failed_clone_does_not_use_up_the_plan_slot(): void
    {
        $this->paidPlan->update(['voice_cloning_max_voices' => 1]);
        ClonedVoice::factory()->failed()->create([
            'user_id' => $this->paidUser->id,
            'name' => 'Failed Take',
            'language_code' => 'ar-XA',
        ]);

        $dashboard = $this->actingAs($this->paidUser)
            ->getLocalizedRoute('dashboard');

        $dashboard->assertOk();
        $dashboard->assertSee(__('ui.voice_cloning.voice_count', ['count' => 0, 'max' => 1]), false);
        $dashboard->assertSee(__('ui.voice_cloning.create_title'), false);
        $html = $dashboard->getContent();
        $this->assertDoesNotMatchRegularExpression('/id="vcCreatePanel"\s+style="display:none"/', $html);

        Bus::fake([ProcessVoiceCloningJob::class]);
        $submit = $this->actingAs($this->paidUser)
            ->postJsonLocalizedRoute('voice-cloning.store', [
                'name' => 'Second Take',
                'language_code' => 'ar-XA',
                'source_type' => 'upload',
                'audio_file' => UploadedFile::fake()->create('sample.wav', 1024, 'audio/wav'),
                'consent_audio' => UploadedFile::fake()->create('consent.wav', 800, 'audio/wav'),
                'consent_confirmed' => '1',
                'consent_duration' => 8,
                'audio_duration' => 15,
            ]);

        $submit->assertOk();
        $submit->assertJson(['success' => true]);
    }

    public function test_failed_card_shows_a_friendly_message_and_keeps_the_raw_error_in_details(): void
    {
        ClonedVoice::factory()->failed()->create([
            'user_id' => $this->paidUser->id,
            'failure_reason' => 'Call to undefined function App\\Services\\shell_exec()',
            'metadata' => ['failure_code' => 'provider'],
        ]);

        $page = $this->actingAs($this->paidUser)->getLocalizedRoute('dashboard');

        $page->assertOk();
        $html = $page->getContent();
        $this->assertMatchesRegularExpression(
            '/class="vc-alert error vc-failure-hint"[^>]*>\s*'.preg_quote(e(__('ui.voice_cloning.processing_failed_generic')), '/').'\s*</',
            $html,
        );
        $this->assertMatchesRegularExpression('/<details class="vc-tech-detail">.*shell_exec/s', $html);
        $this->assertStringNotContainsString('data-failure-reason="Call to undefined', $html);
    }

    public function test_region_block_from_gemini_is_explained_and_can_be_retried(): void
    {
        config(['services.gemini_tts.api_key' => 'test-key']);
        Storage::disk('local')->put('voice-cloning/1/sample.wav', 'RIFF....WAVEfmt sample');
        Storage::disk('local')->put('voice-cloning/1/consent.wav', 'RIFF....WAVEfmt consent');

        \Illuminate\Support\Facades\Http::fake([
            'https://generativelanguage.googleapis.com/*' => \Illuminate\Support\Facades\Http::sequence()
                ->push(['error' => ['code' => 400, 'message' => 'User location is not supported for the API use.', 'status' => 'FAILED_PRECONDITION']], 400)
                ->push(['id' => 'voice_after_retry'], 200),
        ]);

        $voice = ClonedVoice::factory()->create([
            'user_id' => $this->paidUser->id,
            'provider' => 'gemini',
            'source_audio_path' => 'voice-cloning/1/sample.wav',
            'consent_audio_path' => 'voice-cloning/1/consent.wav',
            'source_audio_duration_seconds' => 15,
            'consent_confirmed' => true,
        ]);

        (new ProcessVoiceCloningJob($voice->id))->handle(app(VoiceCloningService::class));
        $voice->refresh();

        $this->assertSame('failed', $voice->status);
        $this->assertSame('region', data_get($voice->metadata, 'failure_code'));
        $this->assertSame(400, data_get($voice->metadata, 'http_status'));

        $page = $this->actingAs($this->paidUser)->getLocalizedRoute('dashboard');
        $page->assertSee(__('ui.voice_cloning.failure_region'), false);
        $page->assertSee(__('ui.voice_cloning.technical_details'), false);
        $page->assertSee('User location is not supported', false);
        $page->assertSee('vc-retry-btn', false);

        $status = $this->actingAs($this->paidUser)
            ->getJson($this->localizedRoute('voice-cloning.status', ['id' => $voice->id]));
        $status->assertJson(['status' => 'failed', 'can_retry' => true]);

        $retry = $this->actingAs($this->paidUser)
            ->postJsonLocalizedRoute('voice-cloning.retry', [], ['id' => $voice->id]);
        $retry->assertOk()->assertJson(['success' => true, 'voice' => ['status' => 'pending']]);

        $voice->refresh();
        $this->assertTrue($voice->isReady());
        $this->assertSame('voice_after_retry', $voice->provider_voice_key);
    }

    public function test_old_webm_recordings_cannot_be_retried(): void
    {
        $voice = ClonedVoice::factory()->failed()->create([
            'user_id' => $this->paidUser->id,
            'source_audio_path' => 'voice-cloning/1/old.webm',
            'consent_audio_path' => 'voice-cloning/1/old-consent.webm',
        ]);

        $this->actingAs($this->paidUser)
            ->postJsonLocalizedRoute('voice-cloning.retry', [], ['id' => $voice->id])
            ->assertStatus(422)
            ->assertJsonFragment(['error' => __('ui.voice_cloning.retry_not_possible')]);
    }

    public function test_retry_is_hidden_from_other_accounts(): void
    {
        $other = User::factory()->create();
        $voice = ClonedVoice::factory()->failed()->create([
            'user_id' => $other->id,
            'source_audio_path' => 'voice-cloning/2/a.wav',
            'consent_audio_path' => 'voice-cloning/2/b.wav',
        ]);

        $this->actingAs($other)
            ->postJsonLocalizedRoute('voice-cloning.retry', [], ['id' => $voice->id])
            ->assertNotFound();
    }

    public function test_diagnose_command_reports_the_gemini_reply(): void
    {
        config(['services.gemini_tts.api_key' => 'test-key']);
        \Illuminate\Support\Facades\Http::fake([
            'https://generativelanguage.googleapis.com/*' => \Illuminate\Support\Facades\Http::response(
                ['error' => ['message' => 'User location is not supported for the API use.']],
                400,
            ),
        ]);

        $this->artisan('voice-cloning:diagnose')
            ->expectsOutputToContain('Gemini API keys found: 1')
            ->expectsOutputToContain('User location is not supported')
            ->assertFailed();
    }

    public function test_ready_voice_offers_a_generator_button_with_that_speaker(): void
    {
        $voice = ClonedVoice::factory()->ready()->create([
            'user_id' => $this->paidUser->id,
            'name' => 'Walled Voice',
            'language_code' => 'ar-XA',
            'provider_voice_key' => 'voice_walled',
        ]);

        $page = $this->actingAs($this->paidUser)
            ->getLocalizedRoute('dashboard', ['cloned_voice' => $voice->id]);

        $page->assertOk();
        $page->assertSee(__('ui.voice_cloning.use_in_generator'), false);
        $page->assertSee('data-vc-action="use"', false);
        $page->assertSee('openClonedVoiceInGenerator', false);
        $page->assertSee('cloned_'.$voice->id, false);
        $page->assertSee('Walled Voice', false);
        $page->assertSee('cloned_voice='.$voice->id, false);
    }
}
