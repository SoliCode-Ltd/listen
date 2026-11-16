<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Every stylesheet the site serves has its braces in pairs. A browser that meets a
 * closing brace with nothing open joins it to the rule after it and throws that rule
 * away, with no error anywhere: that is how the speech to text editor's menus on
 * phones (a sheet from the foot of the screen) were lost and ran off the screen.
 * The admin's own files (public/css/filament) are built by Filament and left out.
 */
class StylesheetBracesTest extends TestCase
{
    public function test_no_stylesheet_has_a_brace_without_its_pair(): void
    {
        $files = glob(dirname(__DIR__, 2).'/public/css/*.css') ?: [];
        $this->assertNotEmpty($files);

        $problems = [];
        foreach ($files as $file) {
            $problems = [...$problems, ...self::problems((string) file_get_contents($file), basename($file))];
        }

        $this->assertSame([], $problems);
    }

    public function test_the_check_finds_a_brace_left_over_and_one_left_open(): void
    {
        $this->assertSame([], self::problems(".a { color: red; }\n@media (max-width: 640px) { .b { top: 0; } }\n/* } in a comment */ .c::after { content: \"}\"; }", 'ok.css'));
        $this->assertSame(['x.css: a closing brace with nothing open at line 2'], self::problems(".a { color: red; }\n}\n.b { top: 0; }", 'x.css'));
        $this->assertSame(['y.css: 1 brace(s) never closed'], self::problems('@media (max-width: 640px) { .a { top: 0; }', 'y.css'));
    }

    /** @return list<string> */
    private static function problems(string $css, string $name): array
    {
        // Comments and quoted text set aside, their line breaks kept so the lines still count true.
        $css = (string) preg_replace_callback('~/\*.*?\*/~s', fn ($m) => (string) preg_replace('/[^\n]/', ' ', $m[0]), $css);
        $css = (string) preg_replace_callback('~"(?:[^"\\\\\n]|\\\\.)*"|\'(?:[^\'\\\\\n]|\\\\.)*\'~', fn ($m) => str_repeat(' ', strlen($m[0])), $css);

        $problems = [];
        $depth = 0;
        $line = 1;
        foreach (str_split($css) as $char) {
            if ($char === "\n") {
                $line++;
            } elseif ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                if ($depth === 0) {
                    $problems[] = "{$name}: a closing brace with nothing open at line {$line}";

                    continue;
                }
                $depth--;
            }
        }
        if ($depth > 0) {
            $problems[] = "{$name}: {$depth} brace(s) never closed";
        }

        return $problems;
    }
}
