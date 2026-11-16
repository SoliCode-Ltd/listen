<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SyncArabicTranslationsCommand extends Command
{
    protected $signature = 'listen:lang-sync-ar
        {--file=ui : Translation file name under lang/{locale} without .php}
        {--dry-run : Show what would be translated without writing files}
        {--throttle=120 : Delay between requests in milliseconds}';

    protected $description = 'Auto-fill missing Arabic translation keys from English using Google Translate endpoint.';

    /**
     * @var array<string, string>
     */
    private array $cache = [];

    public function handle(): int
    {
        $file = trim((string) $this->option('file'));
        if ($file === '') {
            $this->error('Option --file cannot be empty.');
            return self::FAILURE;
        }

        $enPath = lang_path('en'.DIRECTORY_SEPARATOR.$file.'.php');
        $arPath = lang_path('ar'.DIRECTORY_SEPARATOR.$file.'.php');

        if (! is_file($enPath)) {
            $this->error("English translation file not found: {$enPath}");
            return self::FAILURE;
        }

        $en = require $enPath;
        if (! is_array($en)) {
            $this->error("English file must return an array: {$enPath}");
            return self::FAILURE;
        }

        $ar = [];
        if (is_file($arPath)) {
            $loadedAr = require $arPath;
            if (is_array($loadedAr)) {
                $ar = $loadedAr;
            }
        }

        $missing = $this->collectMissingLeafPaths($en, $ar);

        if ($missing === []) {
            $this->info('No missing Arabic keys. Everything is in sync.');
            return self::SUCCESS;
        }

        $this->line('Missing keys detected: '.count($missing));

        $translatedCount = 0;
        $failedCount = 0;
        $throttleMs = max(0, (int) $this->option('throttle'));

        foreach ($missing as $path) {
            $source = (string) data_get($en, $path, '');
            if ($source === '') {
                $this->warn("Skipped empty source for key: {$path}");
                continue;
            }

            $translated = $this->translatePreservingTokens($source);
            if ($translated === null) {
                $failedCount++;
                $this->warn("Failed to translate key: {$path}");
                continue;
            }

            data_set($ar, $path, $translated);
            $translatedCount++;
            $this->line("Translated: {$path}");

            if ($throttleMs > 0) {
                usleep($throttleMs * 1000);
            }
        }

        if ($this->option('dry-run')) {
            $this->info("Dry run complete. translated={$translatedCount}, failed={$failedCount}, total_missing=".count($missing));
            return $failedCount > 0 ? self::FAILURE : self::SUCCESS;
        }

        $this->ensureDirectoryExists(dirname($arPath));
        $content = $this->buildPhpArrayFile($ar);
        file_put_contents($arPath, $content);

        $this->info("Arabic file updated: {$arPath}");
        $this->info("Summary: translated={$translatedCount}, failed={$failedCount}, total_missing=".count($missing));

        return $failedCount > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $en
     * @param  array<string, mixed>  $ar
     * @return list<string>
     */
    private function collectMissingLeafPaths(array $en, array $ar, string $prefix = ''): array
    {
        $missing = [];

        foreach ($en as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            $hasArKey = array_key_exists($key, $ar);
            $arValue = $hasArKey ? $ar[$key] : null;

            if (is_array($value)) {
                $arNested = is_array($arValue) ? $arValue : [];
                $missing = array_merge($missing, $this->collectMissingLeafPaths($value, $arNested, $path));
                continue;
            }

            if (! is_string($value)) {
                continue;
            }

            if (! $hasArKey || ! is_string($arValue) || trim($arValue) === '') {
                $missing[] = $path;
            }
        }

        return $missing;
    }

    private function translatePreservingTokens(string $source): ?string
    {
        $source = trim($source);
        if ($source === '') {
            return $source;
        }

        if (isset($this->cache[$source])) {
            return $this->cache[$source];
        }

        [$masked, $tokenMap] = $this->maskTokens($source);
        $translatedMasked = $this->googleTranslate($masked);
        if ($translatedMasked === null) {
            return null;
        }

        $translated = strtr($translatedMasked, $tokenMap);
        $this->cache[$source] = $translated;

        return $translated;
    }

    /**
     * @return array{0:string,1:array<string,string>}
     */
    private function maskTokens(string $text): array
    {
        $index = 0;
        $tokenMap = [];
        $patterns = [
            '/:[A-Za-z_][A-Za-z0-9_]*/',  // Laravel placeholders: :name
            '/\{[^}]+\}/',                // Curly placeholders: {name}
            '/<[^>]+>/',                  // HTML tags
        ];

        foreach ($patterns as $pattern) {
            $text = preg_replace_callback($pattern, function (array $match) use (&$index, &$tokenMap): string {
                $placeholder = "__TOKEN_{$index}__";
                $tokenMap[$placeholder] = $match[0];
                $index++;
                return $placeholder;
            }, $text) ?? $text;
        }

        return [$text, $tokenMap];
    }

    private function googleTranslate(string $text): ?string
    {
        try {
            $response = Http::timeout(20)->retry(2, 300)->get(
                'https://translate.googleapis.com/translate_a/single',
                [
                    'client' => 'gtx',
                    'sl' => 'en',
                    'tl' => 'ar',
                    'dt' => 't',
                    'q' => $text,
                ]
            );

            if (! $response->ok()) {
                return null;
            }

            $payload = $response->json();
            if (! is_array($payload) || ! isset($payload[0]) || ! is_array($payload[0])) {
                return null;
            }

            $parts = [];
            foreach ($payload[0] as $segment) {
                if (is_array($segment) && isset($segment[0]) && is_string($segment[0])) {
                    $parts[] = $segment[0];
                }
            }

            $joined = trim(implode('', $parts));
            return $joined === '' ? null : $joined;
        } catch (\Throwable) {
            return null;
        }
    }

    private function ensureDirectoryExists(string $path): void
    {
        if (! is_dir($path)) {
            mkdir($path, 0777, true);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function buildPhpArrayFile(array $data): string
    {
        $export = $this->exportArray($data, 0);
        return "<?php\n\nreturn {$export};\n";
    }

    /**
     * @param  array<string, mixed>  $array
     */
    private function exportArray(array $array, int $indentLevel): string
    {
        if ($array === []) {
            return '[]';
        }

        $indent = str_repeat('    ', $indentLevel);
        $nextIndent = str_repeat('    ', $indentLevel + 1);
        $lines = ["["];

        foreach ($array as $key => $value) {
            $encodedKey = "'".$this->escapeString((string) $key)."'";
            if (is_array($value)) {
                $encodedValue = $this->exportArray($value, $indentLevel + 1);
            } elseif (is_string($value)) {
                $encodedValue = "'".$this->escapeString($value)."'";
            } elseif (is_bool($value)) {
                $encodedValue = $value ? 'true' : 'false';
            } elseif ($value === null) {
                $encodedValue = 'null';
            } else {
                $encodedValue = (string) $value;
            }

            $lines[] = "{$nextIndent}{$encodedKey} => {$encodedValue},";
        }

        $lines[] = "{$indent}]";
        return implode("\n", $lines);
    }

    private function escapeString(string $value): string
    {
        return str_replace(
            ["\\", "'", "\r", "\n", "\t"],
            ["\\\\", "\\'", "\\r", "\\n", "\\t"],
            $value
        );
    }
}
