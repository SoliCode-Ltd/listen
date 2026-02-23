<?php

namespace App\Console\Commands;

use App\Models\SiteSetting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ListenSyncExtensionCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'listen:sync-extension';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync the extension config.js apiBase with the current APP_URL';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $appUrl = config('app.url');
        if (! $appUrl) {
            $this->error('APP_URL is not set in the environment.');
            return self::FAILURE;
        }

        $customApiBase = trim((string) SiteSetting::get('extension_custom_api_base', ''));
        $apiBase = $customApiBase !== '' ? $customApiBase : rtrim($appUrl, '/');
        $configUrl = trim((string) SiteSetting::get('extension_remote_config_url', ''));

        $configFile = public_path('extensions/chrome-listen-reader/config.js');

        if (! File::exists($configFile)) {
            $this->error("Extension config file not found at: {$configFile}");
            return self::FAILURE;
        }

        $content = "/**\n * Listen Reader — Chrome Extension\n * Configuration\n *\n * (c) 2024-2026 SoliCode Ltd. All rights reserved.\n * https://solicode.co.uk\n */\n";
        $content .= 'self.LISTEN_EXTENSION_CONFIG = Object.assign({}, self.LISTEN_EXTENSION_CONFIG || {}, ';
        $content .= json_encode([
            'apiBase' => $apiBase,
            'configUrl' => $configUrl ?: $apiBase,
        ], JSON_UNESCAPED_SLASHES);
        $content .= ");\n";

        File::put($configFile, $content);

        $this->info("Extension config successfully synced to apiBase: {$apiBase}");

        return self::SUCCESS;
    }
}
