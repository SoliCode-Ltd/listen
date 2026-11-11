<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * The "Server deploy" workflow (.github/workflows/server-deploy.yml) must
 * start: GitHub refuses to run a workflow whose step script that uses
 * ${{ … }} is longer than 21,000 characters once read as an expression, and
 * every support script a mode sends to the server must exist.
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
                $this->assertLessThan(self::GITHUB_LIMIT, strlen(self::asExpression($script)),
                    "The step script at line {$line} is too long for GitHub; pass the \${{ }} values in env, or move remote work to scripts/support/.");
            }
        }
    }

    public function test_the_measure_counts_what_github_counts(): void
    {
        // GitHub turned this away at 20,830 characters: as an expression it is longer.
        $script = str_repeat("echo 'a {b}'\n", 1000).'echo ${{ inputs.mode }}';

        $this->assertSame("format('".str_repeat("echo ''a {{b}}''\n", 1000)."echo {0}', inputs.mode)", self::asExpression($script));
    }

    /**
     * A script holding ${{ }} the way GitHub checks its length: one
     * format('…', …) expression, with every ' { } of the plain text doubled.
     */
    private static function asExpression(string $script): string
    {
        $parts = preg_split('/\$\{\{(.*?)\}\}/s', $script, -1, PREG_SPLIT_DELIM_CAPTURE);
        $text = '';
        $args = '';
        foreach ($parts as $i => $part) {
            if ($i % 2 === 0) {
                $text .= str_replace(["'", '{', '}'], ["''", '{{', '}}'], $part);
            } else {
                $text .= '{'.intdiv($i, 2).'}';
                $args .= ', '.trim($part);
            }
        }

        return "format('".$text."'".$args.')';
    }

    public function test_every_support_script_a_mode_sends_exists(): void
    {
        preg_match_all('/\btool ([a-z_]+\.(?:php|sh))\b/', (string) file_get_contents(base_path('.github/workflows/server-deploy.yml')), $matches);

        $this->assertContains('composer_install.sh', $matches[1]);
        foreach (array_unique($matches[1]) as $script) {
            $this->assertFileExists(base_path('scripts/support/'.$script));
        }
    }

    public function test_the_removed_files_list_names_only_files_the_code_no_longer_has(): void
    {
        $paths = self::removedFiles((string) file_get_contents(base_path('scripts/support/removed_files.txt')));

        $this->assertContains('app/Filament/Resources/SourceAccountResource.php', $paths);
        foreach ($paths as $path) {
            $this->assertDoesNotMatchRegularExpression('#^/|\.\.#', $path, "{$path}: a path from the app's folder, never above it");
            $this->assertFileDoesNotExist(base_path($path), "{$path} is in the code again: take it off scripts/support/removed_files.txt");
        }
    }

    /**
     * The deploy's own lines, run on a sample app folder: only the listed files
     * that are on the server and not in the code deployed are moved, they are
     * kept under storage/app/listen-deploy/removed/, and a second run changes nothing.
     */
    public function test_the_deploy_moves_aside_only_the_listed_files_the_code_no_longer_has(): void
    {
        $deploy = self::runScripts((string) file_get_contents(base_path('.github/workflows/server-deploy.yml')));
        $script = collect($deploy)->first(fn (string $s): bool => str_contains($s, 'removed_files.txt ]; then'));
        $this->assertNotNull($script);
        $this->assertSame(1, preg_match('/^if \[ -f scripts\/support\/removed_files\.txt \]; then\n.*?^fi\n/ms', $script, $block));

        $root = sys_get_temp_dir().'/listen-removed-'.bin2hex(random_bytes(4));
        $app = $root.'/app-folder';
        $put = function (string $path, string $text) use ($app): void {
            @mkdir(dirname($app.'/'.$path), 0777, true);
            file_put_contents($app.'/'.$path, $text);
        };
        $put('app/Filament/Resources/OldResource.php', 'old resource');
        $put('app/Filament/Resources/OldResource/Pages/ListOld.php', 'old page');
        $put('app/Filament/Resources/BackResource.php', 'in the code again');
        $put('app/Filament/Resources/OtherResource.php', 'never listed');
        $put('app/Kept.php', 'kept');
        file_put_contents($root.'/outside.php', 'outside the app');
        $put('storage/app/listen-deploy/manifest.md5', implode("\n", [
            md5('in the code again').'  app/Filament/Resources/BackResource.php',
            md5('kept').'  app/Kept.php',
            md5('never listed').'  app/Filament/Resources/OtherResource.php',
        ])."\n");
        $put('scripts/support/removed_files.txt', implode("\n", [
            '# a note',
            '',
            'app/Filament/Resources/OldResource.php',
            'app/Filament/Resources/OldResource/Pages/ListOld.php',
            'app/Filament/Resources/BackResource.php',
            'app/NotOnTheServer.php',
            '../outside.php',
            $root.'/outside.php',
        ])."\n");

        try {
            $run = fn () => Process::path($app)->run(['bash', '-c', "set -euo pipefail\n".$block[0]]);

            $first = $run();
            $this->assertTrue($first->successful(), $first->errorOutput());
            $this->assertSame(
                "Moved aside (no longer in the code): app/Filament/Resources/OldResource.php\nMoved aside (no longer in the code): app/Filament/Resources/OldResource/Pages/ListOld.php\n",
                $first->output(),
            );
            $this->assertSame('old resource', file_get_contents($app.'/storage/app/listen-deploy/removed/app/Filament/Resources/OldResource.php'));
            $this->assertSame('old page', file_get_contents($app.'/storage/app/listen-deploy/removed/app/Filament/Resources/OldResource/Pages/ListOld.php'));
            $this->assertFileDoesNotExist($app.'/app/Filament/Resources/OldResource.php');
            $this->assertDirectoryDoesNotExist($app.'/app/Filament/Resources/OldResource');
            $this->assertSame('in the code again', file_get_contents($app.'/app/Filament/Resources/BackResource.php'));
            $this->assertSame('never listed', file_get_contents($app.'/app/Filament/Resources/OtherResource.php'));
            $this->assertSame('kept', file_get_contents($app.'/app/Kept.php'));
            $this->assertSame('outside the app', file_get_contents($root.'/outside.php'));

            $second = $run();
            $this->assertTrue($second->successful(), $second->errorOutput());
            $this->assertSame('', $second->output());
            $this->assertSame('old resource', file_get_contents($app.'/storage/app/listen-deploy/removed/app/Filament/Resources/OldResource.php'));
        } finally {
            Process::run(['rm', '-rf', $root]);
        }
    }

    /**
     * @return list<string>
     */
    private static function removedFiles(string $list): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", $list)), static fn (string $l): bool => $l !== '' && ! str_starts_with($l, '#')));
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
