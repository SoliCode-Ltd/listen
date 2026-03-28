<?php

namespace Tests\Feature;

use App\Jobs\ProcessVoiceCloningJob;
use App\Models\ClonedVoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\VoiceCloningService;
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

        $this->paidUser = User::factory()->create();
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

    public function test_free_user_voice_cloning_route_redirects_to_dashboard_section_with_upgrade_prompt(): void
    {
        $response = $this->actingAs($this->freeUser)->getLocalizedRoute('voice-cloning.index');

        $response->assertRedirect(route('dashboard') . '#voiceCloningSection');

        $page = $this->actingAs($this->freeUser)
            ->followingRedirects()
            ->getLocalizedRoute('voice-cloning.index');

        $page->assertOk();
        $page->assertSee(__('ui.voice_cloning.upgrade_title'));
    }

    public function test_paid_user_voice_cloning_route_redirects_to_dashboard_section(): void
    {
        $response = $this->actingAs($this->paidUser)->getLocalizedRoute('voice-cloning.index');

        $response->assertRedirect(route('dashboard') . '#voiceCloningSection');

        $page = $this->actingAs($this->paidUser)
            ->followingRedirects()
            ->getLocalizedRoute('voice-cloning.index');

        $page->assertOk();
        $page->assertSee(__('ui.voice_cloning.page_title'));
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
                'consent_confirmed' => '1',
                'audio_duration' => 15,
            ]);

        $response->assertStatus(403);
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
                'consent_confirmed' => '1',
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
            'consent_confirmed' => true,
        ]);

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
                'consent_confirmed' => '1',
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
                'consent_confirmed' => '1',
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
                'consent_confirmed' => '1',
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
                'consent_confirmed' => '1',
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
                'consent_confirmed' => '1',
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
                'consent_confirmed' => '1',
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

        $file = UploadedFile::fake()->create('sample.wav', 1024, 'audio/wav');

        $response = $this->actingAs($this->paidUser)
            ->postJsonLocalizedRoute('voice-cloning.store', [
                'name' => 'Third Voice',
                'language_code' => 'ar-XA',
                'source_type' => 'upload',
                'audio_file' => $file,
                'consent_confirmed' => '1',
                'audio_duration' => 15,
            ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['error' => __('ui.voice_cloning.limit_reached', ['max' => 2])]);
    }

    public function test_plan_max_zero_is_reflected_exactly_on_dashboard_and_enforced(): void
    {
        $this->paidPlan->update([
            'voice_cloning_enabled' => true,
            'voice_cloning_max_voices' => 0,
        ]);

        $dashboard = $this->actingAs($this->paidUser)
            ->getLocalizedRoute('dashboard');

        $dashboard->assertOk();
        $dashboard->assertSee(__('ui.voice_cloning.voice_count', ['count' => 0, 'max' => 0]));

        $file = UploadedFile::fake()->create('sample.wav', 1024, 'audio/wav');
        $submit = $this->actingAs($this->paidUser)
            ->postJsonLocalizedRoute('voice-cloning.store', [
                'name' => 'No Slot Voice',
                'language_code' => 'ar-XA',
                'source_type' => 'upload',
                'audio_file' => $file,
                'consent_confirmed' => '1',
                'audio_duration' => 15,
            ]);

        $submit->assertStatus(422);
        $submit->assertJsonFragment(['error' => __('ui.voice_cloning.limit_reached', ['max' => 0])]);
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
                'consent_confirmed' => '1',
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
}
