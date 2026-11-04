<?php

namespace Tests\Feature\Agents;

use App\Models\Agent;
use App\Models\AgentWidget;
use App\Services\Agents\WordPressPlugin;
use Illuminate\Support\Facades\URL;
use Mcamara\LaravelLocalization\Facades\LaravelLocalization;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

/**
 * The WordPress plugin itself: what the zip holds, that it follows WordPress's rules
 * (headers, readme, escaping, capability checks, translations), that its own PHP reads
 * keys and page IDs strictly, and that its settings page lists the website's pages to
 * tick (run against a small stand-in for WordPress).
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
            'assets/settings.css',
            'assets/settings.js',
            'languages/listen-voice-agent-ar.l10n.php',
            'languages/listen-voice-agent-ary.l10n.php',
            'listen-voice-agent.php',
            'readme.txt',
            'src/Pages.php',
            'src/Placement.php',
            'src/Plugin.php',
            'src/Settings.php',
            'src/SettingsPage.php',
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
        $files = $this->files();
        $plugin = $files['src/Plugin.php'];
        $page = $files['src/SettingsPage.php'];

        $this->assertMatchesRegularExpression("/add_options_page\([^;]*'manage_options',/s", $plugin);
        $this->assertMatchesRegularExpression("/function render\(\): void\n    \{\n        if \(!current_user_can\('manage_options'\)\) \{\n            wp_die\(/", $page);
        $this->assertStringContainsString("add_filter('option_page_capability_' . Settings::GROUP, [\$this, 'settings_capability']);", $plugin);
        $this->assertMatchesRegularExpression("/function settings_capability\(\): string\n    \{\n        return 'manage_options';/", $plugin);
        // The form goes through options.php with the Settings API's nonce, and each setting has its own check.
        $this->assertStringContainsString('<form action="options.php" method="post"', $page);
        $this->assertStringContainsString('settings_fields(Settings::GROUP);', $page);
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
        // Its style and script load on its own page only: WordPress runs load-{page} for that page alone.
        $this->assertStringContainsString("add_action('load-' . \$page, [\$screen, 'load']);", $plugin);
        $this->assertMatchesRegularExpression("/function load\(\): void\n    \{\n        add_action\('admin_enqueue_scripts', \[\\\$this, 'enqueue_assets'\]\);\n    \}/", $page);
        foreach ($this->code() as $name => $php) {
            $this->assertSame($name === 'src/SettingsPage.php' ? 1 : 0, substr_count($php, 'admin_enqueue_scripts'), $name);
            $this->assertStringNotContainsString("add_action('admin_init'", $php, $name);
        }
    }

    public function test_every_word_has_its_arabic_and_the_language_files_are_wordpress_files(): void
    {
        $files = $this->files();
        $placeholders = function (string $text): array {
            preg_match_all('/%(?:\d+\$)?[sd]/', $text, $m);

            return $m[0];
        };
        // Every text the code translates, with its plural when it says a number.
        $english = [];
        foreach ($this->code() as $name => $php) {
            foreach ($this->calls($php) as $call) {
                if (in_array($call['name'], ['__', 'esc_html__', 'esc_html_e', 'esc_attr__', 'esc_attr_e'], true)) {
                    $this->assertCount(2, $call['args'], $name.': '.$call['name'].' takes the text and the text domain');
                    [$text, $plural] = [self::literal($call['args'][0]), null];
                } elseif ($call['name'] === '_n') {
                    $this->assertCount(4, $call['args'], $name.': _n takes the singular, the plural, the number and the text domain');
                    [$text, $plural] = [self::literal($call['args'][0]), self::literal($call['args'][1])];
                    $this->assertNotNull($plural, $name.': the plural is written out in full');
                    $this->assertSame($placeholders((string) $text), $placeholders((string) $plural), (string) $text);
                } elseif (in_array($call['name'], ['_x', '_nx', '_n_noop', '_nx_noop', 'esc_html_x', 'esc_attr_x'], true)) {
                    $this->fail($name.': '.$call['name'].' is not checked here, so the plugin does not use it');
                } else {
                    continue;
                }
                $this->assertSame('listen-voice-agent', self::literal(end($call['args'])), $name.': text domain');
                $this->assertNotNull($text, $name.': every translated text is written out in full');
                $this->assertTrue(! array_key_exists($text, $english) || $english[$text] === $plural, $text.': the same words, once with a plural and once without');
                $english[$text] = $plural;
            }
        }
        $header = $this->headers(substr($files['listen-voice-agent.php'], 0, strpos($files['listen-voice-agent.php'], '*/')));
        foreach ([$header['Plugin Name'], $header['Description'], $header['Author']] as $text) {
            $english[$text] ??= null;
        }

        $arabic = json_decode((string) file_get_contents(resource_path('agents/wordpress/translations-ar.json')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertEqualsCanonicalizing(array_keys($english), array_keys($arabic));
        $messages = [];
        foreach ($arabic as $en => $ar) {
            if ($english[$en] === null) {
                $this->assertIsString($ar, $en);
                $this->assertSame($placeholders($en), $placeholders($ar), $en);
                $this->assertDoesNotMatchRegularExpression('/[<>]/', $ar, $en);
                $messages[$en] = $ar;

                continue;
            }
            // A text with a number: the English plural the code uses, and Arabic's six forms (none, one,
            // two, a few, many, a hundred and more). One, two and none are said in words; the others say the number.
            $this->assertSame(['plural', 'forms'], array_keys($ar), $en);
            $this->assertSame($english[$en], $ar['plural'], $en);
            $this->assertCount(6, $ar['forms'], $en);
            foreach ($ar['forms'] as $form => $words) {
                $this->assertContains($placeholders($words), $form < 3 ? [[], $placeholders($en)] : [$placeholders($en)], $en.': form '.$form);
                $this->assertDoesNotMatchRegularExpression('/[<>]/', $words, $en);
            }
            $messages[$en."\0".$ar['plural']] = implode("\0", $ar['forms']);
        }
        $this->assertSame('إسمع', $arabic['Listen']);
        $this->assertSame('وكيل إسمع الصوتي', $arabic['Listen Voice Agent']);
        $this->assertSame('اخترت 3 صفحات', str_replace('%d', '3', $arabic['%d page selected']['forms'][3]));
        $this->assertSame('اخترت 11 صفحة', str_replace('%d', '11', $arabic['%d page selected']['forms'][4]));

        foreach (['ar', 'ary'] as $locale) {
            $file = tempnam(sys_get_temp_dir(), 'lvl');
            file_put_contents($file, $files["languages/listen-voice-agent-{$locale}.l10n.php"]);
            $data = include $file;
            @unlink($file);
            $this->assertSame($locale, $data['language']);
            $this->assertStringStartsWith('nplurals=6;', $data['plural-forms']);
            // WordPress finds a text with a number by "singular\0plural", and splits its forms at "\0".
            $this->assertSame($messages, $data['messages']);
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
                // What the form sends: ticked boxes (anything in them that is not an ID is left out), or IDs typed in 1.1.0's form.
                'chosen' => array_map([Settings::class, 'chosen_pages'], [['12', '45', '12', 'abc', '0', '-3', '1e3', ['7'], 9.5, true, '٤٥', 78], [], '12, 45', '12, abc', null, 12.5]),
                'keys' => array_map([Settings::class, 'is_key'], [$key, $key."\n", ' '.$key, 'lw_short', 'lw_AbCdEfGhIjKlMnOpQrStUvWx123"']),
                'fresh' => $load([]),
                'saved' => $load(['listen_voice_agent_enabled' => '0', 'listen_voice_agent_key' => $key, 'listen_voice_agent_placement' => 'pages', 'listen_voice_agent_pages' => [2, 7]]),
                'typed' => $load(['listen_voice_agent_placement' => 'pages', 'listen_voice_agent_pages' => '2, 7']),
                'tampered' => $load(['listen_voice_agent_enabled' => 'yes', 'listen_voice_agent_key' => $key.'"><script>', 'listen_voice_agent_placement' => 'nowhere', 'listen_voice_agent_pages' => 'a:1:{}']),
                'too_many' => count($load(['listen_voice_agent_pages' => range(1, 600)])[3]),
            ]);
            PHP);
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg("$dir/run.php").' 2>&1', $out, $code);
        array_map('unlink', glob("$dir/*"));
        rmdir($dir);
        $this->assertSame(0, $code, implode("\n", $out));
        $r = json_decode(implode("\n", $out), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame([[12, 45, 78], [12, 45], [12], [], [], [3, 4], null, null, null, null, null, null, null, null], $r['pages']);
        $this->assertSame([[12, 45, 78], [], [12, 45], null, [], null], $r['chosen']);
        $this->assertSame([true, false, false, false, false], $r['keys']);
        $bundled = 'lw_Bundled'.str_repeat('0', 21);
        $this->assertSame([true, $bundled, 'all', []], $r['fresh']);
        $this->assertSame([false, self::KEY, 'pages', [2, 7]], $r['saved']);
        $this->assertSame([true, $bundled, 'pages', [2, 7]], $r['typed']);
        $this->assertSame([false, $bundled, 'all', []], $r['tampered']);
        $this->assertSame(500, $r['too_many']);
    }

    public function test_the_settings_page_lists_the_pages_to_tick_instead_of_asking_for_their_ids(): void
    {
        $files = $this->files();
        $page = $files['src/SettingsPage.php'];
        $script = $files['assets/settings.js'];
        $style = $files['assets/settings.css'];

        // One checkbox per page, saved as a list of IDs; nothing asks for IDs any more.
        $this->assertStringContainsString('<input type="checkbox" name="<?php echo esc_attr(Settings::PAGES); ?>[]" value="<?php echo esc_attr((string) $item[\'id\']); ?>"', $page);
        foreach (['Page IDs', 'post=', 'Separate page IDs'] as $gone) {
            $this->assertStringNotContainsString($gone, implode("\n", $files), $gone);
        }
        // The search, "Select all", "Clear" and the count are on the page, and the script drives them.
        foreach (['data-lva-picker', 'data-lva-search', 'data-lva-all', 'data-lva-clear', 'data-lva-count', 'data-lva-row', 'data-lva-group', 'data-lva-empty'] as $hook) {
            $this->assertStringContainsString($hook, $page, $hook);
            $this->assertStringContainsString('['.$hook.']', $script, $hook);
        }
        // Its words come from the page, in the dashboard's language.
        $this->assertStringContainsString('data-lva-words="<?php echo esc_attr((string) wp_json_encode($this->picker_words()', $page);
        $this->assertStringContainsString("picker.getAttribute('data-lva-words')", $script);
        $this->assertStringContainsString('aria-live="polite"', $page);
        // The list shows with "Only on the pages I choose" only, by style (and by script where :has() is missing).
        $this->assertStringContainsString('.lva-where:has([data-lva-placement="Everywhere"]:checked) .lva-picker', $style);
        $this->assertStringContainsString("=== 'Everywhere'", $script);

        // The style and script are the plugin's own files, from its own folder, on its own page only.
        $this->assertStringContainsString("\$assets = plugin_dir_url(\$this->file) . 'assets/';", $page);
        $this->assertStringContainsString("wp_enqueue_style(self::ASSETS, \$assets . 'settings.css', [], VERSION);", $page);
        $this->assertStringContainsString("wp_enqueue_script(self::ASSETS, \$assets . 'settings.js', [], VERSION, ['in_footer' => true, 'strategy' => 'defer']);", $page);
        // The script writes text only (never HTML), runs nothing it builds from text, and fetches nothing.
        foreach (['innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write', 'eval(', 'new Function', 'setTimeout(', 'fetch(', 'XMLHttpRequest', 'import(', 'http', 'localStorage'] as $unsafe) {
            $this->assertStringNotContainsString($unsafe, $script, $unsafe);
        }
        $this->assertStringContainsString('.textContent =', $script);
        $this->assertStringContainsString("'use strict';", $script);
        // The style loads nothing: no fonts, no images, no other files.
        foreach (['@import', 'url(', 'http'] as $external) {
            $this->assertStringNotContainsString($external, $style, $external);
        }
        // It reads right to left: no left or right in the style except where Arabic needs it said.
        preg_match_all('/^\s*(?:margin|padding|border)-(?:left|right)\b|^\s*(?:left|right)\s*:|text-align:\s*(?:left|right)/m', $style, $physical);
        $this->assertSame(['text-align: right'], array_map('trim', $physical[0]));
        $this->assertStringContainsString(".rtl .lva-row-path bdi {\n\ttext-align: right;", $style);
        // Listen's mark is drawn on the page: seven rounded bars in Listen's colour, no image to load.
        $this->assertStringContainsString('fill="#7659FA"', $page);
        $this->assertSame(7, substr_count($page, '<rect '));
    }

    public function test_it_links_to_the_agents_own_page_in_listen(): void
    {
        $agent = new Agent(['public_id' => 'agt_'.str_repeat('a', 24)]);
        $widget = new AgentWidget(['public_key' => self::KEY]);
        $widget->setRelation('agent', $agent);
        $main = app(WordPressPlugin::class)->files($widget)['listen-voice-agent.php'];

        // Without a language in the address, so Listen opens in the language its owner uses there.
        $page = LaravelLocalization::getNonLocalizedURL(route('voice-agents.show', ['agent' => $agent]));
        $this->assertStringEndsWith('/agents/agt_'.str_repeat('a', 24), $page);
        $this->assertStringContainsString("const AGENT_URL = '".$page."';", $main);
        $this->assertStringContainsString("const MANAGE_URL = '".LaravelLocalization::getNonLocalizedURL(route('voice-agents.index'))."';", $main);
        // A copy built without its agent links to the list of agents instead.
        $this->assertStringContainsString("const AGENT_URL = '".LaravelLocalization::getNonLocalizedURL(route('voice-agents.index'))."';", $this->files()['listen-voice-agent.php']);
        // "Change the look in Listen" opens the agent's Design tab; another agent's key leads to the list of agents.
        $settingsPage = $this->files()['src/SettingsPage.php'];
        $this->assertStringContainsString("\$this->render_link(\$this->listen_url('design'), __('Change the look in Listen', 'listen-voice-agent'));", $settingsPage);
        $this->assertStringContainsString("if ((\$key !== '' && \$key !== AGENT_KEY) || AGENT_URL === MANAGE_URL) {\n            return MANAGE_URL;", $settingsPage);
    }

    /**
     * Runs the plugin's own PHP against a stand-in for the parts of WordPress it uses (its
     * pages live in $GLOBALS['posts']) and returns what the run printed as JSON.
     *
     * @return array<string, mixed>
     */
    private function runInWordPress(string $run): array
    {
        $dir = sys_get_temp_dir().'/lvw-'.bin2hex(random_bytes(6));
        mkdir($dir);
        foreach (['Placement', 'Settings', 'Pages', 'SettingsPage', 'Plugin'] as $class) {
            file_put_contents("$dir/$class.php", $this->files()["src/$class.php"]);
        }
        file_put_contents("$dir/wordpress.php", <<<'PHP'
            <?php
            // Just enough WordPress for the plugin's settings, its settings page and its button.
            define('ABSPATH', __DIR__.'/');
            define('Listen\VoiceAgent\VERSION', '1.2.0');
            define('Listen\VoiceAgent\AGENT_KEY', 'lw_Bundled000000000000000000000');
            define('Listen\VoiceAgent\SCRIPT_URL', 'https://listen.test/js/listen-agent.js');
            define('Listen\VoiceAgent\MANAGE_URL', 'https://listen.test/agents');
            define('Listen\VoiceAgent\AGENT_URL', 'https://listen.test/agents/agt_aaaaaaaaaaaaaaaaaaaaaaaa');
            class WP_Post { public $ID = 0; public $post_title = ''; public $post_name = ''; public $post_type = 'page'; public $post_status = 'publish'; public $post_parent = 0; public $menu_order = 0; public $post_date = '2026-01-01';
                public function __construct(array $fields) { foreach ($fields as $name => $value) { $this->$name = $value; } } }
            foreach (['options' => [], 'posts' => [], 'is_admin' => true, 'can' => true, 'errors' => [], 'scripts' => [], 'queried' => null] as $name => $value) { $GLOBALS[$name] = $value; }
            function post(int $id, string $title, string $slug, string $type = 'page', string $status = 'publish', array $more = []): void { $GLOBALS['posts'][$id] = new WP_Post(['ID' => $id, 'post_title' => $title, 'post_name' => $slug, 'post_type' => $type, 'post_status' => $status] + $more); }
            function get_option($name, $default = false) { return array_key_exists($name, $GLOBALS['options']) ? $GLOBALS['options'][$name] : $default; }
            function get_posts($args = []) {
                $types = (array) ($args['post_type'] ?? 'post');
                $posts = array_filter($GLOBALS['posts'], fn ($p) => in_array($p->post_type, $types, true) && $p->post_status === ($args['post_status'] ?? 'publish'));
                if (isset($args['post__in'])) { $in = array_map('intval', $args['post__in']); $posts = array_filter($posts, fn ($p) => in_array($p->ID, $in, true)); }
                $order = $args['orderby'] ?? null;
                if ($order === 'post__in') { usort($posts, fn ($a, $b) => array_search($a->ID, $in, true) <=> array_search($b->ID, $in, true)); }
                if (is_array($order) && isset($order['menu_order'])) { usort($posts, fn ($a, $b) => [$a->menu_order, $a->post_title] <=> [$b->menu_order, $b->post_title]); }
                if (is_array($order) && isset($order['date'])) { usort($posts, fn ($a, $b) => strcmp($b->post_date, $a->post_date)); }
                $posts = array_slice(array_values($posts), 0, (int) ($args['posts_per_page'] ?? 5));
                return ($args['fields'] ?? '') === 'ids' ? array_map(fn ($p) => $p->ID, $posts) : $posts;
            }
            function get_post_types($args = []) { return ['post' => 'post', 'page' => 'page', 'attachment' => 'attachment', 'product' => 'product']; }
            function post_type_exists($type) { return in_array($type, ['post', 'page', 'attachment', 'product'], true); }
            function get_post_type_object($type) { return (object) ['labels' => (object) ['name' => ['page' => 'Pages', 'product' => 'Products'][$type] ?? ucfirst($type)]]; }
            function get_permalink($post = 0) { $post = $post instanceof WP_Post ? $post : ($GLOBALS['posts'][(int) $post] ?? null); return $post === null ? false : ($post->ID === (int) get_option('page_on_front') ? 'https://site.test/' : 'https://site.test/'.$post->post_name.'/'); }
            function get_post_states($post) { return $post->ID === (int) get_option('page_on_front') ? ['page_on_front' => 'Front Page'] : []; }
            function wp_make_link_relative($link) { return preg_replace('|^(https?:)?//[^/]+(/?.*)|i', '$2', $link); }
            function untrailingslashit($value) { return rtrim($value, '/\\'); }
            function wp_strip_all_tags($text) { return trim(strip_tags($text)); }
            function home_url($path = '') { return 'https://site.test'.$path; }
            function is_admin() { return $GLOBALS['is_admin']; }
            function current_user_can($capability) { return $GLOBALS['can']; }
            function add_settings_error($setting, $code, $message, $type = 'error') { $GLOBALS['errors'][] = ['code' => $code, 'message' => $message]; }
            function get_settings_errors($setting = '') { return $GLOBALS['errors']; }
            function __($text, $domain = 'default') { return $text; }
            function _n($single, $plural, $number, $domain = 'default') { return $number === 1 ? $single : $plural; }
            function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
            function esc_attr($text) { return htmlspecialchars((string) $text, ENT_QUOTES); }
            function esc_url($url) { return htmlspecialchars((string) $url, ENT_QUOTES); }
            function esc_html__($text, $domain = 'default') { return esc_html($text); }
            function esc_html_e($text, $domain = 'default') { echo esc_html($text); }
            function esc_attr_e($text, $domain = 'default') { echo esc_attr($text); }
            function checked($checked, $current = true, $display = true) { if ((string) $checked === (string) $current) { echo " checked='checked'"; } }
            function get_admin_page_title() { return 'Listen Voice Agent'; }
            function settings_fields($group) { echo '<input type="hidden" name="option_page" value="'.esc_attr($group).'">'; }
            function submit_button() { echo '<input type="submit" id="submit">'; }
            function wp_die($message = '', $code = 0) { throw new RuntimeException('wp_die: '.$message); }
            function determine_locale() { return 'en_US'; }
            function is_textdomain_loaded($domain) { return false; }
            function wp_json_encode($data, $flags = 0) { return json_encode($data, $flags); }
            function register_activation_hook($file, $callback) {}
            function add_action($hook, $callback, $priority = 10, $args = 1) {}
            function add_filter($hook, $callback, $priority = 10, $args = 1) {}
            function apply_filters($hook, $value) { return $value; }
            function plugin_basename($file) { return 'listen-voice-agent/listen-voice-agent.php'; }
            function get_queried_object() { return $GLOBALS['queried']; }
            function is_post_type_archive($type = '') { return false; }
            function wp_enqueue_script($handle, $src = '', $deps = [], $ver = false, $args = []) { $GLOBALS['scripts'][] = $handle; }
            foreach (['Placement', 'Settings', 'Pages', 'SettingsPage', 'Plugin'] as $class) { require __DIR__."/$class.php"; }
            PHP);
        file_put_contents("$dir/run.php", "<?php\nrequire __DIR__.'/wordpress.php';\n".$run);
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg("$dir/run.php").' 2>&1', $out, $code);
        array_map('unlink', glob("$dir/*"));
        rmdir($dir);
        $this->assertSame(0, $code, implode("\n", $out));

        return json_decode(implode("\n", $out), true, 512, JSON_THROW_ON_ERROR);
    }

    /** A small clinic's website, as the stand-in WordPress holds it. */
    private const SITE = <<<'PHP'
        use Listen\VoiceAgent\Plugin;
        use Listen\VoiceAgent\Settings;
        use Listen\VoiceAgent\SettingsPage;
        $GLOBALS['options'] = ['show_on_front' => 'page', 'page_on_front' => 7];
        post(7, 'Home', 'home');
        post(8, 'About', 'about', 'page', 'publish', ['menu_order' => 1]);
        post(9, 'Services', 'services', 'page', 'publish', ['menu_order' => 2]);
        post(20, 'Teeth whitening', 'teeth-whitening', 'page', 'publish', ['post_parent' => 9]);
        post(10, 'Prices', 'prices', 'page', 'publish', ['menu_order' => 3]);
        post(11, 'Book an appointment', 'book-an-appointment', 'page', 'publish', ['menu_order' => 4]);
        post(13, 'Contact', 'contact', 'page', 'publish', ['menu_order' => 6]);
        post(19, 'اتصل بنا', '%d8%a7%d8%aa%d8%b5%d9%84-%d8%a8%d9%86%d8%a7', 'page', 'publish', ['menu_order' => 7]);
        post(14, 'Old offer', 'old-offer', 'page', 'draft');
        post(17, 'Removed', 'removed', 'page', 'trash');
        post(16, 'photo.jpg', 'photo', 'attachment', 'inherit');
        post(15, 'What to expect at your first visit', 'first-visit', 'post', 'publish', ['post_date' => '2026-11-01']);
        post(18, 'Whitening kit', 'whitening-kit', 'product');
        $plugin = Plugin::boot(__DIR__.'/listen-voice-agent.php');
        PHP;

    public function test_saving_keeps_only_the_published_pages_that_were_ticked(): void
    {
        $r = $this->runInWordPress(self::SITE."\n".<<<'PHP'
            $GLOBALS['options']['listen_voice_agent_pages'] = [10];
            $save = fn ($value) => $plugin->sanitize_pages($value);
            $result = [
                // Ticked boxes: kept in order, each once; not pages, unpublished pages and media are left out.
                'ticked' => $save(['13', '11', '11', 'abc', '999', '14', '17', '16', '-4', ['x'], '15', '18']),
                // What the form of 1.1.0 sent (an open tab from before the update), Arabic digits too.
                'typed' => $save('13, 11'),
                'arabic' => $save('١٣، ١١'),
                'none' => $save(null),
                'nonsense' => $save('not ids'),
            ];
            // A limit, so the setting stays small: more than it, and nothing changes.
            foreach (range(1000, 1500) as $id) { post($id, 'Branch '.$id, 'branch-'.$id); }
            $result['limit'] = count($save(array_map('strval', range(1000, 1499))));
            $result['over'] = $save(array_map('strval', range(1000, 1500)));
            $result['errors'] = array_column($GLOBALS['errors'], 'code');
            // In the dashboard, only administrators change it; code outside the dashboard (the command line) may.
            $GLOBALS['can'] = false;
            $result['editor'] = $save(['13']);
            $GLOBALS['is_admin'] = false;
            $result['command_line'] = $save(['13']);
            echo json_encode($result);
            PHP);

        $this->assertSame([13, 11, 15, 18], $r['ticked']);
        $this->assertSame([13, 11], $r['typed']);
        $this->assertSame([13, 11], $r['arabic']);
        $this->assertSame([], $r['none']);
        $this->assertSame([10], $r['nonsense']);
        $this->assertSame(500, $r['limit']);
        $this->assertSame([10], $r['over']);
        $this->assertSame(['too_many_pages'], $r['errors']);
        $this->assertSame([10], $r['editor']);
        $this->assertSame([13], $r['command_line']);
    }

    public function test_the_settings_page_ticks_the_chosen_pages_even_when_they_were_typed_as_ids(): void
    {
        $r = $this->runInWordPress(self::SITE."\n".<<<'PHP'
            // Saved as 1.1.0's form saved what was typed in its box: IDs separated by commas.
            $GLOBALS['options'] += ['listen_voice_agent_placement' => 'pages', 'listen_voice_agent_pages' => '10, 11, 13'];
            ob_start();
            (new SettingsPage(__DIR__.'/listen-voice-agent.php'))->render();
            $html = ob_get_clean();
            $GLOBALS['can'] = false;
            try { (new SettingsPage(__DIR__.'/listen-voice-agent.php'))->render(); $refused = false; } catch (RuntimeException $e) { $refused = true; }
            echo json_encode(['html' => $html, 'pages' => Settings::load(Listen\VoiceAgent\AGENT_KEY)->pages, 'refused' => $refused]);
            PHP);
        $html = $r['html'];

        $this->assertSame([10, 11, 13], $r['pages']);
        $this->assertTrue($r['refused'], 'only administrators see the page');
        preg_match_all('/<input type="checkbox" name="listen_voice_agent_pages\[\]" value="(\d+)"\s*(checked=\'checked\')?>/', $html, $boxes, PREG_SET_ORDER);
        // Every published page (the home page first, a subpage after its page), the latest posts, then products.
        $this->assertSame(['7', '8', '9', '20', '10', '11', '13', '19', '15', '18'], array_column($boxes, 1));
        $ticked = array_values(array_map(fn ($box) => $box[1], array_filter($boxes, fn ($box) => ($box[2] ?? '') !== '')));
        $this->assertSame(['10', '11', '13'], $ticked);
        preg_match_all('/<h3 class="lva-group-title"[^>]*>([^<]+)<\/h3>/', $html, $groups);
        $this->assertSame(['Pages', 'Latest posts', 'Products'], $groups[1]);
        // Each page by its title and its address on the website, Arabic addresses in Arabic letters.
        foreach (['/', '/book-an-appointment', '/اتصل-بنا', '/whitening-kit'] as $path) {
            $this->assertStringContainsString('<span class="lva-row-path"><bdi dir="ltr">'.$path.'</bdi></span>', $html, $path);
        }
        $this->assertStringContainsString('<span class="lva-row-title" dir="auto">Home</span>', $html);
        $this->assertStringContainsString('<span class="lva-row-state">Front Page</span>', $html);
        foreach (['Old offer', 'Removed', 'photo.jpg'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $html, $hidden);
        }
        // The status and the count say how many, and the list has its search and its two buttons.
        $this->assertStringContainsString('Your call button is on and shows on 3 pages', $html);
        $this->assertMatchesRegularExpression('/<span class="lva-count" data-lva-count aria-live="polite">3 pages selected<\/span>/', $html);
        $this->assertStringContainsString('<input type="search" id="lva-search"', $html);
        $this->assertStringContainsString('<label class="screen-reader-text" for="lva-search">Search by title or address</label>', $html);
        $this->assertMatchesRegularExpression('/value="pages"\s+checked=\'checked\' data-lva-placement="ChosenPages">/', $html);
        $this->assertMatchesRegularExpression('/value="all"\s+data-lva-placement="Everywhere">/', $html);
        preg_match('/data-lva-words="([^"]+)"/', $html, $words);
        $words = json_decode(html_entity_decode($words[1], ENT_QUOTES), true);
        $this->assertSame(['lang', 'counts', 'none', 'noMatch'], array_keys($words));
        $this->assertSame(['%d page selected', '%d pages selected'], [$words['counts']['1'], $words['counts']['2']]);
        // The links: the website where the button shows (the home page is not chosen), and the agent's look in Listen.
        $this->assertStringContainsString('href="https://site.test/prices/" target="_blank" rel="noopener"><span>View your site</span>', $html);
        $this->assertStringContainsString('href="https://listen.test/agents/agt_aaaaaaaaaaaaaaaaaaaaaaaa#design"', $html);
        $this->assertStringContainsString('<hr class="wp-header-end">', $html);
    }

    public function test_the_button_shows_on_the_chosen_pages_only(): void
    {
        $r = $this->runInWordPress(self::SITE."\n".<<<'PHP'
            $GLOBALS['is_admin'] = false;
            $shows = function (array $options, ?int $page) use ($plugin): bool {
                $GLOBALS['options'] = $options + ['show_on_front' => 'page', 'page_on_front' => 7];
                $GLOBALS['queried'] = $page === null ? null : $GLOBALS['posts'][$page];
                $GLOBALS['scripts'] = [];
                $plugin->enqueue_button();
                return $GLOBALS['scripts'] === ['listen-voice-agent'];
            };
            $chosen = ['listen_voice_agent_placement' => 'pages', 'listen_voice_agent_pages' => [11, 13]];
            $typed = ['listen_voice_agent_placement' => 'pages', 'listen_voice_agent_pages' => '11, 13'];
            echo json_encode([
                'chosen' => [$shows($chosen, 13), $shows($chosen, 11), $shows($chosen, 8), $shows($chosen, null)],
                'typed' => [$shows($typed, 13), $shows($typed, 8)],
                'everywhere' => [$shows([], 8), $shows([], null)],
                'off' => [$shows(['listen_voice_agent_enabled' => '0'], 8), $shows(['listen_voice_agent_enabled' => '0'] + $chosen, 13)],
                'none_chosen' => $shows(['listen_voice_agent_placement' => 'pages', 'listen_voice_agent_pages' => []], 13),
            ]);
            PHP);

        $this->assertSame([true, true, false, false], $r['chosen']);
        $this->assertSame([true, false], $r['typed']);
        $this->assertSame([true, true], $r['everywhere']);
        $this->assertSame([false, false], $r['off']);
        $this->assertFalse($r['none_chosen']);
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
