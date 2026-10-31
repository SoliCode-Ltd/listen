<?php

namespace Tests\Feature;

use App\Jobs\ProcessVoiceCloningJob;
use App\Models\ClonedVoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\VoiceCloningService;
use App\Support\VoiceCloningAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A member can delete a finished cloned voice (the person whose voice it is
 * can withdraw consent), but its plan slot comes back only after 30 days, so
 * deleting and cloning again can't make voice after voice.
 */
class ClonedVoiceSlotTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();

        Plan::factory()->free()->create(['slug' => 'free']);
        $plan = Plan::factory()->paid()->create([
            'slug' => 'creator',
            'price_cents' => 1999,
            'voice_tier' => 'hd',
            'voice_cloning_enabled' => true,
            'voice_cloning_max_voices' => 1,
        ]);
        $this->member = User::factory()->create(['email' => VoiceCloningAccess::PREVIEW_EMAIL]);
        Subscription::create(['user_id' => $this->member->id, 'plan_id' => $plan->id, 'status' => 'active']);

        Storage::fake('local');
        Storage::fake('public');
        Http::fake(['*' => Http::response([], 200)]);
        Bus::fake([ProcessVoiceCloningJob::class]);
    }

    public function test_a_deleted_finished_voice_keeps_its_slot_for_30_days(): void
    {
        $voice = ClonedVoice::factory()->ready()->create(['user_id' => $this->member->id, 'provider_voice_key' => 'voice_one']);

        $deleted = $this->deleteVoice($voice)->assertOk();

        $this->assertSoftDeleted('cloned_voices', ['id' => $voice->id]);
        $deleted->assertJson(['success' => true, 'slots_in_use' => 1]);
        $until = now()->addDays(30)->translatedFormat('j F Y');
        $this->assertSame(__('ui.voice_cloning.deleted_voice_counts', ['date' => $until]), $deleted->json('deleted_voice_note'));

        // Still at the plan's one voice: a new clone is refused, and the member is told until when.
        $this->createVoice()->assertStatus(422)->assertJsonFragment([
            'error' => __('ui.voice_cloning.limit_reached', ['max' => 1]).' '.__('ui.voice_cloning.deleted_voice_counts', ['date' => $until]),
        ]);
        $this->assertSame(0, ClonedVoice::query()->count());

        $this->travel(29)->days();
        $this->createVoice()->assertStatus(422);

        $this->travel(2)->days();
        $this->createVoice()->assertOk()->assertJson(['success' => true]);
        $this->assertSame(1, ClonedVoice::query()->count());
    }

    public function test_deleting_and_cloning_again_never_goes_past_the_plan_limit(): void
    {
        $plan = Plan::query()->where('slug', 'creator')->first();
        $plan->update(['voice_cloning_max_voices' => 2]);
        $first = ClonedVoice::factory()->ready()->create(['user_id' => $this->member->id, 'provider_voice_key' => 'voice_one']);

        // One slot is free: one more voice, and after deleting the first, none.
        $this->createVoice()->assertOk();
        $this->deleteVoice($first)->assertOk()->assertJson(['slots_in_use' => 2]);
        $this->createVoice()->assertStatus(422);
        $this->assertSame(2, VoiceCloningService::slotsInUse($this->member->id));
    }

    public function test_a_voice_that_never_finished_frees_its_slot_at_once(): void
    {
        foreach (['processing', 'failed'] as $status) {
            $voice = ClonedVoice::factory()->create(['user_id' => $this->member->id, 'status' => $status]);

            $this->deleteVoice($voice)->assertOk()->assertJson(['slots_in_use' => 0, 'deleted_voice_note' => '']);
        }

        $this->createVoice()->assertOk();
    }

    public function test_a_voice_an_admin_removes_frees_its_slot(): void
    {
        $voice = ClonedVoice::factory()->ready()->create(['user_id' => $this->member->id, 'provider_voice_key' => 'voice_one']);

        app(VoiceCloningService::class)->purge($voice);

        $this->assertSame(0, VoiceCloningService::slotsInUse($this->member->id));
        $this->createVoice()->assertOk();
    }

    public function test_the_studio_counts_the_deleted_voice_and_says_until_when(): void
    {
        $voice = ClonedVoice::factory()->ready()->create(['user_id' => $this->member->id, 'provider_voice_key' => 'voice_one']);
        $this->deleteVoice($voice)->assertOk();
        $until = now()->addDays(30);

        $page = $this->actingAs($this->member)->getLocalizedRoute('dashboard')->assertOk();
        $page->assertSee(__('ui.voice_cloning.voices_left', ['left' => 0, 'max' => 1]), false);
        $page->assertSee(__('ui.voice_cloning.deleted_voice_counts', ['date' => $until->translatedFormat('j F Y')]), false);
        $this->assertMatchesRegularExpression('/id="vcLimitNotice"(?!\s+style="display:none")/', $page->getContent());

        app()->setLocale('ar');
        $this->actingAs($this->member)->getLocalizedRoute('dashboard')->assertOk()
            ->assertSee(__('ui.voice_cloning.deleted_voice_counts', ['date' => $until->locale('ar')->translatedFormat('j F Y')]), false);
    }

    private function deleteVoice(ClonedVoice $voice): TestResponse
    {
        return $this->actingAs($this->member)
            ->withoutMiddleware([
                \Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect::class,
                \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter::class,
                \Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath::class,
                \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
            ])
            ->deleteJson($this->localizedRoute('voice-cloning.destroy', ['id' => $voice->id]));
    }

    private function createVoice(): TestResponse
    {
        return $this->actingAs($this->member)->postJsonLocalizedRoute('voice-cloning.store', [
            'name' => 'Another Voice',
            'language_code' => 'ar-XA',
            'source_type' => 'upload',
            'audio_file' => UploadedFile::fake()->create('sample.wav', 2048, 'audio/wav'),
            'consent_audio' => UploadedFile::fake()->create('consent.wav', 800, 'audio/wav'),
            'consent_confirmed' => '1',
            'consent_duration' => 8,
            'audio_duration' => 12,
        ]);
    }
}
