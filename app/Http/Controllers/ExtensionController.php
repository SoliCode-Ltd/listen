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
        $announcement = trim((string) SiteSetting::get('extension_announcement', ''));
        if ($this->isArabicLocale() && $announcement !== '' && ! $this->containsArabic($announcement)) {
            $announcement = '';
        }

        return view('extension', [
            'newApiKey' => $request->session()->pull('new_api_key'),
            'apiState' => $this->apiState($user),
            'extensionVersion' => $this->extensionVersion(),

            // Page content from admin Extension Settings
            'pageTitle' => $this->localizedSetting('extension_page_title', 'ui.extension.page_title'),
            'pageSubtitle' => $this->localizedSetting('extension_page_subtitle', 'ui.extension.page_subtitle'),

            // Browser availability
            'chromeEnabled' => SiteSetting::get('extension_chrome_enabled', '1') === '1',
            'firefoxEnabled' => SiteSetting::get('extension_firefox_enabled', '0') === '1',
            'safariEnabled' => SiteSetting::get('extension_safari_enabled', '0') === '1',

            // Browser cards
            'chromeCardTitle' => $this->localizedSetting('extension_chrome_card_title', 'ui.extension.chrome_title'),
            'chromeCardSubtitle' => $this->localizedSetting('extension_chrome_card_subtitle', 'ui.extension.chrome_subtitle'),
            'firefoxCardTitle' => $this->localizedSetting('extension_firefox_card_title', 'ui.extension.firefox_title'),
            'firefoxCardSubtitle' => $this->localizedSetting('extension_firefox_card_subtitle', 'ui.extension.firefox_subtitle'),
            'safariCardTitle' => $this->localizedSetting('extension_safari_card_title', 'ui.extension.safari_title'),
            'safariCardSubtitle' => $this->localizedSetting('extension_safari_card_subtitle', 'ui.extension.safari_subtitle'),

            // Feature cards
            'feature1Title' => $this->localizedSetting('extension_feature_1_title', 'ui.extension.feature1_title'),
            'feature1Desc' => $this->localizedSetting('extension_feature_1_desc', 'ui.extension.feature1_desc'),
            'feature2Title' => $this->localizedSetting('extension_feature_2_title', 'ui.extension.feature2_title'),
            'feature2Desc' => $this->localizedSetting('extension_feature_2_desc', 'ui.extension.feature2_desc'),
            'feature3Title' => $this->localizedSetting('extension_feature_3_title', 'ui.extension.feature3_title'),
            'feature3Desc' => $this->localizedSetting('extension_feature_3_desc', 'ui.extension.feature3_desc'),

            // Installation steps
            'installStep1' => $this->localizedSetting('extension_install_step_1', 'ui.extension.install_step_1'),
            'installStep2' => $this->localizedSetting('extension_install_step_2', 'ui.extension.install_step_2'),
            'installStep3' => $this->localizedSetting('extension_install_step_3', 'ui.extension.install_step_3'),
            'installStep4' => $this->localizedSetting('extension_install_step_4', 'ui.extension.install_step_4'),
            'installStep5' => $this->localizedSetting('extension_install_step_5', 'ui.extension.install_step_5'),

            // How to Use steps
            'howToUseStep1' => $this->localizedSetting('extension_how_to_use_step_1', 'ui.extension.use_step_1'),
            'howToUseStep2' => $this->localizedSetting('extension_how_to_use_step_2', 'ui.extension.use_step_2'),
            'howToUseStep3' => $this->localizedSetting('extension_how_to_use_step_3', 'ui.extension.use_step_3'),
            'howToUseStep4' => $this->localizedSetting('extension_how_to_use_step_4', 'ui.extension.use_step_4'),
            'howToUseStep5' => $this->localizedSetting('extension_how_to_use_step_5', 'ui.extension.use_step_5'),


            // Download & announcement
            'downloadEnabled' => SiteSetting::get('extension_download_enabled', '1') === '1',
            'chromeZipButtonText' => $this->localizedSetting('extension_chrome_zip_button_text', 'ui.extension.zip_button'),
            'chromeStoreEnabled' => SiteSetting::get('extension_chrome_store_enabled', '1') === '1',
            'chromeStoreButtonText' => $this->localizedSetting('extension_chrome_store_button_text', 'ui.extension.store_button'),
            'chromeStoreUrl' => SiteSetting::get('extension_chrome_store_url', 'https://chromewebstore.google.com/detail/listen-%E2%80%93-turn-text-into-h/hgffdggimfklddpnjdfjonijlempljpa?authuser=0&hl=en'),
            'announcement' => $announcement,
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
                'message' => __('ui.backend.extension_new_key_generated'),
                'new_api_key' => $newApiKey,
                'api_state' => $this->apiState($user),
            ]);
        }

        return redirect()
            ->route('browsers-extension.page')
            ->with('ok', __('ui.backend.extension_key_generated_redirect'))
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
                'message' => __('ui.backend.extension_key_revoked'),
                'api_state' => $this->apiState($user),
            ]);
        }

        return redirect()
            ->route('browsers-extension.page')
            ->with('ok', __('ui.backend.extension_key_revoked_redirect'));
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

        $customApiBase = trim((string) SiteSetting::get('extension_custom_api_base', ''));
        $apiBase = $customApiBase !== '' ? $customApiBase : rtrim($request->root(), '/');
        $configUrl = trim((string) SiteSetting::get('extension_remote_config_url', ''));

        $configJs = "/**\n * Listen Reader — Chrome Extension\n * Configuration\n *\n * (c) 2024-2026 SoliCode Ltd. All rights reserved.\n * https://solicode.co.uk\n */\n";
        $configJs .= 'self.LISTEN_EXTENSION_CONFIG = Object.assign({}, self.LISTEN_EXTENSION_CONFIG || {}, ';
        $configJs .= json_encode([
            'apiBase' => $apiBase,
            'configUrl' => $configUrl ?: $apiBase,
        ], JSON_UNESCAPED_SLASHES);
        $configJs .= ");\n";

        File::put($buildDir.'/config.js', $configJs);

        // Update manifest with admin-configured name/description/version
        $manifestPath = $buildDir.'/manifest.json';
        if (File::exists($manifestPath)) {
            $manifest = json_decode(File::get($manifestPath), true);
            if (is_array($manifest)) {
                // Keep the translated name and description (English and Arabic) unless the
                // admin wrote their own; never ship an older version than the source.
                $name = trim((string) SiteSetting::get('extension_chrome_name', ''));
                if ($name !== '' && ! in_array($name, ['Listen Reader', 'Listen Studio Chrome'], true)) {
                    $manifest['name'] = $name;
                }
                $description = trim((string) SiteSetting::get('extension_chrome_description', ''));
                if ($description !== '' && $description !== 'Listen to selected text on any page using your Listen account.') {
                    $manifest['description'] = $description;
                }
                $manifest['version'] = \App\Support\ExtensionVersion::current();
                File::put($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }
        }

        // Replace icons with admin-uploaded versions if available
        $iconSizes = [16, 32, 48, 128];
        foreach ($iconSizes as $size) {
            $uploadedPath = trim((string) SiteSetting::get("extension_icon_{$size}", ''));
            if ($uploadedPath !== '' && \Illuminate\Support\Facades\Storage::disk('public')->exists($uploadedPath)) {
                $source = \Illuminate\Support\Facades\Storage::disk('public')->path($uploadedPath);
                $target = $buildDir."/icon-{$size}.png";
                if (is_file($source)) {
                    copy($source, $target);
                }
            }
        }

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

    private function localizedSetting(string $settingKey, string $translationKey): string
    {
        $configured = trim((string) SiteSetting::get($settingKey, ''));
        $translated = trim((string) __($translationKey));

        if (! $this->isArabicLocale()) {
            if ($configured !== '') {
                return $configured;
            }

            return $translated;
        }

        if ($configured !== '' && $this->containsArabic($configured)) {
            return $configured;
        }

        if ($translated !== '' && $translated !== $translationKey) {
            return $translated;
        }

        return $configured;
    }

    private function isArabicLocale(): bool
    {
        return str_starts_with(strtolower((string) app()->getLocale()), 'ar');
    }

    private function containsArabic(string $value): bool
    {
        return (bool) preg_match('/[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{08A0}-\x{08FF}]/u', $value);
    }
}
