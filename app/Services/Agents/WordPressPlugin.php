<?php

namespace App\Services\Agents;

use App\Models\Agent;
use App\Models\AgentWidget;
use Mcamara\LaravelLocalization\Facades\LaravelLocalization;
use RuntimeException;
use ZipArchive;

/**
 * The WordPress plugin for one agent: a zip the owner uploads in WordPress
 * (Plugins → Add New Plugin → Upload Plugin). It puts the agent's call button
 * on the website with the agent's key already in it; its settings page (under
 * Settings, in Arabic when WordPress is) turns the button off, limits it to the
 * pages ticked in a list of the website's pages, or takes another agent's key.
 *
 * The plugin lives in resources/agents/wordpress: templates whose {{…}} this
 * class fills in, the settings page's style and script, and the Arabic it ships
 * as WordPress translation files.
 */
class WordPressPlugin
{
    public const SLUG = 'listen-voice-agent';

    public const VERSION = '1.2.0';

    /** The plugin's files, by their path in its folder: the template each comes from. */
    private const FILES = [
        'listen-voice-agent.php' => 'listen-voice-agent.php.stub',
        'src/Plugin.php' => 'Plugin.php.stub',
        'src/Settings.php' => 'Settings.php.stub',
        'src/SettingsPage.php' => 'SettingsPage.php.stub',
        'src/Pages.php' => 'Pages.php.stub',
        'src/Placement.php' => 'Placement.php.stub',
        'assets/settings.css' => 'settings.css',
        'assets/settings.js' => 'settings.js',
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
        $page = fn (string $route, array $parameters = []): string => $this->address(LaravelLocalization::getNonLocalizedURL(route($route, $parameters)));
        $agent = $widget->agent;

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
            // The agent's own page (its Design tab changes the button's look), or the list of agents without one.
            '{{AGENT_PHP}}' => var_export($agent instanceof Agent && $agent->public_id ? $page('voice-agents.show', ['agent' => $agent]) : $page('voice-agents.index'), true),
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
     *
     * In the words file each English text maps to its translation, or, for a text with a
     * number, to {"plural": the English plural, "forms": one translation per plural form}.
     * WordPress finds those by "singular\0plural" and picks the form for the number.
     */
    private function translations(string $locale, string $words, string $plurals): string
    {
        $entries = json_decode($this->template($words), true, 512, JSON_THROW_ON_ERROR);
        $forms = preg_match('/\Anplurals=([1-9]);/', $plurals, $m) ? (int) $m[1] : 0;
        $messages = [];
        foreach (is_array($entries) ? $entries : [0 => null] as $english => $text) {
            $plural = is_array($text) ? ($text['plural'] ?? null) : null;
            $translations = is_array($text) ? ($text['forms'] ?? null) : null;
            if (is_string($english) && is_string($text)) {
                $messages[$english] = $text;
            } elseif (is_string($english) && is_string($plural) && is_array($translations) && array_is_list($translations)
                && count($translations) === $forms && array_filter($translations, 'is_string') === $translations && count($text) === 2) {
                $messages[$english."\0".$plural] = implode("\0", $translations);
            } else {
                throw new RuntimeException('The WordPress plugin\'s words in '.$words.' are not all text, or a text with a number does not have all '.$forms.' forms.');
            }
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
