<?php

namespace Tests\Feature\Admin;

use App\Filament\Pages\TranscriptionSettingsPage;
use App\Models\User;
use App\Support\Transcription\TranscriptionSettings;
use App\Support\VoiceCloningAccess;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Admin → Speech to text → Settings: the site's own limits for speech to text,
 * each falling back to config/listen.php until saved.
 */
class TranscriptionSettingsAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_before_anything_is_saved_the_config_numbers_and_the_measured_languages_apply(): void
    {
        $this->assertSame(TranscriptionSettings::AUDIENCE_PREVIEW, TranscriptionSettings::audience());
        $this->assertSame(60, TranscriptionSettings::freeTrySeconds());
        $this->assertSame(50, TranscriptionSettings::freePerDay());
        $this->assertSame(3000, TranscriptionSettings::dailyMinutes());
        $this->assertSame(7, TranscriptionSettings::keepAudioDays());
        $this->assertSame(['ar', 'en', 'fr', 'es', 'de', 'ur'], TranscriptionSettings::languages());
        $this->assertFalse(TranscriptionSettings::review());
        $this->assertSame(0.0025, TranscriptionSettings::costPerMinute());
        $this->assertTrue(TranscriptionSettings::visibleTo(VoiceCloningAccess::PREVIEW_EMAIL));
        $this->assertFalse(TranscriptionSettings::visibleTo('someone@example.com'));
    }

    public function test_the_admin_changes_every_setting(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(TranscriptionSettingsPage::class)
            ->assertSee('Speech to text settings')
            ->fillForm([
                'transcription_audience' => TranscriptionSettings::AUDIENCE_EVERYONE,
                'transcription_free_try_seconds' => 45,
                'transcription_free_per_day' => 20,
                'transcription_daily_minutes' => 5000,
                'transcription_max_upload_mb' => 800,
                'transcription_keep_audio_days' => 3,
                'transcription_stuck_minutes' => 40,
                'transcription_languages' => ['en', 'ar', 'tr'],
                'transcription_review' => true,
                'transcription_cost_per_minute' => 0.003,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue(TranscriptionSettings::visibleTo('someone@example.com'));
        $this->assertSame(45, TranscriptionSettings::freeTrySeconds());
        $this->assertSame(20, TranscriptionSettings::freePerDay());
        $this->assertSame(5000, TranscriptionSettings::dailyMinutes());
        $this->assertSame(800, TranscriptionSettings::maxUploadMb());
        $this->assertSame(3, TranscriptionSettings::keepAudioDays());
        $this->assertSame(40, TranscriptionSettings::stuckMinutes());
        $this->assertSame(['ar', 'en', 'tr'], TranscriptionSettings::languages(), 'in the list\'s own order');
        $this->assertTrue(TranscriptionSettings::review());
        $this->assertSame(0.003, TranscriptionSettings::costPerMinute());
    }

    public function test_off_hides_it_from_everyone(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Livewire::test(TranscriptionSettingsPage::class)
            ->fillForm(['transcription_audience' => TranscriptionSettings::AUDIENCE_OFF])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse(TranscriptionSettings::visibleTo(VoiceCloningAccess::PREVIEW_EMAIL));
    }
}
