<?php

namespace App\Support\Api;

/**
 * A small syntax highlighter for the API documentation's code samples, run on
 * the server so the page needs no script to show coloured code. It knows just
 * enough of each language (comments, strings, numbers, keywords) for the
 * samples ApiCodeSamples writes. Output is HTML-escaped.
 */
final class CodeHighlighter
{
    /** @var array<string, array<int, string>> */
    private const KEYWORDS = [
        'bash' => ['curl', 'export'],
        'python' => ['import', 'from', 'as', 'with', 'def', 'return', 'if', 'elif', 'else', 'for', 'in', 'while', 'try', 'except', 'raise', 'not', 'and', 'or', 'True', 'False', 'None'],
        'javascript' => ['import', 'from', 'const', 'let', 'await', 'async', 'function', 'return', 'if', 'else', 'for', 'of', 'throw', 'new', 'true', 'false', 'null'],
        'php' => ['function', 'return', 'if', 'else', 'throw', 'new', 'true', 'false', 'null', 'foreach', 'as'],
        'go' => ['package', 'import', 'func', 'return', 'if', 'else', 'for', 'range', 'defer', 'var', 'nil', 'err'],
        'java' => ['import', 'public', 'class', 'static', 'void', 'throws', 'new', 'if', 'else', 'throw', 'return', 'String', 'byte'],
        'csharp' => ['using', 'var', 'new', 'await', 'async', 'if', 'else', 'throw', 'return', 'null', 'true', 'false'],
        'ruby' => ['require', 'do', 'end', 'if', 'unless', 'raise', 'puts', 'def', 'return', 'nil', 'true', 'false'],
        'json' => ['true', 'false', 'null'],
        'http' => [],
    ];

    public static function html(string $code, string $grammar): string
    {
        $patterns = self::patterns($grammar);
        $regex = '/'.implode('|', array_map(
            static fn (string $name, string $pattern): string => '(?<'.$name.'>'.$pattern.')',
            array_keys($patterns),
            $patterns,
        )).'/m';

        $html = '';
        $offset = 0;
        $length = strlen($code);
        while ($offset < $length && preg_match($regex, $code, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            [$text, $start] = $match[0];
            if ($text === '') {
                break;
            }
            $html .= e(substr($code, $offset, $start - $offset));
            $type = 'plain';
            foreach (array_keys($patterns) as $name) {
                if (isset($match[$name]) && $match[$name][1] !== -1 && $match[$name][0] !== '') {
                    $type = preg_replace('/\d+$/', '', $name);
                    break;
                }
            }
            $html .= '<span class="tok-'.$type.' mono">'.e($text).'</span>';
            $offset = $start + strlen($text);
        }

        return $html.e(substr($code, $offset));
    }

    /**
     * Token patterns in priority order. Group names end in a digit when a
     * token type needs several patterns.
     *
     * @return array<string, string>
     */
    private static function patterns(string $grammar): array
    {
        $doubleQuoted = '"(?:\\\\.|[^"\\\\\\n])*"';
        $singleQuoted = "'(?:\\\\.|[^'\\\\])*'";
        $keywords = self::KEYWORDS[$grammar] ?? [];

        $patterns = match ($grammar) {
            'json' => [
                'key' => $doubleQuoted.'(?=\\s*:)',
                'string' => $doubleQuoted,
            ],
            'http' => [
                'keyword' => '^HTTP\\/[\\d.]+ \\d{3}[^\\n]*',
                'key' => '^[A-Za-z-]+(?=:)',
                'string' => $doubleQuoted.'(?!\\s*:)',
                'key2' => $doubleQuoted,
            ],
            'bash' => [
                'comment' => '(?<=^|\\s)#[^\\n]*',
                'string' => $singleQuoted,
                'string2' => $doubleQuoted,
                'variable' => '\\$[A-Za-z_][A-Za-z0-9_]*',
                'attr' => '(?<=\\s)--?[A-Za-z-]+',
            ],
            'python' => [
                'comment' => '#[^\\n]*',
                'string' => '"""[\\s\\S]*?"""',
                'string2' => 'f?'.$doubleQuoted,
                'string3' => $singleQuoted,
            ],
            'ruby' => [
                'comment' => '(?<=^|\\s)#(?!\\{)[^\\n]*',
                'string' => "<<~'(?<rb>\\w+)'[\\s\\S]*?\\n\\s*\\k<rb>",
                'string2' => $doubleQuoted,
                'string3' => $singleQuoted,
                'type' => '\\b[A-Z][A-Za-z]*(?:::[A-Z][A-Za-z]*)*',
            ],
            'php' => [
                'comment' => '\\/\\/[^\\n]*',
                'string' => "<<<'(?<ph>\\w+)'[\\s\\S]*?\\n\\s*\\k<ph>",
                'keyword2' => '<\\?php',
                'string2' => $doubleQuoted,
                'string3' => $singleQuoted,
                'variable' => '\\$[A-Za-z_][A-Za-z0-9_]*',
                'type' => '\\b[A-Z][A-Z0-9_]{2,}\\b',
            ],
            'javascript' => [
                'comment' => '\\/\\/[^\\n]*',
                'string' => '`(?:\\\\.|[^`\\\\])*`',
                'string2' => $doubleQuoted,
                'string3' => $singleQuoted,
            ],
            'go' => [
                'comment' => '\\/\\/[^\\n]*',
                'string' => '`[^`]*`',
                'string2' => $doubleQuoted,
            ],
            'java', 'csharp' => [
                'comment' => '\\/\\/[^\\n]*',
                'string' => '"""[\\s\\S]*?"""',
                'string2' => $doubleQuoted,
                'type' => '\\b[A-Z][A-Za-z0-9]*(?=[.<(\\s])',
            ],
            default => [],
        };

        if ($keywords !== []) {
            $patterns['keyword'] = '\\b(?:'.implode('|', array_map(static fn (string $word): string => preg_quote($word, '/'), $keywords)).')\\b';
        }
        $patterns['number'] = '\\b\\d+(?:\\.\\d+)?\\b';

        return $patterns;
    }
}
