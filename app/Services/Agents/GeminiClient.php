<?php

namespace App\Services\Agents;

use App\Services\Tts\GeminiKeyManager;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Listen Agents' calls to Gemini, on the site's keys in their usual order
 * (GeminiKeyManager): a key out of today's quota, refused or capped is marked
 * and the next one is tried, as the studio does.
 *
 * - generate(): the text model, for writing agents and summing up calls.
 * - liveToken(): a short-lived key for one live call, locked to the agent's
 *   setup (model, voice, instructions, tools), so the browser that holds it
 *   can only have that conversation, for that long.
 */
class GeminiClient
{
    public const LIVE_MODEL = 'gemini-3.8-live';

    public const WRITER_MODELS = ['gemini-3.8-flash', 'gemini-3.6-flash'];

    private function base(): string
    {
        return rtrim((string) config('services.gemini_tts.endpoint', 'https://generativelanguage.googleapis.com/v1beta'), '/');
    }

    /**
     * Text in, text out (JSON when $schema is given). Returns the reply text or null.
     *
     * @param  array<string, mixed>|null  $schema
     */
    public function generate(string $prompt, ?array $schema = null, int $timeout = 40, float $temperature = 0.4): ?string
    {
        $config = ['temperature' => $temperature];
        if ($schema !== null) {
            $config['responseMimeType'] = 'application/json';
            $config['responseSchema'] = $schema;
        }
        $deadline = microtime(true) + $timeout;
        foreach (self::WRITER_MODELS as $model) {
            $left = (int) floor($deadline - microtime(true));
            if ($left < 4) {
                break;
            }
            $response = $this->post("/models/{$model}:generateContent", [
                'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
                'generationConfig' => $config,
            ], $model, $left);
            if ($response?->successful()) {
                $text = data_get($response->json(), 'candidates.0.content.parts.0.text');
                if (is_string($text) && trim($text) !== '') {
                    return $text;
                }
            }
        }

        return null;
    }

    /**
     * A key for one live call. Returns ['token' => ..., 'expires_at' => ISO time] or null.
     *
     * @param  array<string, mixed>  $setup  the session setup, without the model
     */
    public function liveToken(array $setup, int $seconds): ?array
    {
        $now = time();
        $expires = $now + max(60, $seconds);
        $response = $this->post('/auth_tokens', [
            'uses' => 1,
            'expireTime' => gmdate('Y-m-d\TH:i:s\Z', $expires),
            'newSessionExpireTime' => gmdate('Y-m-d\TH:i:s\Z', $now + 90),
            'liveConnectConstraints' => [
                'model' => 'models/'.self::LIVE_MODEL,
                'config' => $setup,
            ],
        ], self::LIVE_MODEL, 15);
        $name = $response?->successful() ? data_get($response->json(), 'name') : null;
        if (! is_string($name) || $name === '') {
            Log::warning('Agent live key was not made.', ['status' => $response?->status(), 'error' => substr((string) $response?->body(), 0, 300)]);

            return null;
        }

        return ['token' => $name, 'expires_at' => gmdate('c', $expires)];
    }

    /** @param  array<string, mixed>  $payload */
    protected function post(string $path, array $payload, string $model, int $timeout): ?Response
    {
        $keys = array_values(array_filter(app(GeminiKeyManager::class)->keysInOrder($model), fn ($k) => filled($k['key'] ?? null)));
        $configured = trim((string) config('services.gemini_tts.api_key', ''));
        if ($configured !== '' && ! in_array($configured, array_column($keys, 'key'), true)) {
            array_unshift($keys, ['key' => $configured, 'name' => 'GEMINI_TTS_API_KEY']);
        }
        $last = null;
        foreach ($keys as $index => $key) {
            try {
                $response = Http::timeout($timeout)->connectTimeout(10)
                    ->withHeaders(['Content-Type' => 'application/json', 'x-goog-api-key' => $key['key']])
                    ->post($this->base().$path, $payload);
            } catch (\Throwable $e) {
                Log::warning('Agent Gemini call failed.', ['path' => $path, 'error' => $e->getMessage()]);

                return null;
            }
            $last = $response;
            $body = (string) $response->body();
            $badKey = GeminiKeyManager::isKeyProblem($response->status(), $body);
            if (filled($key['name'] ?? null) && ($badKey || GeminiKeyManager::isDailyQuotaError($response->status(), $body))) {
                app(GeminiKeyManager::class)->markOutForToday((string) $key['name'], $model);
            }
            if (filled($key['name'] ?? null) && GeminiKeyManager::isSpendingCapError($response->status(), $body)) {
                app(GeminiKeyManager::class)->markOutForSpendingCap((string) $key['name']);
            }
            $retry = $badKey || in_array($response->status(), [429, 403], true);
            if (! $retry || $index === array_key_last($keys)) {
                return $response;
            }
        }

        return $last;
    }
}
