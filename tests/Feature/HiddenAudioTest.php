<?php

namespace Tests\Feature;

use App\Filament\Resources\AudioGenerationResource\Pages\ListAudioGenerations;
use App\Models\AudioGeneration;
use App\Models\User;
use App\Support\AudioUrl;
use App\Support\CreditPeriod;
use App\Support\HiddenAudio;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationRedirectFilter;
use Mcamara\LaravelLocalization\Middleware\LaravelLocalizationViewPath;
use Mcamara\LaravelLocalization\Middleware\LocaleSessionRedirect;
use Tests\Feature\Api\InteractsWithDeveloperApi;
use Tests\TestCase;

/**
 * A voice an admin hides from its member (Audio Generations → Hide from member,
 * App\Support\HiddenAudio): the member cannot list, play, download or delete it, in the
 * studio or through the developer API, and its address stops working; the row, its
 * text and its file stay for the admin. Showing it again undoes all of that.
 */
class HiddenAudioTest extends TestCase
{
    use InteractsWithDeveloperApi;
    use RefreshDatabase;

    /** The studio's addresses carry the language; the tests ask for them directly. */
    private const LOCALE_REDIRECTS = [LocaleSessionRedirect::class, LaravelLocalizationRedirectFilter::class, LaravelLocalizationViewPath::class];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->setUpDeveloperApi();
        HiddenAudio::forgetReady();
    }

    protected function tearDown(): void
    {
        HiddenAudio::forgetReady();

        parent::tearDown();
    }

    public function test_a_hidden_voice_is_gone_for_the_member_and_kept_for_the_admin(): void
    {
        $user = $this->apiMember();
        $admin = User::factory()->create(['is_admin' => true]);
        $kept = $this->storedGeneration($user, ['input_text' => 'An ordinary voice.']);
        $hidden = $this->storedGeneration($user, ['input_text' => 'A voice the admin hides.']);
        $path = (string) $hidden->audio_path;
        $spentBefore = (int) CreditPeriod::counter((int) $user->id)->total_chars;

        $this->assertTrue(HiddenAudio::hide($hidden, (int) $admin->id, 'Sexual content'));

        // The file left the public disk (its address serves nothing) and is kept privately.
        Storage::disk('public')->assertMissing($path);
        Storage::disk('local')->assertExists('hidden-audio/'.$hidden->id.'/'.basename($path));
        $this->get(AudioUrl::fromPath($path))->assertNotFound();
        $this->get(AudioUrl::fromPath((string) $kept->audio_path))->assertOk();

        // The studio: not listed, not deletable.
        $this->assertSame([(int) $kept->id], $this->studioHistory($user));
        $this->actingAs($user)->withoutMiddleware(self::LOCALE_REDIRECTS)
            ->deleteJson($this->localizedRoute('dashboard.jobs.destroy', ['job' => $hidden->id]))->assertNotFound();
        $this->assertNotNull(AudioGeneration::query()->find($hidden->id), 'the row stays');

        // The developer API: not listed, not found by its id.
        $plain = $this->issueKey($user)['plain'];
        $this->assertSame([(int) $kept->id], array_column($this->apiGet($plain, '/history')->assertOk()->json('history'), 'history_item_id'));
        $this->apiGet($plain, '/history/'.$hidden->id)->assertNotFound();
        $this->apiDelete($plain, '/history/'.$hidden->id)->assertNotFound();

        // The row keeps everything, and nothing is given back.
        $hidden->refresh();
        $this->assertSame('A voice the admin hides.', $hidden->input_text);
        $this->assertSame($path, $hidden->audio_path);
        $this->assertSame('Sexual content', $hidden->hidden_note);
        $this->assertSame((int) $admin->id, (int) $hidden->hidden_by);
        $this->assertSame($spentBefore, (int) CreditPeriod::counter((int) $user->id)->total_chars);

        // Only an admin plays the kept file.
        auth()->logout();
        $this->actingAs($user)->get(route('admin.hidden-audio', ['generation' => $hidden->id]))->assertForbidden();
        $this->actingAs($admin)->get(route('admin.hidden-audio', ['generation' => $hidden->id]))->assertOk();
        $this->actingAs($admin)->get(route('admin.hidden-audio', ['generation' => $kept->id]))->assertNotFound();
    }

    public function test_showing_it_again_puts_everything_back(): void
    {
        $user = $this->apiMember();
        $generation = $this->storedGeneration($user);
        $path = (string) $generation->audio_path;
        HiddenAudio::hide($generation, null, null);

        $this->assertTrue(HiddenAudio::show($generation));

        Storage::disk('public')->assertExists($path);
        Storage::disk('local')->assertMissing('hidden-audio/'.$generation->id.'/'.basename($path));
        $this->assertNull($generation->fresh()->hidden_at);
        $this->get(AudioUrl::fromPath($path))->assertOk();
        $this->assertSame([(int) $generation->id], $this->studioHistory($user));
    }

    public function test_a_file_another_voice_still_plays_stays_where_it_is(): void
    {
        $plan = $this->apiPlan();
        $user = $this->apiMember($plan);
        $other = $this->apiMember($plan);
        $first = $this->storedGeneration($user);
        $shared = $this->storedGeneration($other, ['audio_path' => $first->audio_path]);

        HiddenAudio::hide($first);

        Storage::disk('public')->assertExists((string) $shared->audio_path);
        Storage::disk('local')->assertExists(HiddenAudio::privatePath($first));

        // Once the last one that plays it is hidden too, the file leaves the public disk.
        HiddenAudio::hide($shared);
        Storage::disk('public')->assertMissing((string) $shared->audio_path);
    }

    public function test_an_admin_hides_and_shows_a_voice_from_the_audio_generations_list(): void
    {
        $user = $this->apiMember();
        $generation = $this->storedGeneration($user);
        $second = $this->storedGeneration($user);
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(ListAudioGenerations::class)
            ->assertTableActionVisible('hideFromMember', $generation)
            ->assertTableActionHidden('showToMember', $generation)
            ->callTableAction('hideFromMember', $generation, ['note' => 'Bad words'])
            ->assertHasNoTableActionErrors()
            ->assertTableActionHidden('hideFromMember', $generation)
            ->assertTableActionVisible('showToMember', $generation)
            ->assertSee('Hidden');

        $generation->refresh();
        $this->assertNotNull($generation->hidden_at);
        $this->assertSame((int) $admin->id, (int) $generation->hidden_by);
        $this->assertSame('Bad words', $generation->hidden_note);

        Livewire::test(ListAudioGenerations::class)
            ->filterTable('hidden_at', true)
            ->assertCanSeeTableRecords([$generation])
            ->assertCanNotSeeTableRecords([$second])
            ->callTableAction('showToMember', $generation);
        $this->assertNull($generation->fresh()->hidden_at);

        // Several at once.
        Livewire::test(ListAudioGenerations::class)
            ->callTableBulkAction('hideFromMembers', [$generation, $second], ['note' => 'Both']);
        $this->assertSame(2, AudioGeneration::query()->whereNotNull('hidden_at')->count());
    }

    public function test_a_hidden_voices_kept_file_goes_when_an_admin_deletes_the_row(): void
    {
        $generation = $this->storedGeneration($this->apiMember());
        HiddenAudio::hide($generation);
        $private = HiddenAudio::privatePath($generation);
        Storage::disk('local')->assertExists($private);

        $generation->delete();

        Storage::disk('local')->assertMissing($private);
    }

    /**
     * The ids the studio lists for the member, newest first.
     *
     * @return list<int>
     */
    private function studioHistory(User $user): array
    {
        $jobs = $this->actingAs($user)->withoutMiddleware(self::LOCALE_REDIRECTS)
            ->getJson($this->localizedRoute('dashboard.jobs.latest'))->assertOk()->json('jobs');

        return array_map('intval', array_column($jobs, 'id'));
    }

    /** A deploy runs before its migration: until the columns exist, every list reads as before. */
    public function test_before_the_migration_nothing_is_hidden_and_the_lists_still_work(): void
    {
        $user = $this->apiMember();
        $generation = $this->storedGeneration($user);
        Schema::table('audio_generations', fn ($table) => $table->dropIndex('audio_generations_content_check_index'));
        Schema::dropColumns('audio_generations', ['hidden_at', 'hidden_by', 'hidden_note', 'content_check']);
        HiddenAudio::forgetReady();

        $this->assertFalse(HiddenAudio::ready());
        $this->assertFalse(HiddenAudio::hide($generation));
        $this->assertSame([(int) $generation->id], $this->studioHistory($user));
    }
}
