<?php

namespace Tests\Feature;

use App\Models\VoiceSample;
use App\Support\SpeakerAvatar;
use Database\Seeders\VoiceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RenameYasserToAlaaTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_zubenelgenubi_speaker_and_its_sample_are_called_alaa(): void
    {
        $this->seed(VoiceSeeder::class);
        $this->assertTrue(DB::table('voices')->where('display_name', 'Alaa')->where('provider_voice_id', 'Zubenelgenubi')->exists());
        $this->assertTrue(DB::table('voices')->where('display_name', 'Waleed')->exists(), 'Waleed stays as he is');
        $this->assertNotNull(SpeakerAvatar::url('Alaa', 'ar'), 'the avatar follows the name');

        DB::table('voices')->where('display_name', 'Alaa')->update(['display_name' => 'Yasser']);
        $sample = VoiceSample::query()->create([
            'title' => 'Sunset on the Nile', 'sample_text' => 'نص', 'audio_path' => 'audio/showcase/ar-sd-nile.mp3', 'language' => 'ar', 'category' => 'hero',
            'metadata' => ['speaker_name' => 'Yasser', 'speaker_name_ar' => 'ياسر', 'speakers' => [['name' => 'Yasser', 'name_ar' => 'ياسر']]],
        ]);

        (require database_path('migrations/2026_10_15_150000_rename_yasser_speaker_to_alaa.php'))->up();

        $this->assertTrue(DB::table('voices')->where('display_name', 'Alaa')->where('provider_voice_id', 'Zubenelgenubi')->exists());
        $meta = $sample->fresh()->metadata;
        $this->assertSame('علاء', $meta['speaker_name_ar']);
        $this->assertSame('Alaa', $meta['speakers'][0]['name']);
    }

    public function test_no_homepage_audio_says_the_old_or_new_name(): void
    {
        $data = require database_path('seeders/data/showcase_samples.php');
        $spoken = '';
        foreach ($data['samples'] as $sample) {
            $spoken .= ' '.($sample['text'] ?? '');
            foreach ($sample['lines'] ?? [] as $line) {
                $spoken .= ' '.explode(':', $line, 2)[1];
            }
        }
        foreach (['ياسر', 'Yasser', 'علاء', 'Alaa'] as $name) {
            $this->assertStringNotContainsString($name, $spoken);
        }
    }
}
