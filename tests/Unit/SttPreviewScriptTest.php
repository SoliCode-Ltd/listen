<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The workflow's stt-preview mode (scripts/support/stt_preview.php) tells the owner
 * what each plan gets from the speech to text migration: its numbers must be the
 * migration's own, and it must list every speech to text migration.
 */
class SttPreviewScriptTest extends TestCase
{
    public function test_the_preview_shows_the_migration_s_own_numbers_and_every_migration(): void
    {
        $root = dirname(__DIR__, 2);
        $migration = require $root.'/database/migrations/2026_12_01_120000_add_transcription_to_plans.php';
        $plans = (new \ReflectionClassConstant($migration, 'PLANS'))->getValue();
        $script = (string) file_get_contents($root.'/scripts/support/stt_preview.php');

        preg_match('/const PLANS = (\[.*?\n\]);/s', $script, $m);
        $this->assertNotEmpty($m, 'the script has its PLANS list');
        $this->assertSame($plans, eval('return '.$m[1].';'));

        foreach (glob($root.'/database/migrations/2026_12_01_120*transcription*.php') ?: [] as $file) {
            $this->assertStringContainsString(basename($file, '.php'), $script);
        }
        $this->assertStringContainsString('SET SESSION TRANSACTION READ ONLY', $script);
    }
}
