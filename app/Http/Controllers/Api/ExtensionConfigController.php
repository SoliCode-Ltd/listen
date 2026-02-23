<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SiteSetting;
use Illuminate\Http\JsonResponse;

class ExtensionConfigController extends Controller
{
    /**
     * Public endpoint for the browser extension to fetch current server config.
     *
     * This allows the extension to dynamically discover the API base URL,
     * enabling seamless domain migration without rebuilding the extension.
     * No authentication required — only non-sensitive config is returned.
     */
    public function config(): JsonResponse
    {
        $customApiBase = trim((string) SiteSetting::get('extension_custom_api_base', ''));
        $apiBase = $customApiBase !== '' ? $customApiBase : rtrim((string) config('app.url'), '/');

        return response()->json([
            'ok' => true,
            'config' => [
                'apiBase' => $apiBase,
                'brandName' => SiteSetting::get('extension_chrome_name', config('app.name', 'Listen')),
                'version' => SiteSetting::get('extension_chrome_version', '1.0.0'),
                'defaultLanguage' => SiteSetting::get('extension_default_language', 'ar'),
                'defaultTranslate' => SiteSetting::get('extension_default_translate', '1') === '1',
            ],
        ], 200, [
            'Access-Control-Allow-Origin' => '*',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }
}
