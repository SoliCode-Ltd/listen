<?php

namespace App\Http\Controllers;

use App\Models\SiteSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

class ExtensionController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();

        return view('extension', [
            'newApiKey' => $request->session()->pull('new_api_key'),
            'apiState' => $this->apiState($user),
            'extensionVersion' => $this->extensionVersion(),

            // Page content from admin Extension Settings
            'pageTitle' => SiteSetting::get('extension_page_title', 'Browsers Extension'),
            'pageSubtitle' => SiteSetting::get('extension_page_subtitle', 'Turn any text on the web into natural voice. Select, click, and listen across all your favorite websites.'),

            // Browser availability
            'chromeEnabled' => SiteSetting::get('extension_chrome_enabled', '1') === '1',
            'firefoxEnabled' => SiteSetting::get('extension_firefox_enabled', '0') === '1',
            'safariEnabled' => SiteSetting::get('extension_safari_enabled', '0') === '1',

            // Browser cards
            'chromeCardTitle' => SiteSetting::get('extension_chrome_card_title', 'Chrome'),
            'chromeCardSubtitle' => SiteSetting::get('extension_chrome_card_subtitle', 'Full support with context menu integration'),
            'firefoxCardTitle' => SiteSetting::get('extension_firefox_card_title', 'Mozilla Firefox'),
            'firefoxCardSubtitle' => SiteSetting::get('extension_firefox_card_subtitle', 'WebExtension API support'),
            'safariCardTitle' => SiteSetting::get('extension_safari_card_title', 'Apple Safari'),
            'safariCardSubtitle' => SiteSetting::get('extension_safari_card_subtitle', 'Native Safari Web Extension'),

            // Feature cards
            'feature1Title' => SiteSetting::get('extension_feature_1_title', 'One-Click Listening'),
            'feature1Desc' => SiteSetting::get('extension_feature_1_desc', 'Select text anywhere and listen instantly with context menu integration.'),
            'feature2Title' => SiteSetting::get('extension_feature_2_title', 'Secure by Design'),
            'feature2Desc' => SiteSetting::get('extension_feature_2_desc', 'Your API key is stored locally and tied to your account with instant rotation.'),
            'feature3Title' => SiteSetting::get('extension_feature_3_title', 'Built for Speed'),
            'feature3Desc' => SiteSetting::get('extension_feature_3_desc', 'Audio streams immediately with no waiting. Keep your workflow uninterrupted.'),

            // Installation steps
            'installStep1' => SiteSetting::get('extension_install_step_1', 'Download the extension package using the button above.'),
            'installStep2' => SiteSetting::get('extension_install_step_2', 'Open chrome://extensions/ in your browser.'),
            'installStep3' => SiteSetting::get('extension_install_step_3', 'Enable Developer mode in the top right corner.'),
            'installStep4' => SiteSetting::get('extension_install_step_4', 'Click Load unpacked and select the extracted folder.'),
            'installStep5' => SiteSetting::get('extension_install_step_5', 'Pin the extension to your toolbar for quick access.'),

            // Download & announcement
            'downloadEnabled' => SiteSetting::get('extension_download_enabled', '1') === '1',
            'announcement' => SiteSetting::get('extension_announcement', ''),
            'announcementType' => SiteSetting::get('extension_announcement_type', 'info'),
        ]);
    }


    public function regenerateApiKey(Request $request)
    {
        $user = $request->user();
        $newApiKey = $user->issueApiKey();
        $user->refresh();

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'message' => 'New API key generated.',
                'new_api_key' => $newApiKey,
                'api_state' => $this->apiState($user),
            ]);
        }

        return redirect()
            ->route('browsers-extension.page')
            ->with('ok', 'A new API key was generated successfully.')
            ->with('new_api_key', $newApiKey);
    }

    public function revokeApiKey(Request $request)
    {
        $user = $request->user();
        $user->revokeApiKey();
        $user->refresh();

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'message' => 'API key revoked.',
                'api_state' => $this->apiState($user),
            ]);
        }

        return redirect()
            ->route('browsers-extension.page')
            ->with('ok', 'API key revoked. Generate a new key to reconnect the extension.');
    }

    public function download(Request $request): BinaryFileResponse
    {
        $sourceDir = public_path('extensions/chrome-listen-reader');
        if (! is_dir($sourceDir)) {
            abort(404, 'Extension package source is missing.');
        }

        $targetDir = storage_path('app/extensions');
        if (! is_dir($targetDir)) {
            mkdir($targetDir, 0775, true);
        }

        $buildDir = $targetDir.'/build/chrome-listen-reader';
        if (is_dir($buildDir)) {
            File::deleteDirectory($buildDir);
        }
        if (! File::copyDirectory($sourceDir, $buildDir)) {
            throw new \RuntimeException('Unable to prepare extension package.');
        }

        $apiBase = rtrim($request->root(), '/');
        File::put(
            $buildDir.'/config.js',
            "self.LISTEN_EXTENSION_CONFIG = Object.assign({}, self.LISTEN_EXTENSION_CONFIG || {}, { apiBase: ".json_encode($apiBase).", });\n"
        );

        $zipPath = $targetDir.'/listen-chrome-extension.zip';
        $this->buildZip($buildDir, $zipPath, 'listen-chrome-extension');

        return response()->download($zipPath, 'listen-chrome-extension.zip', [
            'Content-Type' => 'application/zip',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    private function buildZip(string $sourceDir, string $zipPath, string $rootFolder = ''): void
    {
        $sourceRoot = realpath($sourceDir);
        if (! is_string($sourceRoot) || $sourceRoot === '') {
            throw new \RuntimeException('Extension source path is invalid.');
        }

        $sourceRoot = rtrim(str_replace('\\', '/', $sourceRoot), '/');
        $rootFolder = trim(str_replace('\\', '/', $rootFolder), '/');

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Unable to build extension package.');
        }

        $files = File::allFiles($sourceDir);
        foreach ($files as $file) {
            $absolutePath = $file->getRealPath();
            if (! is_string($absolutePath)) {
                continue;
            }

            $absolutePath = str_replace('\\', '/', $absolutePath);
            if (! str_starts_with($absolutePath, $sourceRoot.'/')) {
                continue;
            }

            $relativePath = ltrim(substr($absolutePath, strlen($sourceRoot)), '/');
            if ($relativePath === '') {
                continue;
            }

            $entryName = $rootFolder !== '' ? $rootFolder.'/'.$relativePath : $relativePath;
            $zip->addFile($absolutePath, $entryName);
        }

        $zip->close();
    }

    private function apiState(?object $user): array
    {
        $createdAt = $user?->api_key_created_at;
        if ($createdAt instanceof Carbon) {
            $createdAt = $createdAt->toIso8601String();
        }

        return [
            'has_api_key' => (bool) $user?->api_key_hash,
            'api_key_last4' => $user?->api_key_last4,
            'api_key_created_at' => $createdAt,
            'api_key_created_human' => $user?->api_key_created_at?->diffForHumans(),
        ];
    }

    private function extensionVersion(): string
    {
        $sourceDir = public_path('extensions/chrome-listen-reader');
        if (! is_dir($sourceDir)) {
            return (string) now()->timestamp;
        }

        $latestMtime = 0;
        foreach (File::allFiles($sourceDir) as $file) {
            $mtime = (int) $file->getMTime();
            if ($mtime > $latestMtime) {
                $latestMtime = $mtime;
            }
        }

        return (string) max($latestMtime, (int) now()->timestamp);
    }
}
