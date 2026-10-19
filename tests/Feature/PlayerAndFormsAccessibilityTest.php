<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\VoiceCloningAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Keyboard and screen reader use: the audio player can be played, paused and
 * moved with keys and says what it does; the text boxes and file pickers have
 * names; and messages that appear (errors, a finished voice, a cloned voice
 * that is ready) are announced. Names are in the page's language.
 */
class PlayerAndFormsAccessibilityTest extends TestCase
{
    use RefreshDatabase;

    private function studioPage(string $locale = 'en'): TestResponse
    {
        Plan::factory()->free()->create(['slug' => 'free']);
        $plan = Plan::factory()->paid()->create([
            'slug' => 'creator', 'voice_tier' => 'hd', 'voice_cloning_enabled' => true, 'voice_cloning_max_voices' => 5,
        ]);
        $member = User::factory()->create(['email' => VoiceCloningAccess::PREVIEW_EMAIL]);
        Subscription::create(['user_id' => $member->id, 'plan_id' => $plan->id, 'status' => 'active']);

        app()->setLocale($locale);

        return $this->actingAs($member)->getLocalizedRoute('dashboard')->assertOk();
    }

    /** A string as @json writes it into the page's script. */
    private static function js(string $text): string
    {
        return json_encode($text, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    }

    public function test_the_player_works_from_the_keyboard(): void
    {
        $this->studioPage()
            // The position bar is a slider that takes focus.
            ->assertSee('progContainer.tabIndex = 0;', false)
            ->assertSee("progContainer.setAttribute('role', 'slider');", false)
            ->assertSee("progContainer.setAttribute('aria-label', i18n.audio_seek);", false)
            ->assertSee("progContainer.addEventListener('keydown', function(e) {", false)
            ->assertSee("if (e.key === ' ' || e.key === 'Enter') {", false)
            ->assertSee('var step = { ArrowRight: 5, ArrowUp: 5, ArrowLeft: -5, ArrowDown: -5 }[e.key];', false)
            ->assertSee("var target = e.key === 'Home' ? 0 : (e.key === 'End' ? d : Number(audio.currentTime || 0) + step);", false)
            // The download menu closes with Escape.
            ->assertSee("if (ev.key !== 'Escape') return;", false)
            // Focus is always visible on its controls.
            ->assertSee('.custom-audio-play:focus-visible,', false)
            ->assertSee('.custom-audio-progress-container:focus-visible {', false);
    }

    public function test_the_player_says_what_it_does_in_the_page_language(): void
    {
        $page = $this->studioPage('ar')
            ->assertSee("playBtn.setAttribute('aria-label', isPlaying ? i18n.audio_pause : i18n.audio_play);", false)
            ->assertSee("progContainer.setAttribute('aria-valuetext', String(i18n.audio_position).replace(':current', formatTime(current)).replace(':total', formatTime(duration)));", false);

        foreach (['audio_play', 'audio_pause', 'audio_seek', 'audio_position'] as $key) {
            $text = __('ui.scripts.'.$key, [], 'ar');
            $this->assertMatchesRegularExpression('/\p{Arabic}/u', $text);
            $page->assertSee('"'.$key.'":'.self::js($text), false);
        }
    }

    public function test_the_text_boxes_and_file_pickers_have_names(): void
    {
        $this->studioPage('ar')
            ->assertSee('id="contentTextarea"', false)
            ->assertSee('aria-label="'.e(__('ui.dashboard.text_box_label', [], 'ar')).'"', false)
            ->assertSee('id="vcFileInput" accept=".wav,.mp3,.m4a,.mp4,.ogg,.webm,.flac,audio/*" aria-label="'.e(__('ui.voice_cloning.upload_label', [], 'ar')).'"', false)
            // The × beside a chosen file is a real button with a name.
            ->assertSee('<button type="button" class="remove" id="vcFileRemove" aria-label="'.e(__('ui.voice_cloning.remove_file', [], 'ar')).'">&times;</button>', false)
            ->assertSee('<button type="button" class="remove" id="vcConsentFileRemove" aria-label="'.e(__('ui.voice_cloning.remove_file', [], 'ar')).'">&times;</button>', false);

        $this->actingAs(User::factory()->create())->getLocalizedRoute('demo.page')
            ->assertOk()
            ->assertSee('placeholder="'.e(__('ui.dashboard.paste_text')).'" aria-label="'.e(__('ui.dashboard.text_box_label')).'"', false);
    }

    public function test_messages_that_appear_are_announced(): void
    {
        $this->studioPage()
            ->assertSee('<div id="formMessage" role="status"></div>', false)
            ->assertSee('<div class="audio-meta" id="generatedAudioMeta" role="status"></div>', false)
            // A cloned voice's status is spoken when it changes, not on every check.
            ->assertSee('<span class="vc-status ${escapeHtml(status)}" role="status">', false)
            ->assertSee("if (statusBadge && (statusBadge.textContent.trim() !== statusText || !statusBadge.classList.contains(status))) {", false);

        $this->actingAs(User::factory()->create())->getLocalizedRoute('demo.page')
            ->assertOk()
            ->assertSee('<div class="audio-meta" id="audioMeta" role="status"></div>', false);

        $member = User::factory()->create();
        $this->actingAs($member)->getLocalizedRoute('browsers-extension.page')
            ->assertOk()
            ->assertSee('<div id="messageBox" role="status"></div>', false);
        $billing = $this->actingAs($member)->getLocalizedRoute('billing.page')
            ->assertOk()
            ->assertSee('<div id="card-error" class="card-error" aria-live="polite"></div>', false);
        $this->assertMatchesRegularExpression('/<div id="message" class="message[^"]*" role="status">/', $billing->getContent());
    }
}
