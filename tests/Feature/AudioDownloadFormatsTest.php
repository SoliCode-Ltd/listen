<?php

namespace Tests\Feature;

use App\Filament\Resources\PlanResource\Pages\EditPlan;
use App\Models\AudioGeneration;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\AudioFormats;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class AudioDownloadFormatsTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_plan_keeps_its_own_download_types(): void
    {
        $this->assertSame(['mp3'], AudioFormats::chosenFor(null));
        $this->assertSame(['mp3'], AudioFormats::chosenFor(new Plan(['download_formats' => []])));
        // Fixed order, unknown types dropped.
        $this->assertSame(['mp3', 'wav', 'flac'], AudioFormats::chosenFor(new Plan(['download_formats' => ['flac', 'exe', 'wav', 'mp3']])));
        // WAV and MP3 never depend on the server.
        $this->assertSame(['wav'], AudioFormats::availableFor(new Plan(['download_formats' => ['wav']])));
    }

    public function test_the_admin_ticks_the_types_for_a_plan(): void
    {
        $plan = Plan::factory()->create(['slug' => 'pro', 'price_cents' => 2900]);
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(EditPlan::class, ['record' => $plan->getRouteKey()])
            ->assertFormFieldExists('download_formats')
            ->fillForm(['download_formats' => ['mp3', 'wav', 'm4a']])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['mp3', 'wav', 'm4a'], $plan->fresh()->download_formats);
    }

    public function test_the_page_gives_the_player_the_members_types(): void
    {
        [$user] = $this->member(['mp3', 'wav']);
        $this->actingAs($user);
        app()->setLocale('en');

        $html = $this->getLocalizedRoute('dashboard')->assertOk()->getContent();
        $this->assertStringContainsString('var downloadFormats = [{"key":"mp3","label":"MP3","browser":true},{"key":"wav","label":"WAV","browser":true}]', $html);
        $this->assertStringContainsString('/audio-download', $html);
    }

    public function test_the_server_converts_only_a_members_own_audio_in_a_type_their_plan_has(): void
    {
        Storage::fake('public');
        [$user, $plan] = $this->member(['mp3', 'flac']);
        $other = User::factory()->create();
        Storage::disk('public')->put('audio/mine.wav', $this->wav());
        Storage::disk('public')->put('audio/theirs.wav', $this->wav());
        AudioGeneration::create(['user_id' => $user->id, 'user_sequence_number' => 1, 'input_text' => 'mine', 'target_language' => 'en', 'status' => 'done', 'audio_path' => 'audio/mine.wav']);
        AudioGeneration::create(['user_id' => $other->id, 'user_sequence_number' => 1, 'input_text' => 'theirs', 'target_language' => 'en', 'status' => 'done', 'audio_path' => 'audio/theirs.wav']);
        $this->actingAs($user);

        // Someone else's audio, a type the plan lacks, and browser-made types are refused.
        $this->get('/audio-download?path=audio/theirs.wav&format=flac')->assertNotFound();
        $this->get('/audio-download?path=audio/mine.wav&format=m4a')->assertForbidden();
        $this->get('/audio-download?path=audio/mine.wav&format=mp3')->assertNotFound();
        $this->get('/audio-download?path=../.env&format=flac')->assertNotFound();

        if (! AudioFormats::serverCanConvert()) {
            $this->markTestSkipped('ffmpeg is not installed here.');
        }

        $response = $this->get('/audio-download?path=media/audio/mine.wav&format=flac&name=en_hello')->assertOk();
        $this->assertSame('audio/flac', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('filename="en_hello.flac"', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('fLaC', substr($response->getContent(), 0, 4));
    }

    /**
     * @param  array<int, string>  $formats
     * @return array{0: User, 1: Plan}
     */
    private function member(array $formats): array
    {
        Plan::factory()->create(['slug' => 'free', 'price_cents' => 0]);
        $plan = Plan::factory()->create(['slug' => 'studio', 'price_cents' => 2900, 'download_formats' => $formats]);
        $user = User::factory()->create();
        $user->forceFill(['paid_welcome_sent_at' => now()])->saveQuietly();
        Subscription::create(['user_id' => $user->id, 'plan_id' => $plan->id, 'status' => 'active']);

        return [$user, $plan];
    }

    private function wav(): string
    {
        $samples = '';
        for ($i = 0; $i < 2400; $i++) {
            $samples .= pack('v', (int) (8000 * sin($i / 8)) & 0xFFFF);
        }

        return 'RIFF'.pack('V', 36 + strlen($samples)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 24000, 48000, 2, 16)
            .'data'.pack('V', strlen($samples)).$samples;
    }
}
