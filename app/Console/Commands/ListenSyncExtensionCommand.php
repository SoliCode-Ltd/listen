<?php

namespace App\Console\Commands;

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

        $apiBase = rtrim($appUrl, '/');
        $configFile = public_path('extensions/chrome-listen-reader/config.js');

        if (! File::exists($configFile)) {
            $this->error("Extension config file not found at: {$configFile}");
            return self::FAILURE;
        }

        $content = <<<JS
/**
 * Listen Reader — Chrome Extension
 * Configuration
 *
 * (c) 2024-2026 SoliCode Ltd. All rights reserved.
 * https://solicode.co.uk
 */
self.LISTEN_EXTENSION_CONFIG = Object.assign({}, self.LISTEN_EXTENSION_CONFIG || {}, {
  apiBase: '{$apiBase}',
});
JS;

        File::put($configFile, $content);

        $this->info("Extension config successfully synced to apiBase: {$apiBase}");

        return self::SUCCESS;
    }
}
