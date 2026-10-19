<?php

namespace Tests\Feature;

use App\Services\Tts\GeminiKeyManager;
use Dotenv\Parser\Parser;
use Illuminate\Support\Facades\File;
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
