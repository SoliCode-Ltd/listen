<?php

namespace App\Http\Controllers;

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
