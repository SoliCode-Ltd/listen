<?php

namespace App\Services\Agents;

use App\Models\AgentWidget;
use Mcamara\LaravelLocalization\Facades\LaravelLocalization;
use RuntimeException;
use ZipArchive;

/**
 * The WordPress plugin for one agent: a zip the owner uploads in WordPress
 * (Plugins → Add New Plugin → Upload Plugin). It puts the agent's call button
 * on the website with the agent's key already in it; its settings page (under
 * Settings, in Arabic when WordPress is) turns the button off, shows it on some
 * pages only, or takes another agent's key.
 *
 * The plugin lives in resources/agents/wordpress: templates whose {{…}} this
 * class fills in, and the Arabic it ships as WordPress translation files.
 */
class WordPressPlugin
{
    public const SLUG = 'listen-voice-agent';

    public const VERSION = '1.1.0';

    /** The plugin's files, by their path in its folder: the template each comes from. */
    private const FILES = [
        'listen-voice-agent.php' => 'listen-voice-agent.php.stub',
        'src/Plugin.php' => 'Plugin.php.stub',
        'src/Settings.php' => 'Settings.php.stub',
        'src/Placement.php' => 'Placement.php.stub',
        'uninstall.php' => 'uninstall.php.stub',
        'readme.txt' => 'readme.txt.stub',
    ];

    /** Arabic's six plural forms, as WordPress writes them for its Arabic locales. */
    private const ARABIC_PLURALS = 'nplurals=6; plural=(n == 0) ? 0 : ((n == 1) ? 1 : ((n == 2) ? 2 : ((n % 100 >= 3 && n % 100 <= 10) ? 3 : ((n % 100 >= 11 && n % 100 <= 99) ? 4 : 5))));';

    /** The plugin's languages besides English, by WordPress locale: its words, and its plural forms. */
    private const LANGUAGES = [
        'ar' => ['translations-ar.json', self::ARABIC_PLURALS],
        // Moroccan Arabic: WordPress's other Arabic. The same words read well there.
        'ary' => ['translations-ar.json', self::ARABIC_PLURALS],
    ];

    /** Builds the zip and returns its path: a temporary file for the caller to send and delete. */
    public function build(AgentWidget $widget): string
    {
        $files = $this->files($widget);
        $path = tempnam(sys_get_temp_dir(), 'lva');
        $zip = new ZipArchive;
        if ($path === false || $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The WordPress plugin could not be built.');
        }
        foreach ($files as $name => $content) {
            $zip->addFromString(self::SLUG.'/'.$name, $content);
        }
        $zip->close();

        return $path;
    }

    /** @return array<string, string> the plugin's files, by their path in its folder */
    public function files(AgentWidget $widget): array
    {
        $placeholders = $this->placeholders($widget);
        $files = [];
        foreach (self::FILES as $name => $template) {
            $files[$name] = strtr($this->template($template), $placeholders);
        }
        foreach (self::LANGUAGES as $locale => [$words, $plurals]) {
            $files['languages/'.self::SLUG.'-'.$locale.'.l10n.php'] = $this->translations($locale, $words, $plurals);
        }

        return $files;
    }

    /** @return array<string, string> what each {{…}} in the templates becomes, for this agent */
    private function placeholders(AgentWidget $widget): array
    {
        $key = (string) $widget->public_key;
        if (! preg_match('/\Alw_[A-Za-z0-9]{28}\z/', $key)) {
            throw new RuntimeException('The WordPress plugin needs the agent\'s key.');
        }
        // Listen's pages without a language in the address, so each opens in its reader's language.
        $page = fn (string $route): string => $this->address(LaravelLocalization::getNonLocalizedURL(route($route)));

        return [
            '{{VERSION}}' => self::VERSION,
            '{{HOME}}' => $this->address(rtrim(url('/'), '/')),
            '{{PRODUCT_URL}}' => $page('agents-landing'),
            '{{TERMS_URL}}' => $page('legal.terms'),
            '{{PRIVACY_URL}}' => $page('legal.privacy'),
            // PHP strings in the plugin's code.
            '{{KEY_PHP}}' => var_export($key, true),
            '{{SCRIPT_PHP}}' => var_export($this->address(asset('js/listen-agent.js')), true),
            '{{MANAGE_PHP}}' => var_export($page('voice-agents.index'), true),
        ];
    }

    /**
     * A web address the plugin can carry anywhere: in its header, a comment or a string.
     * Only http(s) and the characters of a plain path, so nothing in it can end any of them.
     */
    private function address(string $url): string
    {
        if (! preg_match('#\Ahttps?://[A-Za-z0-9.-]+(?::[0-9]{1,5})?(?:/[A-Za-z0-9._~%/-]*)?\z#', $url)) {
            throw new RuntimeException('The WordPress plugin cannot carry this address: '.$url);
        }

        return $url;
    }

    /**
     * One language's WordPress translation file: the PHP format WordPress reads since 6.5
     * (….l10n.php), written with var_export so no word can become code.
     */
    private function translations(string $locale, string $words, string $plurals): string
    {
        $messages = json_decode($this->template($words), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($messages) || array_filter($messages, fn ($text, $english) => ! is_string($text) || ! is_string($english), ARRAY_FILTER_USE_BOTH) !== []) {
            throw new RuntimeException('The WordPress plugin\'s words in '.$words.' are not all text.');
        }
        $file = [
            'project-id-version' => 'Listen Voice Agent '.self::VERSION,
            'language' => $locale,
            'plural-forms' => $plurals,
            'messages' => $messages,
        ];

        return "<?php\n// Listen Voice Agent's words in this language. WordPress reads this file itself.\nreturn ".var_export($file, true).";\n";
    }

    private function template(string $file): string
    {
        $contents = @file_get_contents(resource_path('agents/wordpress/'.$file));
        if ($contents === false) {
            throw new RuntimeException('The WordPress plugin is missing '.$file.'.');
        }

        return $contents;
    }
}
