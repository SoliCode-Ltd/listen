<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The "Server deploy" workflow (.github/workflows/server-deploy.yml) must
 * start: GitHub refuses to run a workflow whose step script that uses
 * ${{ … }} is longer than 21,000 characters, and every support script a
 * mode sends to the server must exist.
 */
class ServerDeployWorkflowTest extends TestCase
{
    private const GITHUB_LIMIT = 21000;

    public function test_every_step_script_fits_githubs_limit(): void
    {
        $scripts = self::runScripts((string) file_get_contents(base_path('.github/workflows/server-deploy.yml')));

        $this->assertNotEmpty($scripts);
        foreach ($scripts as $line => $script) {
            if (str_contains($script, '${{')) {
                $this->assertLessThan(self::GITHUB_LIMIT, strlen($script),
                    "The step script at line {$line} is too long for GitHub; move remote work to scripts/support/.");
            }
        }
    }

    public function test_every_support_script_a_mode_sends_exists(): void
    {
        preg_match_all('/\btool ([a-z_]+\.(?:php|sh))\b/', (string) file_get_contents(base_path('.github/workflows/server-deploy.yml')), $matches);

        $this->assertContains('composer_install.sh', $matches[1]);
        foreach (array_unique($matches[1]) as $script) {
            $this->assertFileExists(base_path('scripts/support/'.$script));
        }
    }

    /**
     * The text of each "run: |" block, by its line number: the lines indented
     * deeper than the "run:" key, as YAML reads them.
     *
     * @return array<int, string>
     */
    private static function runScripts(string $yaml): array
    {
        $lines = explode("\n", $yaml);
        $scripts = [];
        foreach ($lines as $i => $line) {
            if (! preg_match('/^(\s*)run: \|\s*$/', $line, $key)) {
                continue;
            }
            $keyIndent = strlen($key[1]);
            $block = [];
            for ($j = $i + 1; $j < count($lines); $j++) {
                $next = $lines[$j];
                if (trim($next) !== '' && strlen($next) - strlen(ltrim($next)) <= $keyIndent) {
                    break;
                }
                $block[] = $next;
            }
            $indent = min(array_map(static fn (string $l): int => strlen($l) - strlen(ltrim($l)), array_filter($block, static fn (string $l): bool => trim($l) !== '')));
            $scripts[$i + 1] = rtrim(implode("\n", array_map(static fn (string $l): string => substr($l, $indent), $block)))."\n";
        }

        return $scripts;
    }
}
