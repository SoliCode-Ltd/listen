<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\TranscriptionResource;
use App\Models\Transcription;
use App\Models\User;
use App\Services\Transcription\TranscriptionFlow;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Admin → Speech to text → Transcripts: every transcript with its member, minutes,
 * cost and wait, the figures above the list, a transcript's page with its text and
 * recording, and the recording for admins only.
 */
class TranscriptionsAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_the_list_its_figures_and_a_transcript_page_open(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $member = User::factory()->create(['email' => 'member@example.com']);
        $ready = $this->transcript($member, ['title' => 'Team call', 'status' => Transcription::READY, 'language' => 'ar', 'cost_usd' => 0.0123,
            'paragraphs' => [['speaker' => 'A', 'text' => 'هلا والله، شلونكم؟', 'start' => 0.0, 'end' => 2.4], ['speaker' => 'B', 'text' => 'كل شي تمام.', 'start' => 2.9, 'end' => 4.1]],
            'speaker_names' => ['A' => 'خديجة'], 'finished_at' => now(), 'notes' => ['times: 1 of 1 parts placed where heard']]);
        $failed = $this->transcript($member, ['title' => 'Quiet one', 'status' => Transcription::FAILED, 'charged_seconds' => 0, 'error_code' => 'no_speech', 'error' => 'We could not hear anyone speaking.']);
        $deleted = $this->transcript($member, ['title' => 'Gone', 'status' => Transcription::READY, 'finished_at' => now()]);
        $deleted->delete();

        $this->actingAs($admin)->get(TranscriptionResource::getUrl('index'))->assertOk()
            ->assertSee('Speech to text')->assertSee('Transcripts today')->assertSee('Cost to us')
            ->assertSee('member@example.com')->assertSee('Team call')->assertSee('Quiet one');

        $this->actingAs($admin)->get(TranscriptionResource::getUrl('view', ['record' => $ready]))->assertOk()
            ->assertSee('هلا والله، شلونكم؟')->assertSee('خديجة')->assertSee('$0.0123')->assertSee('Minutes used');
        $this->actingAs($admin)->get(TranscriptionResource::getUrl('view', ['record' => $failed]))->assertOk()
            ->assertSee('Why it failed')->assertSee('We could not hear anyone speaking.');
        $this->actingAs($admin)->get(TranscriptionResource::getUrl('view', ['record' => $deleted]))->assertOk()->assertSee('Gone');
    }

    public function test_the_list_opens_with_nothing_in_it(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]))->get(TranscriptionResource::getUrl('index'))->assertOk()->assertSee('Transcripts today');
    }

    public function test_a_stuck_transcript_is_stopped_and_its_minutes_given_back(): void
    {
        $t = $this->transcript(User::factory()->create(), ['status' => Transcription::PROCESSING, 'charged_seconds' => 90]);

        $this->assertTrue(app(TranscriptionFlow::class)->fail($t, 'admin', __('transcribe.failed.stuck')));

        $t->refresh();
        $this->assertSame(Transcription::FAILED, $t->status);
        $this->assertSame(0, $t->charged_seconds);
    }

    public function test_the_recording_plays_for_admins_only(): void
    {
        $member = User::factory()->create();
        $t = $this->transcript($member, ['status' => Transcription::READY, 'finished_at' => now()]);
        $path = TranscriptionFlow::folder($t).'/audio.mp3';
        Storage::disk('local')->put($path, 'ID3 test audio');
        $t->forceFill(['audio_path' => $path])->save();

        $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route('admin.transcripts.audio', ['code' => $t->code]))
            ->assertOk()->assertHeader('Content-Type', 'audio/mpeg');
        $this->actingAs($member)->get(route('admin.transcripts.audio', ['code' => $t->code]))->assertForbidden();

        TranscriptionResource::deleteAudio($t);
        $this->assertNotNull($t->fresh()->audio_deleted_at);
        $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route('admin.transcripts.audio', ['code' => $t->code]))->assertNotFound();
    }

    /** @param  array<string, mixed>  $values */
    private function transcript(User $user, array $values): Transcription
    {
        return Transcription::query()->create($values + ['user_id' => $user->id, 'title' => 'Recording', 'source' => 'upload', 'seconds' => 42.6,
            'charged_seconds' => 43, 'language_requested' => 'auto', 'period_ym' => now()->format('Y-m'), 'status' => Transcription::QUEUED]);
    }
}
