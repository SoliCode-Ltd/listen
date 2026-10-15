<?php

namespace Tests\Feature;

use App\Models\VoiceSample;
use App\Support\SpeakerAvatar;
use Database\Seeders\VoiceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AlaaSpeakerTest extends TestCase
{
    use RefreshDatabase;

    public function test_hazem_is_now_alaa_and_yasser_and_waleed_keep_their_names(): void
    {
        $this->seed(VoiceSeeder::class);
        $this->assertTrue(DB::table('voices')->where('display_name', 'Alaa')->where('provider_voice_id', 'Schedar')->exists());
        $this->assertTrue(DB::table('voices')->where('display_name', 'Yasser')->where('provider_voice_id', 'Zubenelgenubi')->exists());
        $this->assertTrue(DB::table('voices')->where('display_name', 'Waleed')->exists());
        $this->assertFalse(DB::table('voices')->where('display_name', 'Hazem')->exists());
        $this->assertNotNull(SpeakerAvatar::url('Alaa', 'ar'));
        $this->assertNotNull(SpeakerAvatar::url('Yasser', 'ar'));
    }

    public function test_the_migration_moves_the_name_on_live_rows(): void
    {
        $this->seed(VoiceSeeder::class);
        // Live before: Yasser was called Alaa, Hazem was Hazem.
        DB::table('voices')->where('provider_voice_id', 'Schedar')->update(['display_name' => 'Hazem']);
        DB::table('voices')->where('provider_voice_id', 'Zubenelgenubi')->update(['display_name' => 'Alaa']);
        $nile = $this->sample('ar-sd-nile', 'Alaa', 'علاء');
        $saidi = $this->sample('ar-eg-saidi-wisdom', 'Hazem', 'حازم');
        $tea = $this->sample('ar-mr-hassaniya-tea', 'Hazem', 'حازم');

        (require database_path('migrations/2026_10_15_160000_move_alaa_from_yasser_to_hazem.php'))->up();

        $this->assertSame('Yasser', DB::table('voices')->where('provider_voice_id', 'Zubenelgenubi')->value('display_name'));
        $this->assertSame('Alaa', DB::table('voices')->where('provider_voice_id', 'Schedar')->value('display_name'));
        $this->assertSame('ياسر', $nile->fresh()->metadata['speaker_name_ar']);
        $this->assertSame('علاء', $saidi->fresh()->metadata['speaker_name_ar']);
        $this->assertSame('Alaa', $tea->fresh()->metadata['speakers'][0]['name']);
    }

    public function test_no_homepage_audio_says_any_of_these_names(): void
    {
        $data = require database_path('seeders/data/showcase_samples.php');
        $spoken = '';
        foreach ($data['samples'] as $sample) {
            $spoken .= ' '.($sample['text'] ?? '');
            foreach ($sample['lines'] ?? [] as $line) {
                $spoken .= ' '.explode(':', $line, 2)[1];
            }
        }
        foreach (['حازم', 'Hazem', 'علاء', 'Alaa', 'ياسر', 'Yasser'] as $name) {
            $this->assertStringNotContainsString($name, $spoken);
        }
    }

    private function sample(string $slug, string $name, string $nameAr): VoiceSample
    {
        return VoiceSample::query()->create([
            'title' => $slug, 'sample_text' => 'نص', 'audio_path' => 'audio/showcase/'.$slug.'.mp3', 'language' => 'ar', 'category' => 'hero',
            'metadata' => ['speaker_name' => $name, 'speaker_name_ar' => $nameAr, 'speakers' => [['name' => $name, 'name_ar' => $nameAr]]],
        ]);
    }
}
