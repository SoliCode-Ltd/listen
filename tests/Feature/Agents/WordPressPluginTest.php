<?php

namespace Tests\Feature\Agents;

use App\Models\AgentWidget;
use App\Services\Agents\WordPressPlugin;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

/**
 * The WordPress plugin itself: what the zip holds, that it follows WordPress's rules
 * (headers, readme, escaping, capability checks, translations), and that its own PHP
 * reads keys and page IDs strictly.
 */
class WordPressPluginTest extends TestCase
{
    private const KEY = 'lw_AbCdEfGhIjKlMnOpQrStUvWx1234';

    /** The functions that print safely: every echo and printf argument starts with one. */
    private const ESCAPERS = ['esc_html', 'esc_attr', 'esc_url', 'esc_html__', 'esc_attr__', 'wp_kses_post'];

    /** @return array<string, string> the plugin's files, by their path in its folder */
    private function files(string $key = self::KEY): array
    {
        return app(WordPressPlugin::class)->files(new AgentWidget(['public_key' => $key]));
    }

    /** @return array<string, string> the plugin's own PHP (not its translation files) */
    private function code(): array
    {
        return array_filter($this->files(), fn (string $name) => str_ends_with($name, '.php') && ! str_starts_with($name, 'languages/'), ARRAY_FILTER_USE_KEY);
    }

    /** @return array<string, string> the "Name: value" lines of a plugin header or readme */
    private function headers(string $text): array
    {
        preg_match_all('/^[ \t*]*([A-Za-z ]+):[ \t]*(.+)$/m', $text, $m);

        return array_combine(array_map('trim', $m[1]), array_map('trim', $m[2]));
    }

    /** @return list<array{0: int|string, 1: string}> the file's tokens, without whitespace and comments */
    private function tokens(string $php): array
    {
        $tokens = [];
        foreach (token_get_all($php) as $token) {
            $token = is_array($token) ? [$token[0], $token[1]] : [$token, $token];
            if (! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $tokens[] = $token;
            }
        }

        return $tokens;
    }

    /**
     * Every call to a function by name in the file: its name and its top-level arguments.
     *
     * @return list<array{name: string, args: list<list<array{0: int|string, 1: string}>>}>
     */
    private function calls(string $php): array
    {
        $tokens = $this->tokens($php);
        $calls = [];
        foreach ($tokens as $i => [$type, $text]) {
            $before = $tokens[$i - 1][0] ?? null;
            if ($type !== T_STRING || ($tokens[$i + 1][1] ?? null) !== '(' || in_array($before, [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NULLSAFE_OBJECT_OPERATOR], true)) {
                continue;
            }
            $args = [[]];
            for ($j = $i + 2, $depth = 1; $j < count($tokens); $j++) {
                $t = $tokens[$j][1];
                $depth += in_array($t, ['(', '[', '{'], true) ? 1 : (in_array($t, [')', ']', '}'], true) ? -1 : 0);
                if ($depth === 0) {
                    break;
                }
                if ($depth === 1 && $t === ',') {
                    $args[] = [];

                    continue;
                }
                $args[count($args) - 1][] = $tokens[$j];
            }
            $calls[] = ['name' => strtolower($text), 'args' => array_values(array_filter($args))];
        }

        return $calls;
    }

    private static function literal(array $arg): ?string
    {
        return count($arg) === 1 && $arg[0][0] === T_CONSTANT_ENCAPSED_STRING ? stripcslashes(substr($arg[0][1], 1, -1)) : null;
    }

    public function test_the_zip_holds_the_whole_plugin_in_its_own_folder(): void
    {
        $path = app(WordPressPlugin::class)->build(new AgentWidget(['public_key' => self::KEY]));
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();
        @unlink($path);

        sort($names);
        $this->assertSame(array_map(fn ($n) => 'listen-voice-agent/'.$n, [
            'languages/listen-voice-agent-ar.l10n.php',
            'languages/listen-voice-agent-ary.l10n.php',
            'listen-voice-agent.php',
            'readme.txt',
            'src/Placement.php',
            'src/Plugin.php',
            'src/Settings.php',
            'uninstall.php',
        ]), $names);
    }

    public function test_every_php_file_is_valid_strict_and_refuses_to_run_on_its_own(): void
    {
        foreach ($this->files() as $name => $php) {
            if (! str_ends_with($name, '.php')) {
                continue;
            }
            $file = tempnam(sys_get_temp_dir(), 'lvp');
            file_put_contents($file, $php);
            exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($file).' 2>&1', $out, $code);
            @unlink($file);
            $this->assertSame(0, $code, $name.': '.implode("\n", $out));
        }

        foreach ($this->code() as $name => $php) {
            $this->assertStringContainsString("declare(strict_types=1);\n\nnamespace Listen\\VoiceAgent;", $php, $name);
            $guard = $name === 'uninstall.php' ? "if (!defined('WP_UNINSTALL_PLUGIN')) {\n    exit;\n}" : "if (!defined('ABSPATH')) {\n    exit;\n}";
            $this->assertStringContainsString($guard, $php, $name);
            // Nothing runs before the guard: only the namespace and its imports come first.
            $before = preg_replace(['#/\*.*?\*/#s', '/<\?php|declare\(strict_types=1\);|namespace [A-Za-z\\\\]+;|use [A-Za-z_\\\\]+;/'], '', substr($php, 0, strpos($php, $guard)));
            $this->assertSame('', trim($before), $name.': code before the guard');
        }
    }

    public function test_its_header_and_readme_follow_wordpress(): void
    {
        $files = $this->files();
        $main = $this->headers(substr($files['listen-voice-agent.php'], 0, strpos($files['listen-voice-agent.php'], '*/')));
        $this->assertSame('Listen Voice Agent', $main['Plugin Name']);
        $this->assertSame(WordPressPlugin::VERSION, $main['Version']);
        $this->assertSame('6.5', $main['Requires at least']);
        $this->assertSame('8.1', $main['Requires PHP']);
        $this->assertSame('GPL-2.0-or-later', $main['License']);
        $this->assertSame('listen-voice-agent', $main['Text Domain']);
        $this->assertSame('/languages', $main['Domain Path']);
        // A plugin of the same name elsewhere can never "update" this one.
        $this->assertSame('false', $main['Update URI']);
        $this->assertStringContainsString("const VERSION = '".WordPressPlugin::VERSION."';", $files['listen-voice-agent.php']);

        $readme = $files['readme.txt'];
        $this->assertStringStartsWith("=== Listen Voice Agent ===\n", $readme);
        $head = $this->headers(strstr($readme, "\n\n", true));
        $this->assertSame($main['Requires at least'], $head['Requires at least']);
        $this->assertSame($main['Requires PHP'], $head['Requires PHP']);
        $this->assertSame(WordPressPlugin::VERSION, $head['Stable tag']);
        $this->assertSame('GPLv2 or later', $head['License']);
        $this->assertMatchesRegularExpression('/\A\d+\.\d+\z/', $head['Tested up to']);
        $this->assertTrue(version_compare($head['Tested up to'], '7.1', '>='));
        $this->assertLessThanOrEqual(5, count(explode(',', $head['Tags'])));

        $short = trim(explode("\n\n", $readme)[1]);
        $this->assertLessThanOrEqual(150, mb_strlen($short));
        $this->assertStringNotContainsString('<', $short);
        foreach (['Description', 'Installation', 'Frequently Asked Questions', 'External services', 'Changelog', 'Upgrade Notice'] as $section) {
            $this->assertStringContainsString("\n== {$section} ==\n", $readme);
        }
        $this->assertMatchesRegularExpression('/== Changelog ==\n\n= '.preg_quote(WordPressPlugin::VERSION).' =\n/', $readme);
        preg_match('/== Upgrade Notice ==\n\n= '.preg_quote(WordPressPlugin::VERSION).' =\n(.+)\n/', $readme, $notice);
        $this->assertNotEmpty($notice);
        $this->assertLessThanOrEqual(300, mb_strlen($notice[1]));
        $this->assertStringContainsString(route('legal.privacy'), $readme);
    }

    public function test_it_loads_the_button_the_wordpress_way_and_escapes_everything_it_prints(): void
    {
        $plugin = $this->files()['src/Plugin.php'];
        $this->assertStringContainsString("wp_enqueue_script(self::SLUG, SCRIPT_URL, [], VERSION, ['in_footer' => true, 'strategy' => 'async']);", $plugin);
        $this->assertStringContainsString("add_filter('wp_script_attributes', [\$this, 'add_button_attributes']);", $plugin);
        $this->assertStringContainsString("\$attributes['data-agent'] = Settings::load(AGENT_KEY)->key;", $plugin);

        foreach ($this->code() as $name => $php) {
            $this->assertStringNotContainsString('<script', $php, $name);
            $tokens = $this->tokens($php);
            foreach ($tokens as $i => [$type]) {
                $this->assertNotContains($type, [T_EVAL, T_INCLUDE, T_REQUIRE], $name.': no eval and no dynamic include');
                if ($type === T_VARIABLE) {
                    $this->assertNotContains($tokens[$i][1], ['$_GET', '$_POST', '$_REQUEST', '$_COOKIE', '$_SERVER', '$_FILES', '$GLOBALS'], $name.': the Settings API reads the form');
                }
                // Whatever is echoed is escaped first.
                if (in_array($type, [T_ECHO, T_PRINT, T_OPEN_TAG_WITH_ECHO], true)) {
                    $this->assertContains(strtolower($tokens[$i + 1][1]), self::ESCAPERS, $name.': echo '.$tokens[$i + 1][1]);
                }
            }
            foreach ($this->calls($php) as $call) {
                $this->assertNotContains($call['name'], [
                    'assert', 'create_function', 'base64_decode', 'exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen',
                    'file_put_contents', 'file_get_contents', 'fopen', 'fwrite', 'unlink', 'rename', 'copy', 'move_uploaded_file', 'unserialize', 'extract',
                    'wp_remote_get', 'wp_remote_post', 'wp_remote_request', 'wp_safe_remote_get', 'wp_safe_remote_post', 'curl_init', '_e', '_ex', 'wp_print_script_tag',
                ], $name.': '.$call['name']);
                if (in_array($call['name'], ['printf', 'vprintf'], true)) {
                    $this->assertNotNull(self::literal($call['args'][0]), $name.': printf takes a fixed format');
                    foreach (array_slice($call['args'], 1) as $arg) {
                        $this->assertContains(strtolower($arg[0][1]), self::ESCAPERS, $name.': printf argument '.$arg[0][1]);
                    }
                }
            }
            // The only files it loads are its own, by a fixed path.
            preg_match_all('/require_once (.+);/', $php, $requires);
            foreach ($requires[1] as $path) {
                $this->assertMatchesRegularExpression("#\A__DIR__ \. '/src/[A-Za-z]+\.php'\z#", $path);
            }
        }
    }

    public function test_only_administrators_reach_and_save_the_settings(): void
    {
        $plugin = $this->files()['src/Plugin.php'];

        $this->assertMatchesRegularExpression("/add_options_page\([^;]*'manage_options',/s", $plugin);
        $this->assertMatchesRegularExpression("/function render_settings_page\(\): void\n    \{\n        if \(!current_user_can\('manage_options'\)\) \{\n            wp_die\(/", $plugin);
        $this->assertStringContainsString("add_filter('option_page_capability_' . Settings::GROUP, [\$this, 'settings_capability']);", $plugin);
        $this->assertMatchesRegularExpression("/function settings_capability\(\): string\n    \{\n        return 'manage_options';/", $plugin);
        // The form goes through options.php with the Settings API's nonce, and each setting has its own check.
        $this->assertStringContainsString('settings_fields(Settings::GROUP);', $plugin);
        $this->assertSame(4, substr_count($plugin, "'sanitize_callback' => [\$this, 'sanitize_"));
        $this->assertStringContainsString("\$args + ['show_in_rest' => false]", $plugin);
        // Saving is refused in the dashboard to anyone who is not an administrator, whatever path the change takes.
        $this->assertStringContainsString("return !is_admin() || current_user_can('manage_options');", $plugin);
        $this->assertSame(4, substr_count($plugin, '$this->may_change_settings()'));

        // In the dashboard the plugin adds its page, its link and a one-time notice, nothing else.
        $admin = substr($plugin, strpos($plugin, 'if (is_admin()) {'));
        foreach (["add_action('admin_menu'", "add_action('load-plugins.php'", "add_filter('plugin_action_links_'"] as $hook) {
            $this->assertStringContainsString($hook, strstr($admin, "\n        }\n", true));
        }
        $this->assertStringNotContainsString('admin_enqueue_scripts', $plugin);
        $this->assertStringNotContainsString("add_action('admin_init'", $plugin);
    }

    public function test_every_word_has_its_arabic_and_the_language_files_are_wordpress_files(): void
    {
        $files = $this->files();
        $english = [];
        foreach ($this->code() as $name => $php) {
            foreach ($this->calls($php) as $call) {
                if (! in_array($call['name'], ['__', 'esc_html__', 'esc_html_e', 'esc_attr__', 'esc_attr_e', '_x', '_n', 'esc_html_x', 'esc_attr_x'], true)) {
                    continue;
                }
                $this->assertSame('listen-voice-agent', self::literal(end($call['args'])), $name.': text domain');
                $english[] = self::literal($call['args'][0]);
            }
        }
        $header = $this->headers(substr($files['listen-voice-agent.php'], 0, strpos($files['listen-voice-agent.php'], '*/')));
        array_push($english, $header['Plugin Name'], $header['Description'], $header['Author']);
        $this->assertNotContains(null, $english, 'every translated text is written out in full');

        $arabic = json_decode((string) file_get_contents(resource_path('agents/wordpress/translations-ar.json')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertEqualsCanonicalizing(array_values(array_unique($english)), array_keys($arabic));
        foreach ($arabic as $en => $ar) {
            preg_match_all('/%(?:\d+\$)?[sd]/', $en, $a);
            preg_match_all('/%(?:\d+\$)?[sd]/', $ar, $b);
            $this->assertSame($a[0], $b[0], $en);
            $this->assertDoesNotMatchRegularExpression('/[<>]/', $ar, $en);
        }
        $this->assertSame('إسمع', $arabic['Listen']);
        $this->assertSame('وكيل إسمع الصوتي', $arabic['Listen Voice Agent']);

        foreach (['ar', 'ary'] as $locale) {
            $file = tempnam(sys_get_temp_dir(), 'lvl');
            file_put_contents($file, $files["languages/listen-voice-agent-{$locale}.l10n.php"]);
            $data = include $file;
            @unlink($file);
            $this->assertSame($locale, $data['language']);
            $this->assertStringStartsWith('nplurals=6;', $data['plural-forms']);
            $this->assertSame($arabic, $data['messages']);
        }
    }

    public function test_uninstalling_removes_every_setting_the_plugin_keeps(): void
    {
        $files = $this->files();
        preg_match_all("/public const (ENABLED|KEY|PLACEMENT|PAGES) = '([a-z_]+)';/", $files['src/Settings.php'], $options);
        $this->assertCount(4, $options[2]);
        $this->assertStringContainsString('public const OPTIONS = [self::ENABLED, self::KEY, self::PLACEMENT, self::PAGES];', $files['src/Settings.php']);
        $this->assertStringContainsString("foreach (['".implode("', '", $options[2])."'] as \$option) {\n        delete_option(\$option);", $files['uninstall.php']);
        $this->assertStringContainsString("delete_transient('listen_voice_agent_activated');", $files['uninstall.php']);
        $this->assertStringContainsString("const ACTIVATED = 'listen_voice_agent_activated';", $files['src/Plugin.php']);
        $this->assertStringContainsString("get_sites(['fields' => 'ids', 'number' => 0])", $files['uninstall.php']);
    }

    public function test_the_plugin_names_no_other_company(): void
    {
        foreach ($this->files() as $name => $text) {
            foreach (['Gemini', 'Google', 'ElevenLabs', 'Eleven Labs', 'OpenAI', 'Twilio', 'Vapi', 'Retell', 'Shopify', 'Wix', 'Salla', 'Zid', 'Laravel', 'Cloudways', 'Stripe'] as $company) {
                $this->assertStringNotContainsString($company, $text, $name.': '.$company);
            }
        }
    }

    public function test_its_own_php_reads_keys_and_page_ids_strictly(): void
    {
        $dir = sys_get_temp_dir().'/lvp-'.bin2hex(random_bytes(6));
        mkdir($dir);
        $files = $this->files();
        file_put_contents("$dir/Placement.php", $files['src/Placement.php']);
        file_put_contents("$dir/Settings.php", $files['src/Settings.php']);
        // Just enough WordPress to run the plugin's settings code: options come from $GLOBALS['options'].
        file_put_contents("$dir/run.php", <<<'PHP'
            <?php
            define('ABSPATH', __DIR__.'/');
            function get_option($name, $default = false) { return array_key_exists($name, $GLOBALS['options']) ? $GLOBALS['options'][$name] : $default; }
            require __DIR__.'/Placement.php';
            require __DIR__.'/Settings.php';
            use Listen\VoiceAgent\Settings;
            $key = 'lw_AbCdEfGhIjKlMnOpQrStUvWx1234';
            $load = function (array $options) { $GLOBALS['options'] = $options; $s = Settings::load('lw_Bundled'.str_repeat('0', 21)); return [$s->enabled, $s->key, $s->placement->value, $s->pages]; };
            echo json_encode([
                'pages' => array_map([Settings::class, 'parse_pages'], ['12, 45 78', '١٢، ٤٥', '۱۲؛ 12', '', null, [3, '4'], '12, abc', '0', '-3', '1e3', '0x1A', ['x'], 12.5, true]),
                'keys' => array_map([Settings::class, 'is_key'], [$key, $key."\n", ' '.$key, 'lw_short', 'lw_AbCdEfGhIjKlMnOpQrStUvWx123"']),
                'fresh' => $load([]),
                'saved' => $load(['listen_voice_agent_enabled' => '0', 'listen_voice_agent_key' => $key, 'listen_voice_agent_placement' => 'pages', 'listen_voice_agent_pages' => [2, 7]]),
                'tampered' => $load(['listen_voice_agent_enabled' => 'yes', 'listen_voice_agent_key' => $key.'"><script>', 'listen_voice_agent_placement' => 'nowhere', 'listen_voice_agent_pages' => 'a:1:{}']),
                'too_many' => count($load(['listen_voice_agent_pages' => range(1, 150)])[3]),
            ]);
            PHP);
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg("$dir/run.php").' 2>&1', $out, $code);
        array_map('unlink', glob("$dir/*"));
        rmdir($dir);
        $this->assertSame(0, $code, implode("\n", $out));
        $r = json_decode(implode("\n", $out), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame([[12, 45, 78], [12, 45], [12], [], [], [3, 4], null, null, null, null, null, null, null, null], $r['pages']);
        $this->assertSame([true, false, false, false, false], $r['keys']);
        $bundled = 'lw_Bundled'.str_repeat('0', 21);
        $this->assertSame([true, $bundled, 'all', []], $r['fresh']);
        $this->assertSame([false, self::KEY, 'pages', [2, 7]], $r['saved']);
        $this->assertSame([false, $bundled, 'all', []], $r['tampered']);
        $this->assertSame(100, $r['too_many']);
    }

    public function test_it_is_never_built_without_a_real_key_or_with_an_address_that_could_break_out(): void
    {
        foreach (['', 'lw_short', 'lw_'.str_repeat('a', 27)."'", "lw_AbCdEfGhIjKlMnOpQrStUvWx1234\n"] as $key) {
            try {
                $this->files($key);
                $this->fail('Built with the key '.json_encode($key));
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }

        URL::forceRootUrl('https://example.com/*/');
        $this->expectException(RuntimeException::class);
        $this->files();
    }
}
