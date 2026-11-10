<?php

namespace Tests\Feature;

use App\Models\AudioGeneration;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\TextItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The studio's history is one card per voice (what it says, who speaks it, the
 * player and its actions in view on any screen), not a table that has to be
 * slid sideways to reach the player and the buttons.
 */
class StudioHistoryCardsTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_voice_is_a_card_with_its_words_its_player_and_its_actions(): void
    {
        $user = $this->member();
        $named = $this->voice($user, 1, 'The first words of an unnamed voice are its title.', ['title' => 'Radio spot']);
        $unnamed = $this->voice($user, 2, 'The first words of an unnamed voice are its title.');
        $failed = $this->voice($user, 3, 'This one failed.', ['status' => 'failed', 'audio_path' => null, 'error_message' => 'Could not be made.']);
        $making = $this->voice($user, 4, 'This one is still being made.', ['status' => 'processing', 'audio_path' => null]);

        $html = $this->actingAs($user)->getLocalizedRoute('dashboard')->assertOk()->getContent();
        $history = $this->history($html);

        $this->assertStringContainsString('<ul class="hx-list" id="jobsTableBody">', $history);
        $this->assertStringNotContainsString('<table', $history, 'no table to slide sideways');
        $this->assertSame(4, substr_count($history, '<li class="hx-card"'));

        $card = fn (AudioGeneration $voice): string => $this->card($history, $voice);
        // Its name leads the card, else the start of its text.
        $this->assertStringContainsString('Radio spot', $card($named));
        $this->assertStringContainsString('The first words of an unnamed voice are its title.', $card($unnamed));
        // A finished voice: the player, a place for its download button, and delete.
        $this->assertStringContainsString('<div class="hx-player">', $card($named));
        $this->assertStringContainsString('data-hx-download', $card($named));
        $this->assertStringContainsString('data-action="delete-job"', $card($named));
        $this->assertStringContainsString('aria-label="'.__('ui.dashboard.delete').'"', $card($named));
        // One that failed says why; one being made says so; neither has a player.
        $this->assertStringContainsString('Could not be made.', $card($failed));
        $this->assertStringNotContainsString('hx-player', $card($failed));
        $this->assertStringContainsString(__('ui.dashboard.history_making'), $card($making));
        $this->assertStringNotContainsString('hx-player', $card($making));
    }

    public function test_a_long_history_is_drawn_ten_cards_at_a_time(): void
    {
        $user = $this->member();
        for ($n = 1; $n <= 13; $n++) {
            $this->voice($user, $n, "Voice number {$n}.");
        }

        $html = $this->actingAs($user)->getLocalizedRoute('dashboard')->assertOk()->getContent();
        $history = $this->history($html);

        // Every voice is known to the page, but only the first ten are drawn whole: the others
        // carry their data alone until their page is opened.
        $this->assertSame(13, substr_count($history, 'data-job-data='));
        $this->assertSame(10, substr_count($history, '<div class="hx-player">'));
        $this->assertSame(3, preg_match_all('#<li class="hx-card"[^>]*\shidden\s#', $history));
        // The page shows ten at a time and keeps its page numbers.
        $this->assertStringContainsString('const jobsPerPage = 10;', $html);
        $this->assertStringContainsString('id="jobsPagination"', $history);
        $this->assertStringContainsString("jobsTableBody.querySelectorAll('[data-job-data]')", $html);
    }

    public function test_the_page_draws_the_same_card_and_puts_the_download_button_with_the_actions(): void
    {
        $html = $this->actingAs($this->member())->getLocalizedRoute('dashboard')->assertOk()->getContent();

        $this->assertStringContainsString("return '<li class=\"hx-card\" data-job-status=\"' + statusClass", $html);
        $this->assertStringContainsString('function placeHistoryDownloads()', $html);
        $this->assertStringContainsString("card.querySelector('.hx-player .custom-audio-menu')", $html);
        // No row of a table is drawn or looked for any more.
        $this->assertStringNotContainsString("'<tr data-job-key", $html);
        $this->assertStringNotContainsString("querySelector('tr[data-job-key", $html);
        $this->assertStringContainsString('<li id="jobsEmptyRow" class="hx-empty">', $html, 'an empty history says so');
    }

    public function test_a_voice_sent_to_the_page_later_carries_the_start_of_its_text(): void
    {
        $user = $this->member();
        $text = str_repeat('A long text for a voice. ', 12);
        $voice = $this->voice($user, 1, $text);

        $job = $this->actingAs($user)->withHeaders(['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])
            ->getLocalizedRoute('dashboard.jobs.show', ['job' => $voice->id])->assertOk()->json('job');

        $this->assertSame(42, mb_strlen($job['text_excerpt']), 'the file name keeps its short start');
        $this->assertStringStartsWith('A long text for a voice. A long text', $job['text_preview']);
        $this->assertStringEndsWith('…', $job['text_preview']);
        $this->assertLessThanOrEqual(111, mb_strlen($job['text_preview']));
    }

    private function history(string $html): string
    {
        $from = strpos($html, 'id="recentVoicesSection"');
        $to = strpos($html, '</section>', $from);
        $this->assertNotFalse($from);

        return substr($html, $from, $to - $from);
    }

    private function card(string $history, AudioGeneration $voice): string
    {
        $this->assertSame(1, preg_match('#<li class="hx-card" data-job-id="'.$voice->id.'".*?</li>#s', $history, $found), "the card of voice {$voice->id}");

        return (string) preg_replace('#data-job-data="[^"]*"#', '', $found[0]);
    }

    private function member(): User
    {
        $plan = Plan::factory()->create(['voice_tier' => 'standard', 'is_active' => true, 'monthly_chars' => 100000, 'max_chars_per_request' => 5000, 'monthly_requests' => 0]);
        $user = User::factory()->create(['subscription_status_checked_at' => now()]);
        Subscription::query()->create(['user_id' => $user->id, 'plan_id' => $plan->id, 'status' => 'active']);

        return $user;
    }

    /** @param array<string, mixed> $with */
    private function voice(User $user, int $number, string $text, array $with = []): AudioGeneration
    {
        $item = isset($with['title']) ? TextItem::create(['user_id' => $user->id, 'source_type' => 'message', 'title' => $with['title'], 'content' => $text, 'status' => 'done']) : null;
        unset($with['title']);

        return AudioGeneration::query()->create(array_merge([
            'user_id' => $user->id, 'text_item_id' => $item?->id, 'user_sequence_number' => $number, 'input_text' => $text, 'source_language' => 'en',
            'target_language' => 'en', 'voice_tier' => 'standard', 'voice_name' => 'en-US-Wavenet-C', 'status' => 'done',
            'audio_path' => "audio/voice-{$number}.mp3",
        ], $with));
    }
}
