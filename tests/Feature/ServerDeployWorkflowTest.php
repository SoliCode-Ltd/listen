<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * The "Server deploy" workflow (.github/workflows/server-deploy.yml) must
 * start: GitHub refuses to run a workflow whose step script that uses
 * ${{ … }} is longer than 21,000 characters once read as an expression, and
 * every support script a mode sends to the server must exist. And a script
 * that stops on an error must fail the run: once Laravel has started, its own
 * handler shows an uncaught error and PHP still ends with 0, so the step
 * would pass. Each script takes over right after starting Laravel: those in
 * scripts/support/ and those the other workflows send from scripts/tts/,
 * scripts/api/, scripts/stats/ and the rest.
 */
class ServerDeployWorkflowTest extends TestCase
{
    private const GITHUB_LIMIT = 21000;

    /** What every script that starts Laravel carries on the lines after it (after a comment of its own, if any). */
    private const STOPS_ON_AN_ERROR = <<<'PHP'
        set_exception_handler(function (Throwable $e): void {
            fwrite(STDERR, 'The check stopped on an error: '.$e::class.': '.$e->getMessage().' ('.basename($e->getFile()).':'.$e->getLine().")\nFAILED\n");
            exit(1);
        });
        PHP;

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

    public function test_every_script_that_starts_laravel_fails_the_run_when_it_stops_on_an_error(): void
    {
        $starting = [];
        foreach (File::allFiles(base_path('scripts')) as $file) {
            if ($file->getExtension() === 'php' && str_contains($file->getContents(), '->bootstrap();')) {
                $starting['scripts/'.str_replace('\\', '/', $file->getRelativePathname())] = $file->getContents();
            }
        }

        // Every folder is looked at, not scripts/support/ alone.
        $this->assertArrayHasKey('scripts/support/data_checks.php', $starting);
        $this->assertArrayHasKey('scripts/stats/saas_stats.php', $starting);
        $this->assertArrayHasKey('scripts/tts/long_form_record.php', $starting);
        foreach ($starting as $path => $code) {
            $this->assertTrue(self::everyStartIsFollowedByTheHandler($code),
                $path.' starts Laravel: right after ->bootstrap(); it needs the set_exception_handler lines the other scripts have, or an error in it would not fail the run.');
        }
    }

    public function test_the_php_written_in_a_workflow_fails_the_run_when_it_stops_on_an_error(): void
    {
        $withPhp = [];
        foreach (glob(base_path('.github/workflows/*.yml')) as $path) {
            $workflow = basename($path);
            $scripts = implode("\n", self::runScripts((string) file_get_contents($path)));

            // Sent on its own lines (a heredoc): the same handler as the scripts.
            if (preg_match('/->bootstrap\(\);$/m', $scripts) === 1) {
                $withPhp[] = $workflow;
                $this->assertTrue(self::everyStartIsFollowedByTheHandler($scripts),
                    'PHP in '.$workflow.' starts Laravel without the set_exception_handler lines after ->bootstrap(); an error in it would not fail the run.');
            }
            // As the list of a "for", what PHP ends with is never looked at: read it into a variable first.
            $this->assertDoesNotMatchRegularExpression('/^\s*for \w+ in [^\n]*\$\([^\n]*\bphp\b/m', $scripts, $workflow);
        }

        $this->assertContains('server-deploy.yml', $withPhp);
    }

    public function test_the_handler_ends_php_with_an_error(): void
    {
        $script = implode("\n", [
            '<?php',
            "require 'vendor/autoload.php';",
            "\$app = require 'bootstrap/app.php';",
            '$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();',
            self::STOPS_ON_AN_ERROR,
            'echo "before\\n";',
            "throw new RuntimeException('boom');",
        ]);

        // Sent the way the workflow sends it: on standard input, from the app's folder.
        $run = Process::path(base_path())->input($script)->run([PHP_BINARY]);

        $this->assertSame(1, $run->exitCode(), $run->output().$run->errorOutput());
        $this->assertSame("before\n", $run->output());
        $this->assertSame("The check stopped on an error: RuntimeException: boom (Standard input code:10)\nFAILED\n", $run->errorOutput());
    }

    /**
     * Every "->bootstrap();" that ends a line is followed, after an empty line
     * and a comment line at most, by the handler (at that line's own indent).
     */
    private static function everyStartIsFollowedByTheHandler(string $code): bool
    {
        $handler = implode('\n\1', array_map(static fn (string $line): string => preg_quote($line, '/'), explode("\n", self::STOPS_ON_AN_ERROR)));
        $starts = preg_match_all('/->bootstrap\(\);$/m', $code);

        return $starts > 0 && $starts === preg_match_all('/->bootstrap\(\);\n\n?( *)(?:\/\/[^\n]*\n\1)?'.$handler.'\n/', $code);
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
