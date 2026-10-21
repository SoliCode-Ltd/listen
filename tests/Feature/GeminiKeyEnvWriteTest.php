<?php

namespace Tests\Feature;

use App\Services\Tts\GeminiKeyManager;
use Dotenv\Parser\Parser;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Adding or removing a Gemini key (admin, Gemini keys) writes .env the same
 * safe way as the Env Editor: checked, with a copy of the old file kept.
 * Works on a throwaway .env, never the real one.
 */
class GeminiKeyEnvWriteTest extends TestCase
{
    private string $dir;

    private string $originalBasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/gemini-keys-'.uniqid();
        File::makeDirectory($this->dir);
        File::put($this->dir.'/.env', "APP_NAME=Listen\nGEMINI_TTS_API_KEY=AIzaFirstTestKey0000\n");

        // GeminiKeyManager writes base_path('.env'): point that, and storage, at the copy.
        $this->originalBasePath = $this->app->basePath();
        $this->app->setBasePath($this->dir);
        $this->app->useStoragePath($this->dir.'/storage');
    }

    protected function tearDown(): void
    {
        $this->app->setBasePath($this->originalBasePath);
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    public function test_adding_and_removing_a_key_keeps_the_file_readable_and_a_copy_of_it(): void
    {
        $manager = app(GeminiKeyManager::class);

        $this->assertSame('GEMINI_TTS_API_KEY_2', $manager->addKey('AIzaSecondTestKey1111'));

        $env = File::get($this->dir.'/.env');
        $this->assertStringContainsString("\nGEMINI_TTS_API_KEY_2=AIzaSecondTestKey1111", $env);
        $this->assertNotEmpty((new Parser)->parse($env));
        $this->assertCount(1, $this->backups());
        $this->assertSame("APP_NAME=Listen\nGEMINI_TTS_API_KEY=AIzaFirstTestKey0000\n", File::get($this->backups()[0]));

        $manager->removeKey('GEMINI_TTS_API_KEY_2');

        $this->assertStringNotContainsString('GEMINI_TTS_API_KEY_2', File::get($this->dir.'/.env'));
        $this->assertStringContainsString('GEMINI_TTS_API_KEY=AIzaFirstTestKey0000', File::get($this->dir.'/.env'));
        $this->assertCount(2, $this->backups());
    }

    public function test_a_key_in_googles_newer_format_is_taken_once_google_accepts_it(): void
    {
        Http::fake(['*/models*' => Http::response(['models' => [['name' => 'models/x']]], 200)]);
        $manager = app(GeminiKeyManager::class);
        $newer = 'AQ.Ab8RN6Kx9Tq2Lm4Vb7Zc1Pd3Fh5Jk8Mn0Rs';

        $check = $manager->checkNewKey('  '.$newer.' ');

        $this->assertTrue($check['ok']);
        $this->assertTrue($check['checked']);
        // Asked with the key in a header, never in the address.
        Http::assertSent(fn ($request) => $request->hasHeader('x-goog-api-key', $newer) && ! str_contains($request->url(), $newer));
        $this->assertSame('GEMINI_TTS_API_KEY_2', $manager->addKey($newer));
        $this->assertStringContainsString("\nGEMINI_TTS_API_KEY_2=".$newer, File::get($this->dir.'/.env'));
        $this->assertNotEmpty((new Parser)->parse(File::get($this->dir.'/.env')));
    }

    public function test_a_key_google_refuses_or_a_broken_paste_or_a_duplicate_is_not_saved(): void
    {
        $manager = app(GeminiKeyManager::class);

        Http::fake(['*' => Http::response(['error' => ['message' => 'API key not valid. Please pass a valid API key.']], 400)]);
        $refused = $manager->checkNewKey('AQ.ThisKeyWasMistypedByOneLetter12345');
        $this->assertFalse($refused['ok']);
        $this->assertStringContainsString('API key not valid', $refused['message']);

        // Spaces, a line break or "=" could break .env: never accepted.
        foreach (['short', "AQ.Two Words In It 1234567890", "AQ.Line\nGEMINI_TTS_API_KEY_9=evil", 'AQ.Has=Equals1234567890123'] as $bad) {
            $this->assertFalse($manager->checkNewKey($bad)['ok'], $bad);
        }
        $this->assertSame('This key is already saved.', $manager->checkNewKey('AIzaFirstTestKey0000')['message']);
    }

    public function test_when_google_cannot_be_reached_the_key_is_saved_with_a_warning(): void
    {
        Http::fake(['*' => Http::response('Service Unavailable', 503)]);

        $check = app(GeminiKeyManager::class)->checkNewKey('AQ.Ab8RN6Kx9Tq2Lm4Vb7Zc1Pd3Fh5Jk8Mn0Rs');

        $this->assertTrue($check['ok']);
        $this->assertFalse($check['checked']);
    }

    /**
     * @return list<string>
     */
    private function backups(): array
    {
        $copies = glob($this->dir.'/storage/app/listen-deploy/env.backup-*') ?: [];
        sort($copies);

        return $copies;
    }
}
