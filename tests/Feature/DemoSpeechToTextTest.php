<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\Transcription;
use App\Models\User;
use App\Support\Transcription\TranscriptionSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The demo's Speech to text tab: there once speech to text is open to everyone, as the studio's
 * own tool with its free try, and the free try counted for the person (a second account in the
 * same browser sees it used).
 */
class DemoSpeechToTextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Plan::factory()->create(['slug' => 'starter', 'price_cents' => 900, 'is_active' => true,
            'transcription_enabled' => true, 'transcription_minutes_month' => 120, 'transcription_max_file_minutes' => 30]);
    }

    public function test_the_demo_has_the_tab_and_the_tool_once_speech_to_text_is_public(): void
    {
        $member = User::factory()->create(['email_verified_at' => now()]);
        $this->actingAs($member)->getLocalizedRoute('demo.page')->assertOk()
            ->assertDontSee('data-studio-tab="stt"', false);

        SiteSetting::set('transcription_audience', TranscriptionSettings::AUDIENCE_EVERYONE, TranscriptionSettings::GROUP);
        $page = $this->actingAs($member)->getLocalizedRoute('demo.page')->assertOk();
        $page->assertSee('data-studio-tab="stt"', false)
            ->assertSee('class="tr-embed" data-studio-pane="stt"', false)
            ->assertSee('js/transcribe.js', false)
            ->assertSee('css/transcribe.css', false)
            ->assertSee(__('transcribe.demo.tab'));
        // One heading for the page: the tool's title is the pane's.
        $this->assertSame(1, substr_count((string) $page->getContent(), '<h1'));
        $page->assertSee(__('transcribe.page.free_text', ['length' => '1 min']), false);
    }

    public function test_a_second_account_in_the_same_browser_is_told_its_free_try_is_used(): void
    {
        SiteSetting::set('transcription_audience', TranscriptionSettings::AUDIENCE_EVERYONE, TranscriptionSettings::GROUP);
        $first = User::factory()->create(['email_verified_at' => now(), 'registration_device_id' => 'browser_demo_stt_0123456789abcdefg']);
        Transcription::query()->create(['user_id' => $first->id, 'title' => 'try', 'status' => Transcription::READY, 'is_free' => true,
            'charged_seconds' => 20, 'period_ym' => 'x', 'browser' => 'browser_demo_stt_0123456789abcdefg']);
        $this->travel(3)->weeks();
        $second = User::factory()->create(['email_verified_at' => now(), 'registration_ip' => '203.0.113.80']);

        $this->withCredentials()->withCookies(['listen_demo_device' => 'browser_demo_stt_0123456789abcdefg'])
            ->actingAs($second)->getLocalizedRoute('demo.page')->assertOk()
            ->assertSee(__('transcribe.page.used_text'), false);
    }
}
