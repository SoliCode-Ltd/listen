<?php

namespace Tests\Feature;

use App\Models\AudioGeneration;
use App\Models\Plan;
use App\Models\User;
use App\Services\PlanAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The players in the history, the result dock and the demo (components/scripts):
 * a download that comes back as an error page is never saved as an ".mp3", and
 * a file that cannot be played or downloaded says so on its own row, in the
 * page's language.
 */
class AudioPlayerFailuresTest extends TestCase
{
    use RefreshDatabase;

    private function historyPage(string $locale): TestResponse
    {
        Storage::fake('public');
        Storage::disk('public')->put('audio/history.mp3', 'audio');
        $member = User::factory()->create(['subscription_status_checked_at' => now()]);
        app(PlanAccessService::class)->assignPlan($member, Plan::factory()->paid()->create(['slug' => 'creator', 'voice_tier' => 'hd']));
        AudioGeneration::query()->create([
            'user_id' => $member->id, 'input_text' => 'Hello there.', 'source_language' => 'en', 'target_language' => 'en',
            'voice_tier' => 'hd', 'voice_name' => 'en-Gemini-HD-Kore', 'translate' => false, 'status' => 'done',
            'audio_path' => 'audio/history.mp3', 'duration_seconds' => 3, 'billable_chars' => 12,
        ]);

        app()->setLocale($locale);

        return $this->actingAs($member)->getLocalizedRoute('dashboard')->assertOk()
            ->assertSee('src="'.\App\Support\AudioUrl::fromPath('audio/history.mp3').'"', false);
    }

    /** A string as @json writes it into the page's script. */
    private static function js(string $text): string
    {
        return json_encode($text, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    }

    public function test_a_download_that_is_not_audio_is_never_saved(): void
    {
        $this->historyPage('en')
            ->assertSee("return type === '' || /^(audio|video)\\//.test(type) || /^application\\/(octet-stream|ogg)/.test(type);", false)
            // The file the browser converts, and the types the server converts.
            ->assertSee("if (!response.ok || !isAudioResponse(response)) throw new Error('missing');", false)
            ->assertSee("if (!response.ok || !isAudioResponse(response)) throw new Error(response.status === 503 ? 'format' : 'missing');", false)
            ->assertSee("showNotice(i18n.audio_download_unavailable);", false)
            ->assertSee("showNotice(error && error.message === 'format' ? i18n.audio_download_failed : i18n.audio_download_unavailable);", false)
            ->assertDontSee('window.alert(i18n.audio_download_failed', false);
    }

    public function test_a_file_that_cannot_be_played_says_so_on_its_row(): void
    {
        $this->historyPage('en')
            ->assertSee("notice.className = 'custom-audio-notice';", false)
            ->assertSee("notice.setAttribute('role', 'status');", false)
            ->assertSee("wrapper.parentNode.insertBefore(notice, wrapper.nextSibling);", false)
            ->assertSee("if (!audio.getAttribute('src') || !audio.error || audio.error.code === 1) return;", false)
            ->assertSee("showNotice(i18n.audio_play_failed);", false)
            // Pressing play again tries the file again.
            ->assertSee('if (audio.error) audio.load();', false)
            ->assertSee('.custom-audio-notice:not(:empty) {', false)
            // Under a cloning preview (a row of its own) the note takes the next line.
            ->assertSee('.vc-playback.is-ready { display: flex; flex-wrap: wrap; }', false)
            ->assertSee('.vc-playback .custom-audio-notice:not(:empty) { flex-basis: 100%; text-align: center; }', false);
    }

    public function test_the_messages_are_in_the_page_language(): void
    {
        $page = $this->historyPage('ar');

        foreach (['audio_play_failed', 'audio_download_unavailable', 'audio_download_failed'] as $key) {
            $text = __('ui.scripts.'.$key, [], 'ar');
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', $text);
            $page->assertSee('"'.$key.'":'.self::js($text), false);
        }
    }
}
