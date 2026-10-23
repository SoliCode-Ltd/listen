<?php

namespace Tests\Feature;

use App\Models\Voice;
use App\Support\ArabicDialects;
use App\Support\EnglishAccents;
use App\Support\VoiceCatalog;
use App\Support\VoicePreviews;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VoicePreviewsTest extends TestCase
{
    use RefreshDatabase;

    private const GEMINI_VOICES = [
        'Zephyr', 'Puck', 'Charon', 'Kore', 'Fenrir', 'Leda', 'Orus', 'Aoede', 'Callirrhoe', 'Autonoe',
        'Enceladus', 'Iapetus', 'Umbriel', 'Algieba', 'Despina', 'Erinome', 'Algenib', 'Rasalgethi', 'Laomedeia', 'Achernar',
        'Alnilam', 'Schedar', 'Gacrux', 'Pulcherrima', 'Achird', 'Zubenelgenubi', 'Vindemiatrix', 'Sadachbia', 'Sadaltager', 'Sulafat',
    ];

    private ?string $public = null;

    protected function tearDown(): void
    {
        if ($this->public !== null) {
            File::deleteDirectory($this->public);
        }
        VoicePreviews::forget();
        parent::tearDown();
    }

    public function test_every_line_belongs_to_a_speaker_and_fits_ten_seconds(): void
    {
        $lines = VoicePreviews::lines();
        $this->assertGreaterThanOrEqual(100, count($lines));

        foreach ($lines as $slug => $line) {
            $this->assertFileExists(public_path("images/speakers/{$slug}.webp"), "{$slug} has no avatar");
            $this->assertContains($line['voice'], self::GEMINI_VOICES, $slug);
            $this->assertNotSame('', trim($line['style']), $slug);

            $language = $line['language'];
            if ($language === 'ar') {
                $this->assertStringStartsWith('ar-', $slug);
                $this->assertArrayHasKey($line['dialect'], ArabicDialects::ALL, $slug);
            } elseif ($language === 'en') {
                $this->assertStringStartsWith('en-', $slug);
                $this->assertArrayHasKey($line['dialect'], EnglishAccents::ALL, $slug);
            } else {
                // English speakers who speak another language natively.
                $this->assertStringStartsWith('en-', $slug);
                $this->assertArrayHasKey($line['dialect'], VoicePreviews::NATIVE, $slug);
                $this->assertStringStartsWith($language.'-', $line['dialect'], $slug);
            }

            $this->assertLessThanOrEqual(1, preg_match_all('/<[^<>]+>/u', $line['text']), "{$slug}: at most one sound tag");
            $this->assertDoesNotMatchRegularExpression('/\d/u', $line['text'], "{$slug}: numbers are written as words");
            $this->assertDoesNotMatchRegularExpression('/gemini|google|eleven ?labs/iu', $line['text'], "{$slug}: no product names");

            $words = count(preg_split('/\s+/u', trim((string) preg_replace('/<[^<>]+>/u', ' ', $line['text']))));
            if ($language === 'ar') {
                $this->assertTrue($words >= 14 && $words <= 22, "{$slug}: {$words} Arabic words");
            } elseif ($language === 'en') {
                $this->assertTrue($words >= 20 && $words <= 33, "{$slug}: {$words} English words");
            } else {
                $this->assertTrue(mb_strlen($line['text']) >= 40 && mb_strlen($line['text']) <= 190, "{$slug}: length");
            }
        }
    }

    public function test_arabic_and_english_speakers_are_spread_over_many_dialects_and_languages(): void
    {
        $lines = collect(VoicePreviews::lines());

        // No two speakers share a dialect, accent or language: the library is a tour of them.
        $this->assertSame($lines->count(), $lines->pluck('dialect')->unique()->count(), 'Two speakers share '.$lines->pluck('dialect')->duplicates()->implode(', '));

        $this->assertGreaterThanOrEqual(15, $lines->where('language', 'ar')->pluck('dialect')->unique()->count());
        $this->assertGreaterThanOrEqual(15, $lines->where('language', 'en')->pluck('dialect')->unique()->count());
        $this->assertGreaterThanOrEqual(8, $lines->whereNotIn('language', ['ar', 'en'])->pluck('language')->unique()->count());
    }

    public function test_generate_makes_missing_clips_once_and_remakes_a_changed_line(): void
    {
        $this->usePublicPath();
        Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => [['inlineData' => ['data' => base64_encode($this->wav())]]]]]]])]);

        $this->artisan('tts:showcase', ['--set' => 'previews', '--generate' => true, '--only' => ['ar-fahad'], '--key' => 'test', '--pause' => 0])
            ->assertSuccessful();

        $this->assertFileExists(public_path('audio/voice-previews/ar-fahad.mp3'));
        $made = json_decode((string) file_get_contents(VoicePreviews::madePath()), true);
        $this->assertSame(VoicePreviews::hash(VoicePreviews::lines()['ar-fahad']), $made['ar-fahad']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'gemini-3.8-flash-tts')
            && $request['generationConfig']['speechConfig']['voiceConfig']['voice'] === 'Iapetus'
            && str_contains($request['contents'][0]['parts'][0]['speech_metadata']['style'], 'Najdi')
            && count($request['contents'][0]['parts']) === 1);

        // Made already: nothing is asked for again.
        $this->artisan('tts:showcase', ['--set' => 'previews', '--generate' => true, '--only' => ['ar-fahad'], '--key' => 'test', '--pause' => 0])
            ->assertSuccessful();
        Http::assertSentCount(1);

        // The line changed since: the clip is made again.
        file_put_contents(VoicePreviews::madePath(), json_encode(['ar-fahad' => 'old']));
        $this->artisan('tts:showcase', ['--set' => 'previews', '--generate' => true, '--only' => ['ar-fahad'], '--key' => 'test', '--pause' => 0])
            ->assertSuccessful();
        Http::assertSentCount(2);
    }

    public function test_a_native_language_preview_is_asked_for_in_that_language(): void
    {
        $this->usePublicPath();
        Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => [['inlineData' => ['data' => base64_encode($this->wav())]]]]]]])]);

        $this->artisan('tts:showcase', ['--set' => 'previews', '--generate' => true, '--only' => ['en-chloe'], '--key' => 'test', '--pause' => 0])
            ->assertSuccessful();

        Http::assertSent(fn ($request) => str_contains($request['contents'][0]['parts'][0]['speech_metadata']['style'], 'French as spoken in Paris')
            && str_contains($request['contents'][0]['parts'][0]['speech_metadata']['style'], 'No English accent'));
    }

    public function test_the_library_plays_a_made_clip_and_names_its_dialect(): void
    {
        $this->usePublicPath();
        File::ensureDirectoryExists(public_path('audio/voice-previews'));
        file_put_contents(public_path('audio/voice-previews/ar-fahad.mp3'), 'mp3');
        file_put_contents(public_path('audio/voice-previews/en-chloe.mp3'), 'mp3');
        file_put_contents(public_path('audio/voice-previews/ar-ziad.mp3'), 'mp3');
        file_put_contents(VoicePreviews::madePath(), json_encode([
            'ar-fahad' => VoicePreviews::hash(VoicePreviews::lines()['ar-fahad']),
            'en-chloe' => VoicePreviews::hash(VoicePreviews::lines()['en-chloe']),
            'ar-ziad' => 'a line that has changed since',
        ]));

        $fahad = $this->voice('Fahad', 'ar', 'male', 'Iapetus');
        $ziad = $this->voice('Ziad', 'ar', 'male', 'Iapetus');
        $chloe = $this->voice('Chloe', 'en', 'female', 'Despina');

        $entries = array_values(VoiceCatalog::libraryEntries(false));
        $byName = [];
        foreach ([$fahad, $ziad, $chloe] as $i => $voice) {
            $byName[$voice->display_name] = $entries[$i];
        }

        $this->assertStringContainsString('audio/voice-previews/ar-fahad.mp3?v=', (string) $byName['Fahad']['p']);
        $this->assertSame('Saudi (Najdi Bedouin)', $byName['Fahad']['d']);
        $this->assertStringEndsWith('images/flags/sa.svg', (string) $byName['Fahad']['f']);

        $this->assertStringContainsString('audio/voice-previews/en-chloe.mp3?v=', (string) $byName['Chloe']['p']);
        $this->assertSame('French (France)', $byName['Chloe']['d']);
        $this->assertStringEndsWith('images/flags/fr.svg', (string) $byName['Chloe']['f']);

        // A clip made from an older line is not offered.
        $this->assertNull($byName['Ziad']['p']);
        $this->assertSame('', $byName['Ziad']['d']);

        $arabic = array_values(VoiceCatalog::libraryEntries(true));
        $this->assertSame('سعودي (نجدي بدوي)', $arabic[0]['d']);
        $this->assertSame('الفرنسية (فرنسا)', $arabic[2]['d']);
    }

    private function voice(string $name, string $language, string $gender, string $engine): Voice
    {
        return Voice::create([
            'language' => $language,
            'gender' => $gender,
            'tier' => 'hd',
            'display_name' => $name,
            'internal_voice_id' => "{$language}-Gemini-HD-{$engine}-{$name}",
            'provider' => 'gemini',
            'provider_voice_id' => $engine,
            'is_active' => true,
        ]);
    }

    private function usePublicPath(): void
    {
        $this->public = sys_get_temp_dir().'/listen-previews-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->public);
        File::copyDirectory(public_path('images/flags'), $this->public.'/images/flags');
        $this->app->usePublicPath($this->public);
        VoicePreviews::forget();
    }

    /** Half a second of a quiet tone as 24 kHz mono WAV, like Gemini's reply. */
    private function wav(): string
    {
        $samples = '';
        for ($i = 0; $i < 12000; $i++) {
            $samples .= pack('v', (int) (3000 * sin($i / 8)) & 0xFFFF);
        }

        return 'RIFF'.pack('V', 36 + strlen($samples)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 24000, 48000, 2, 16)
            .'data'.pack('V', strlen($samples)).$samples;
    }
}
