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
 * - judge(): the same model ruling on a text (App\Services\ContentSafety\TextContentCheck).
 * - converse(): the same model in a written chat with a customer (AgentChats): instructions,
 *   the conversation so far and tools in, its words or tool calls and its token counts out.
 * - readFile(): the same model reading a file the owner uploaded (a PDF, a photo of a menu).
 * - transcribe(): the same model writing down what a caller said, from their voice.
 * - liveToken(): a short-lived key for one live call, locked to the agent's
 *   setup (model, voice, instructions, tools), so the browser that holds it
 *   can only have that conversation, for that long.
 */
class GeminiClient
{
    public const LIVE_MODEL = 'gemini-3.8-live';

    public const WRITER_MODELS = ['gemini-3.8-flash', 'gemini-3.6-flash'];

    /**
     * The tokens generate() last used (what it read, what it wrote with its thinking, the part
     * read from the cache) and the model that answered; null when it gave nothing.
     *
     * @var array{in: int, out: int, cached: int, model: string}|null
     */
    public ?array $lastUsage = null;

    private function base(): string
    {
        return rtrim((string) config('services.gemini_tts.endpoint', 'https://generativelanguage.googleapis.com/v1beta'), '/');
    }

    /**
     * Text in, text out (JSON when $schema is given). Returns the reply text or null.
     * $thinking ('low') makes a short task quicker: the model thinks less before it answers.
     *
     * @param  array<string, mixed>|null  $schema
     */
    public function generate(string $prompt, ?array $schema = null, int $timeout = 40, float $temperature = 0.4, ?string $thinking = null): ?string
    {
        $this->lastUsage = null;
        $config = ['temperature' => $temperature];
        if ($schema !== null) {
            $config['responseMimeType'] = 'application/json';
            $config['responseSchema'] = $schema;
        }
        if ($thinking !== null) {
            $config['thinkingConfig'] = ['thinkingLevel' => $thinking];
        }
        $deadline = microtime(true) + $timeout;
        foreach (self::WRITER_MODELS as $model) {
            $left = (int) floor($deadline - microtime(true));
            if ($left < 4) {
                break;
            }
            $ask = fn (array $config, int $seconds) => $this->post("/models/{$model}:generateContent", [
                'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
                'generationConfig' => $config,
            ], $model, $seconds);
            $response = $ask($config, $left);
            // A model that does not take that thinking level is asked again with its own.
            if ($response?->status() === 400 && isset($config['thinkingConfig']) && str_contains(strtolower((string) $response->body()), 'thinking')) {
                unset($config['thinkingConfig']);
                $left = (int) floor($deadline - microtime(true));
                $response = $left >= 4 ? $ask($config, $left) : null;
            }
            if ($response?->successful()) {
                $text = data_get($response->json(), 'candidates.0.content.parts.0.text');
                if (is_string($text) && trim($text) !== '') {
                    $usage = (array) data_get($response->json(), 'usageMetadata', []);
                    $this->lastUsage = [
                        'in' => (int) ($usage['promptTokenCount'] ?? 0),
                        'out' => (int) ($usage['candidatesTokenCount'] ?? 0) + (int) ($usage['thoughtsTokenCount'] ?? 0),
                        'cached' => (int) ($usage['cachedContentTokenCount'] ?? 0),
                        'model' => $model,
                    ];

                    return $text;
                }
            }
        }

        return null;
    }

    /**
     * The models that rule on a text, the quick one first. Measured on live (November 2026) on
     * the same texts: gemini-3.6-flash, thinking as little as it can, rules in 1.3 to 1.9
     * seconds; gemini-3.8-flash does not take that thinking level and needs 4 to 9 seconds.
     */
    public const JUDGE_MODELS = ['gemini-3.6-flash', 'gemini-3.8-flash'];

    /**
     * A ruling on a text, answered as JSON: a text model at temperature 0, thinking as little
     * as it can, with the provider's own filters off so it reads the text and rules on it rather
     * than turning the request away. Returns the reply text, and whether the provider would not
     * read the text at all (its own refusal, which is a ruling too); null when no model answered.
     *
     * The first model is waited for half the time at most, so the second always has its turn:
     * a slow first answer must never leave a text without a ruling.
     *
     * @param  array<string, mixed>  $schema
     * @return array{text: ?string, blocked: bool, model: string}|null
     */
    public function judge(string $system, string $content, array $schema, int $timeout = 10): ?array
    {
        $base = [
            'temperature' => 0,
            'responseMimeType' => 'application/json',
            'responseSchema' => $schema,
        ];
        $safety = array_map(
            static fn (string $category): array => ['category' => $category, 'threshold' => 'BLOCK_NONE'],
            ['HARM_CATEGORY_SEXUALLY_EXPLICIT', 'HARM_CATEGORY_HARASSMENT', 'HARM_CATEGORY_HATE_SPEECH', 'HARM_CATEGORY_DANGEROUS_CONTENT'],
        );
        $deadline = microtime(true) + $timeout;
        $last = array_key_last(self::JUDGE_MODELS);
        foreach (self::JUDGE_MODELS as $index => $model) {
            if ($deadline - microtime(true) < 2) {
                break;
            }
            $until = $index === $last ? $deadline : min($deadline, microtime(true) + max(2, (int) ceil($timeout / 2)));
            $response = null;
            // The least thinking the model takes: one that refuses a level is asked with the next.
            foreach (['minimal', 'low', null] as $thinking) {
                $left = (int) floor($until - microtime(true));
                if ($left < 2) {
                    break;
                }
                $response = $this->post("/models/{$model}:generateContent", [
                    'systemInstruction' => ['parts' => [['text' => $system]]],
                    'contents' => [['role' => 'user', 'parts' => [['text' => $content]]]],
                    'generationConfig' => $base + ($thinking !== null ? ['thinkingConfig' => ['thinkingLevel' => $thinking]] : []),
                    'safetySettings' => $safety,
                ], $model, $left);
                if (! ($response?->status() === 400 && str_contains(strtolower((string) $response->body()), 'thinking'))) {
                    break;
                }
            }
            if (! $response?->successful()) {
                continue;
            }
            $json = (array) $response->json();
            $finish = (string) data_get($json, 'candidates.0.finishReason', '');
            if (filled(data_get($json, 'promptFeedback.blockReason')) || in_array($finish, ['SAFETY', 'PROHIBITED_CONTENT', 'BLOCKLIST'], true)) {
                return ['text' => null, 'blocked' => true, 'model' => $model];
            }
            $text = '';
            foreach ((array) data_get($json, 'candidates.0.content.parts', []) as $part) {
                if (is_array($part) && empty($part['thought']) && isset($part['text']) && is_string($part['text'])) {
                    $text .= $part['text'];
                }
            }
            if (trim($text) !== '') {
                return ['text' => $text, 'blocked' => false, 'model' => $model];
            }
        }

        return null;
    }

    /**
     * One turn of a written conversation: the agent's instructions, what was written so far, and
     * the tools it may call. Returns what the model answered (its words, or the tools it calls),
     * its own parts to keep in the conversation, and the tokens the turn used; null when no model
     * answered in time. The second writer model is tried when the first fails.
     *
     * @param  array<int, array{role: string, parts: array<int, array<string, mixed>>}>  $contents
     * @param  array<int, array<string, mixed>>  $tools  function declarations
     * @return array{text: string, calls: array<int, array{name: string, args: array<string, mixed>}>, parts: array<int, array<string, mixed>>, usage: array{in: int, out: int, thoughts: int, cached: int}, model: string}|null
     */
    public function converse(string $system, array $contents, array $tools = [], int $timeout = 25, int $maxOutputTokens = 600, bool $wordsOnly = false): ?array
    {
        // A chat reply is a short answer from facts it was given: the model thinks little before
        // writing, so the customer is not kept waiting (listen.agents.chat_thinking). Measured on
        // staging: "low" answered in 3 to 6 seconds; "minimal" was slower (4 to 9).
        $thinking = (string) config('listen.agents.chat_thinking', 'low');
        $config = ['temperature' => 0.4, 'maxOutputTokens' => $maxOutputTokens] + ($thinking !== '' ? ['thinkingConfig' => ['thinkingLevel' => $thinking]] : []);
        $deadline = microtime(true) + $timeout;
        foreach (self::WRITER_MODELS as $model) {
            $left = (int) floor($deadline - microtime(true));
            if ($left < 4) {
                break;
            }
            $ask = fn (array $config, int $seconds) => $this->post("/models/{$model}:generateContent", array_filter([
                'systemInstruction' => ['parts' => [['text' => $system]]],
                'contents' => $contents,
                'tools' => $tools !== [] ? [['functionDeclarations' => $tools]] : null,
                // A round that must answer in words: the tools stay declared (the conversation
                // above it used them) but none may be called.
                'toolConfig' => $tools !== [] && $wordsOnly ? ['functionCallingConfig' => ['mode' => 'NONE']] : null,
                'generationConfig' => $config,
            ]), $model, $seconds);
            $response = $ask($config, $left);
            // A model that does not take that thinking level is asked again with the next one up, then its own.
            foreach (['low', null] as $next) {
                if (! isset($config['thinkingConfig']) || $response?->status() !== 400 || ! str_contains(strtolower((string) $response->body()), 'thinking')) {
                    break;
                }
                if ($next !== null && ($config['thinkingConfig']['thinkingLevel'] ?? '') !== $next) {
                    $config['thinkingConfig'] = ['thinkingLevel' => $next];
                } else {
                    unset($config['thinkingConfig']);
                }
                $left = (int) floor($deadline - microtime(true));
                $response = $left >= 4 ? $ask($config, $left) : null;
            }
            if (! $response?->successful()) {
                continue;
            }
            $parts = array_values(array_filter((array) data_get($response->json(), 'candidates.0.content.parts', []), 'is_array'));
            $text = '';
            $calls = [];
            foreach ($parts as $part) {
                // The model's own thinking is never part of what it says.
                if (! empty($part['thought'])) {
                    continue;
                }
                if (isset($part['text']) && is_string($part['text'])) {
                    $text .= $part['text'];
                }
                if (isset($part['functionCall']['name'])) {
                    $calls[] = ['name' => (string) $part['functionCall']['name'], 'args' => (array) ($part['functionCall']['args'] ?? [])];
                }
            }
            if (trim($text) === '' && $calls === []) {
                continue;
            }
            $usage = (array) data_get($response->json(), 'usageMetadata', []);

            return [
                'text' => trim($text),
                'calls' => $calls,
                'parts' => $parts,
                'usage' => [
                    'in' => (int) ($usage['promptTokenCount'] ?? 0),
                    'out' => (int) ($usage['candidatesTokenCount'] ?? 0),
                    'thoughts' => (int) ($usage['thoughtsTokenCount'] ?? 0),
                    // The part of what it read that came from its own cache (billed at the cache's price).
                    'cached' => (int) ($usage['cachedContentTokenCount'] ?? 0),
                ],
                'model' => $model,
            ];
        }

        return null;
    }

    /**
     * A file the owner uploaded (a PDF or a photo of a menu), read by the text model with the
     * prompt beside it. Returns the reply text or null.
     */
    public function readFile(string $prompt, string $mime, string $bytes, int $timeout = 90): ?string
    {
        $deadline = microtime(true) + $timeout;
        foreach (self::WRITER_MODELS as $model) {
            $left = (int) floor($deadline - microtime(true));
            if ($left < 8) {
                break;
            }
            $response = $this->post("/models/{$model}:generateContent", [
                'contents' => [['role' => 'user', 'parts' => [
                    ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode($bytes)]],
                    ['text' => $prompt],
                ]]],
                'generationConfig' => ['temperature' => 0.1],
            ], $model, $left);
            if ($response?->successful()) {
                $text = data_get($response->json(), 'candidates.0.content.parts.0.text');
                if (is_string($text) && trim($text) !== '') {
                    $usage = (array) data_get($response->json(), 'usageMetadata', []);
                    $this->lastUsage = [
                        'in' => (int) ($usage['promptTokenCount'] ?? 0),
                        'out' => (int) ($usage['candidatesTokenCount'] ?? 0) + (int) ($usage['thoughtsTokenCount'] ?? 0),
                        'cached' => (int) ($usage['cachedContentTokenCount'] ?? 0),
                        'model' => $model,
                    ];

                    return $text;
                }
            }
        }

        return null;
    }

    /**
     * What a caller said, written down from their voice (a WAV recording) by the text model, with
     * the prompt beside it: quick (little thinking) and exact (temperature 0). Returns the words,
     * '' when the model heard none, or null when no model answered in time.
     */
    public function transcribe(string $prompt, string $wav, int $timeout = 12, string $thinking = 'low'): ?string
    {
        $deadline = microtime(true) + $timeout;
        $body = fn (bool $think): array => [
            'contents' => [['role' => 'user', 'parts' => [
                ['inline_data' => ['mime_type' => 'audio/wav', 'data' => base64_encode($wav)]],
                ['text' => $prompt],
            ]]],
            'generationConfig' => ['temperature' => 0] + ($think ? ['thinkingConfig' => ['thinkingLevel' => $thinking]] : []),
        ];
        foreach (self::WRITER_MODELS as $model) {
            $left = (int) floor($deadline - microtime(true));
            if ($left < 3) {
                break;
            }
            $response = $this->post("/models/{$model}:generateContent", $body(true), $model, $left);
            // A model that does not take that thinking level is asked again with its own.
            if ($response?->status() === 400 && str_contains(strtolower((string) $response->body()), 'thinking')) {
                $left = (int) floor($deadline - microtime(true));
                $response = $left >= 3 ? $this->post("/models/{$model}:generateContent", $body(false), $model, $left) : null;
            }
            if ($response?->successful()) {
                $text = data_get($response->json(), 'candidates.0.content.parts.0.text');

                return is_string($text) ? trim($text) : '';
            }
        }

        return null;
    }

    /**
     * A key for one live call. Returns ['token' => ..., 'expires_at' => ISO time] or null.
     *
     * @param  array<string, mixed>  $setup  the session setup (LiveSession::setupFor)
     */
    public function liveToken(array $setup, int $seconds): ?array
    {
        $now = time();
        $expires = $now + max(60, $seconds);
        $response = $this->post('/auth_tokens', [
            'uses' => 1,
            'expireTime' => gmdate('Y-m-d\TH:i:s\Z', $expires),
            'newSessionExpireTime' => gmdate('Y-m-d\TH:i:s\Z', $now + 90),
            // The whole setup, locked: the setup the browser sends is ignored (checked live by the
            // agent call test: a "pirate" setup from the browser did not change the agent).
            'bidiGenerateContentSetup' => $setup + ['model' => 'models/'.self::LIVE_MODEL],
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
