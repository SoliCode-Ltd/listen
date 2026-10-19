<?php

namespace Tests\Feature;

use App\Models\AudioGeneration;
use App\Models\DemoVoiceGeneration;
use App\Models\Plan;
use App\Models\TextItem;
use App\Models\User;
use App\Support\VoiceCatalog;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Tests\Feature\Api\InteractsWithDeveloperApi;
use Tests\TestCase;

/**
 * Deleting a voice deletes its audio. Anything on the public disk plays for
 * whoever has its address, so the voice's file, the speech cache's copy made
 * from the take and its text go with it, in the studio, the developer API and
 * the demo. A file another voice still plays stays, and a cache copy that is
 * gone is made again by the engine instead of being pointed at.
 */
class DeletedVoiceAudioTest extends TestCase
{
    use InteractsWithDeveloperApi;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpDeveloperApi();
    }

    public function test_deleting_a_studio_voice_deletes_its_audio_its_cache_copy_and_its_text(): void
    {
        $user = $this->apiMember();
        $this->studio($user, ['content' => 'Words that go when the voice goes.'])->assertOk();
        $voice = AudioGeneration::query()->where('user_id', $user->id)->sole();
        $files = [$voice->audio_path, $voice->settings_snapshot['cache_copy'] ?? null];
        $this->assertNotNull($files[1], 'the take made the speech cache\'s copy');
        foreach ($files as $path) {
            $this->get('/media/'.$path)->assertOk();
        }

        $this->deleteVoice($user, $voice)->assertOk()->assertJsonPath('deleted_id', $voice->id);

        foreach ($files as $path) {
            Storage::disk('public')->assertMissing($path);
            $this->get('/media/'.$path)->assertNotFound();
        }
        $this->assertNull(AudioGeneration::query()->find($voice->id));
        $this->assertNull(TextItem::query()->find($voice->text_item_id), 'its text goes too');
    }

    public function test_deleting_through_the_developer_api_deletes_its_audio_too(): void
    {
        $user = $this->apiMember();
        $plain = $this->issueKey($user)['plain'];
        $voice = AudioGeneration::query()->findOrFail((int) $this->speak($plain, $this->premiumVoice())->assertOk()->headers->get('X-History-Item-Id'));
        $files = [$voice->audio_path, $voice->settings_snapshot['cache_copy'] ?? null];
        $this->assertNotNull($files[1]);

        $this->apiDelete($plain, '/history/'.$voice->id)->assertOk();

        foreach ($files as $path) {
            Storage::disk('public')->assertMissing($path);
            $this->get('/media/'.$path)->assertNotFound();
        }
        $this->assertNull(TextItem::query()->find($voice->text_item_id));
    }

    /**
     * An identical developer API request is answered from the saved copy, so that
     * copy is the second voice's audio: it stays until that voice is deleted too,
     * and the next identical request is made again by the engine.
     */
    public function test_a_shared_cache_copy_stays_while_a_voice_plays_it_and_is_made_again_once_gone(): void
    {
        $user = $this->apiMember();
        $plain = $this->issueKey($user)['plain'];
        $voice = $this->premiumVoice();
        $make = fn (): AudioGeneration => AudioGeneration::query()->findOrFail(
            (int) $this->speak($plain, $voice, ['text' => 'The same words, sent again.'])->assertOk()->headers->get('X-History-Item-Id'),
        );
        $first = $make();
        $second = $make();
        $copy = $first->settings_snapshot['cache_copy'];
        $this->assertSame($copy, $second->audio_path, 'the second is the saved copy');
        $this->assertSame(1, $this->speechCalls->gemini);

        $this->apiDelete($plain, '/history/'.$first->id)->assertOk();

        Storage::disk('public')->assertMissing($first->audio_path);
        $this->get('/media/'.$copy)->assertOk();
        $this->apiGet($plain, '/history/'.$second->id.'/audio')->assertOk();

        $this->apiDelete($plain, '/history/'.$second->id)->assertOk();
        $this->get('/media/'.$copy)->assertNotFound();

        $third = $make();
        $this->assertSame(2, $this->speechCalls->gemini, 'made again, not pointed at a file that is gone');
        $this->assertSame('done', $third->status);
        $this->get('/media/'.$third->audio_path)->assertOk();
    }

    public function test_a_file_or_text_another_voice_still_uses_is_kept(): void
    {
        $user = $this->apiMember();
        $kept = $this->storedGeneration($user, ['text_item_id' => TextItem::query()->create([
            'user_id' => $user->id, 'source_type' => 'message', 'content' => 'Hello from the archive.', 'status' => 'done',
        ])->id]);
        // Before every take was a new one, identical studio texts shared one saved copy.
        $deleted = $this->storedGeneration($user, ['audio_path' => $kept->audio_path, 'text_item_id' => $kept->text_item_id]);
        // And a demo voice of the history may play the same file as another.
        $demoFile = 'audio/demo/20261019_120000_shared.mp3';
        Storage::disk('public')->put($demoFile, 'demo-audio');
        DemoVoiceGeneration::query()->create([
            'user_id' => $user->id, 'primary_identity_hash' => 'u:'.$user->id, 'input_text' => 'Hello', 'target_language' => 'en',
            'audio_path' => $demoFile, 'billable_chars' => 5,
        ]);
        $alsoDeleted = $this->storedGeneration($user, ['audio_path' => $demoFile]);

        $this->deleteVoice($user, $deleted)->assertOk();
        $this->deleteVoice($user, $alsoDeleted)->assertOk();

        $this->get('/media/'.$kept->audio_path)->assertOk();
        $this->get('/media/'.$demoFile)->assertOk();
        $this->assertNotNull(TextItem::query()->find($kept->text_item_id));
        $this->assertNotNull($kept->fresh());
    }

    public function test_with_a_sound_environment_the_clean_take_is_not_kept_and_deleting_takes_the_rest(): void
    {
        $user = $this->apiMember($this->apiPlan(['sound_effects_enabled' => true]));
        $this->studio($user, ['content' => 'A voice in a big hall.', 'audio_effect' => 'hall'])->assertOk();
        $voice = AudioGeneration::query()->where('user_id', $user->id)->sole();
        $this->assertStringStartsWith('audio/effects/', $voice->audio_path);
        // The effected file is named after the clean one, so the clean take's address could be worked out.
        $clean = 'audio/'.preg_replace('/-hall-[0-9a-f]{8}$/', '', pathinfo($voice->audio_path, PATHINFO_FILENAME)).'.'.pathinfo($voice->audio_path, PATHINFO_EXTENSION);
        $this->get('/media/'.$clean)->assertNotFound();

        $this->deleteVoice($user, $voice)->assertOk();

        $this->get('/media/'.$voice->audio_path)->assertNotFound();
        $this->get('/media/'.$voice->settings_snapshot['cache_copy'])->assertNotFound();
    }

    public function test_a_deleted_demo_voice_takes_the_cache_copy_it_made_and_keeps_only_its_characters(): void
    {
        Plan::factory()->create(['slug' => 'free', 'price_cents' => 0, 'voice_tier' => 'standard', 'is_active' => true, 'monthly_requests' => 0]);
        $member = User::factory()->create(['subscription_status_checked_at' => now()]);
        $this->actingAs($member)->postJsonLocalizedRoute('demo.preview', [
            'text' => 'A demo voice, deleted later.',
            'target_language' => 'en',
            'voice_tier' => 'hd',
            'voice_key' => VoiceCatalog::keyFromId((int) $this->premiumVoice()->id),
        ])->assertOk()->assertJsonPath('ok', true);
        $demo = DemoVoiceGeneration::query()->sole();
        $demo->forceFill(['style_prompt' => 'Read it softly.'])->save();
        $files = [$demo->audio_path, $demo->metadata['cache_copy'] ?? null];
        $this->assertNotNull($files[1]);

        $this->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class, LaravelLocalizationViewPath::class, ValidateCsrfToken::class])
            ->deleteJson($this->localizedRoute('dashboard.demo-jobs.destroy', ['generation' => $demo->id]))
            ->assertOk();

        foreach ($files as $path) {
            $this->get('/media/'.$path)->assertNotFound();
        }
        $kept = $demo->fresh();
        $this->assertSame($demo->billable_chars, $kept->billable_chars, 'its characters stay counted');
        $this->assertSame('', $kept->input_text);
        $this->assertNull($kept->speech_text);
        $this->assertNull($kept->style_prompt);
    }

    /** @param  array<string, mixed>  $input */
    private function studio(User $user, array $input): TestResponse
    {
        return $this->actingAs($user)->postJsonLocalizedRoute('dashboard.queue', $input + [
            'target_language' => 'en',
            'voice_tier' => 'hd',
            'voice_key' => VoiceCatalog::keyFromId((int) $this->premiumVoice()->id),
        ]);
    }

    private function deleteVoice(User $user, AudioGeneration $voice): TestResponse
    {
        return $this->actingAs($user)
            ->withoutMiddleware([LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class, LaravelLocalizationViewPath::class, ValidateCsrfToken::class])
            ->deleteJson($this->localizedRoute('dashboard.jobs.destroy', ['job' => $voice->id]));
    }
}
