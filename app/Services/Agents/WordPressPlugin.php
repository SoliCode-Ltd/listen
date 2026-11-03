<?php

namespace App\Services\Agents;

use App\Models\AgentWidget;
use RuntimeException;
use ZipArchive;

/**
 * The WordPress plugin for one agent: a zip the owner uploads in WordPress
 * (Plugins → Add New Plugin → Upload Plugin). It puts the agent's call button
 * on every page with the agent's key already in it; its settings page can turn
 * the button off or take another agent's key. The plugin's code lives in
 * resources/agents/wordpress.
 */
class WordPressPlugin
{
    public const SLUG = 'listen-voice-agent';

    public const VERSION = '1.0.0';

    /** Builds the zip and returns its path: a temporary file for the caller to send and delete. */
    public function build(AgentWidget $widget): string
    {
        $path = tempnam(sys_get_temp_dir(), 'lva');
        $zip = new ZipArchive;
        if ($path === false || $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The WordPress plugin could not be built.');
        }
        foreach ($this->files($widget) as $name => $content) {
            $zip->addFromString(self::SLUG.'/'.$name, $content);
        }
        $zip->close();

        return $path;
    }

    /** @return array<string, string> the plugin's files, by name */
    public function files(AgentWidget $widget): array
    {
        $home = rtrim(url('/'), '/');
        $vars = [
            '{{VERSION}}' => self::VERSION,
            '{{HOME}}' => $home,
            '{{HOME_PHP}}' => var_export($home.'/agents', true),
            '{{KEY}}' => var_export((string) $widget->public_key, true),
            '{{SCRIPT}}' => var_export(asset('js/listen-agent.js'), true),
        ];
        $stub = fn (string $file) => strtr((string) file_get_contents(resource_path('agents/wordpress/'.$file)), $vars);

        return [
            self::SLUG.'.php' => $stub('listen-voice-agent.php.stub'),
            'readme.txt' => $stub('readme.txt.stub'),
            'uninstall.php' => "<?php\n// Listen Voice Agent: removing the plugin removes its two settings.\nif (!defined('WP_UNINSTALL_PLUGIN')) {\n    exit;\n}\ndelete_option('listen_voice_agent_enabled');\ndelete_option('listen_voice_agent_key');\n",
        ];
    }
}
