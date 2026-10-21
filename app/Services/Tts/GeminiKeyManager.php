<?php

namespace App\Services\Tts;

use App\Support\EnvFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Gemini API Key Manager - Multi-Key Rotation System.
 *
 * Manages multiple Gemini API keys with automatic rotation when limits are hit.
 * Each key has independent usage tracking (RPM, TPM, RPD).
 *
 * Rate Limits per key (Gemini 2.5 Flash TTS):
 * - RPM: 10 requests per minute
 * - TPM: 10,000 tokens per minute (approx 40K chars)
 * - RPD: 100 requests per day
 */
class GeminiKeyManager
{
    // Gemini 2.5 Flash TTS default limits per key
    private const DEFAULT_RPM_LIMIT = 10;
    private const DEFAULT_TPM_LIMIT = 10000;
    private const DEFAULT_RPD_LIMIT = 100;
    private const APPROX_CHARS_PER_TOKEN = 4;

    private const CACHE_PREFIX = 'gemini_key';
    private const CACHE_TTL_DAY = 86400; // 24 hours
    private const CACHE_TTL_MINUTE = 60;

    /** A key stopped by its project's spending cap is set aside this long, then tried again. */
    private const CACHE_TTL_SPENDING_CAP = 3600;

    /**
     * What Google says when a project's spending cap or prepaid credits stop
     * it. Not "billing" alone: every ordinary 429 asks to "check your plan and
     * billing details".
     */
    private const SPENDING_CAP_PATTERN = '/spend(?:ing)?[ _-]?(?:cap|limit)|prepa(?:id|yment)|credits? (?:are |is |have been )?(?:depleted|exhausted|used up)|billing account/i';

    /**
     * Get all configured API keys dynamically from .env.
     * Supports unlimited keys with pattern GEMINI_TTS_API_KEY_N.
     *
     * @return array<int, array{key: string, name: string, index: int}>
     */
    public function getAllKeys(): array
    {
        $keys = [];
        $envPath = base_path('.env');

        if (!File::exists($envPath)) {
            return $keys;
        }

        $envContent = File::get($envPath);
        $lines = explode("\n", $envContent);

        foreach ($lines as $line) {
            $line = trim($line);
            if (str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$keyName, $value] = explode('=', $line, 2);
            $keyName = trim($keyName);
            $value = trim($value, " \t\n\r\0\x0B\"'");

            // Match GEMINI_TTS_API_KEY or GEMINI_TTS_API_KEY_N
            if (preg_match('/^GEMINI_TTS_API_KEY(_(\d+))?$/', $keyName, $matches)) {
                $index = isset($matches[2]) ? (int) $matches[2] : 1;
                if (!empty($value)) {
                    $keys[] = [
                        'key' => $value,
                        'name' => $keyName,
                        'index' => $index,
                    ];
                }
            }
        }

        // Sort by index
        usort($keys, fn($a, $b) => $a['index'] <=> $b['index']);

        return $keys;
    }

    /**
     * Get the next available key that hasn't exceeded its limits.
     *
     * @return array{key: string, name: string, index: int}|null
     */
    public function getAvailableKey(?string $model = null): ?array
    {
        return $this->keysWithRoom($model)[0] ?? null;
    }

    /**
     * The keys with room left this minute and today (not out for the day on
     * this model, nor stopped by their spending cap), the least used this
     * minute first, then in their order. Each key has its own limits, so
     * handing out the least used one spreads the voices over all of them:
     * before, every request went to the first key until it hit its limit
     * (on live in October 2026 the first key was set aside 94 times in two
     * weeks, the second 3 times, the third never).
     *
     * @return array<int, array{key: string, name: string, index: int}>
     */
    public function keysWithRoom(?string $model = null): array
    {
        $keys = [];
        foreach ($this->getAllKeys() as $keyInfo) {
            if ($model !== null && $this->isOutForToday($keyInfo['name'], $model)) {
                continue;
            }
            $stats = $this->getKeyStats($keyInfo['name']);
            // Need at least 1K tokens.
            if ($stats['rpm_remaining'] > 0 && $stats['rpd_remaining'] > 0 && $stats['tpm_remaining'] > 1000) {
                $keys[] = [$stats['rpm_used'], $keyInfo];
            }
        }
        usort($keys, fn (array $a, array $b): int => [$a[0], $a[1]['index']] <=> [$b[0], $b[1]['index']]);

        return array_column($keys, 1);
    }

    /**
     * Get usage statistics for a specific key.
     *
     * @param string $keyName The env variable name (e.g., GEMINI_TTS_API_KEY_2)
     * @return array{rpm_used: int, rpm_limit: int, rpm_remaining: int, tpm_used: int, tpm_limit: int, tpm_remaining: int, rpd_used: int, rpd_limit: int, rpd_remaining: int, is_exhausted: bool}
     */
    public function getKeyStats(string $keyName): array
    {
        $rpmLimit = $this->rpmLimit();
        $tpmLimit = (int) config('services.gemini_tts.tpm_limit', self::DEFAULT_TPM_LIMIT);
        $rpdLimit = (int) config('services.gemini_tts.rpd_limit', self::DEFAULT_RPD_LIMIT);

        $rpmUsed = $this->getRpmUsage($keyName);
        $tpmUsed = $this->getTpmUsage($keyName);
        $rpdUsed = $this->getRpdUsage($keyName);

        $rpmRemaining = max(0, $rpmLimit - $rpmUsed);
        $tpmRemaining = max(0, $tpmLimit - $tpmUsed);
        $rpdRemaining = max(0, $rpdLimit - $rpdUsed);

        return [
            'rpm_used' => $rpmUsed,
            'rpm_limit' => $rpmLimit,
            'rpm_remaining' => $rpmRemaining,
            'tpm_used' => $tpmUsed,
            'tpm_limit' => $tpmLimit,
            'tpm_remaining' => $tpmRemaining,
            'rpd_used' => $rpdUsed,
            'rpd_limit' => $rpdLimit,
            'rpd_remaining' => $rpdRemaining,
            'is_exhausted' => $rpmRemaining <= 0 || $rpdRemaining <= 0,
        ];
    }

    /**
     * Record a successful API request for a key.
     *
     * @param string $keyName The env variable name
     * @param int $charCount Number of characters processed
     */
    public function recordUsage(string $keyName, int $charCount = 0): void
    {
        // Increment RPM (resets every minute)
        $this->incrementRpm($keyName);

        // Increment TPM (tokens, resets every minute)
        $tokens = (int) ceil($charCount / self::APPROX_CHARS_PER_TOKEN);
        $this->incrementTpm($keyName, $tokens);

        // Increment RPD (resets every day)
        $this->incrementRpd($keyName);

        Log::debug('Gemini key usage recorded', [
            'key' => $keyName,
            'chars' => $charCount,
            'tokens' => $tokens,
            'stats' => $this->getKeyStats($keyName),
        ]);
    }

    /**
     * Mark a key as temporarily exhausted (hit 429).
     *
     * @param string $keyName The env variable name
     */
    public function markKeyExhausted(string $keyName): void
    {
        // Set RPM to limit to prevent use until minute expires
        $cacheKey = $this->cacheKey('rpm', $keyName);
        Cache::put($cacheKey, $this->rpmLimit(), self::CACHE_TTL_MINUTE);

        Log::warning('Gemini key marked as exhausted', ['key' => $keyName]);
    }

    /**
     * Every key, best first: the preferred one, then those with room left,
     * then the ones Google has cut off for today (a last resort, in case
     * the quota came back early).
     *
     * @return array<int, array{key: string, name: string, index: int}>
     */
    public function keysInOrder(?string $model = null, ?string $preferred = null): array
    {
        $keys = $this->getAllKeys();
        $rank = function (array $k) use ($model, $preferred): int {
            $out = $model !== null && $this->isOutForToday($k['name'], $model);
            if ($out) {
                return 3;
            }
            if ($preferred !== null && $k['name'] === $preferred) {
                return 0;
            }

            return $this->getKeyStats($k['name'])['is_exhausted'] ? 2 : 1;
        };
        usort($keys, fn ($a, $b) => [$rank($a), $a['index']] <=> [$rank($b), $b['index']]);

        return $keys;
    }

    /**
     * Google cut this key off for the day on this model (a "per day" 429).
     * It stays skipped until Google's daily reset, midnight Pacific time,
     * so later requests do not waste a call on it first.
     */
    public function markOutForToday(string $keyName, string $model): void
    {
        $reset = now('America/Los_Angeles')->addDay()->startOfDay();
        Cache::put($this->outKey($keyName, $model), true, $reset->utc());

        Log::warning('Gemini key reached its daily limit for this model; using the other keys until it resets.', [
            'key' => $keyName,
            'model' => $model,
            'resets_at' => $reset->toIso8601String(),
        ]);
    }

    /**
     * Out on this model for the day, or out on every model for a while after
     * its project's spending cap stopped it.
     */
    public function isOutForToday(string $keyName, string $model): bool
    {
        return (bool) Cache::get($this->outKey($keyName, $model), false)
            || (bool) Cache::get($this->spendingCapKey($keyName), false);
    }

    /**
     * Google stopped this key for its project's spending cap (or used-up
     * prepaid credits). That lasts until someone raises the cap or the month
     * turns, and it stops every model on the key, so the key is set aside on
     * all of them for an hour instead of the minute a rate limit gets. Like a
     * key out for the day, stock voices still try it last when no key has
     * room, so they come back as soon as the cap is raised; cloned voices skip
     * it until the hour is over.
     */
    public function markOutForSpendingCap(string $keyName): void
    {
        Cache::put($this->spendingCapKey($keyName), true, self::CACHE_TTL_SPENDING_CAP);

        Log::warning('Gemini key stopped by its spending cap; using the other keys and trying it again in an hour.', [
            'key' => $keyName,
        ]);
    }

    /**
     * A 429 that means the daily quota is used up (not the per-minute one).
     */
    public static function isDailyQuotaError(int $status, string $body): bool
    {
        return $status === 429 && (bool) preg_match('/per ?day|PerDay|daily/i', $body);
    }

    /**
     * A reply that means the key's project hit its spending cap or ran out of
     * prepaid credits (a 429, or a 403): not the per-minute rate limit.
     */
    public static function isSpendingCapError(int $status, string $body): bool
    {
        return in_array($status, [403, 429], true) && self::mentionsSpendingCap($body);
    }

    public static function mentionsSpendingCap(string $text): bool
    {
        return (bool) preg_match(self::SPENDING_CAP_PATTERN, $text);
    }

    /**
     * Google refused the key itself (invalid, expired, or not allowed): the
     * request can still succeed on another key.
     */
    public static function isKeyProblem(int $status, string $body): bool
    {
        return in_array($status, [400, 401, 403], true)
            && (bool) preg_match('/API_KEY_INVALID|API key not valid|API key expired|API_KEY_SERVICE_BLOCKED|PERMISSION_DENIED|SERVICE_DISABLED|billing/i', $body);
    }

    /**
     * The model a request path is for (/models/{model}:generateContent).
     */
    public static function modelFromPath(string $path, string $fallback): string
    {
        return preg_match('#/models/([^:/?]+)#', $path, $m) ? $m[1] : $fallback;
    }

    private function outKey(string $keyName, string $model): string
    {
        return self::CACHE_PREFIX."_dayout_{$model}_{$keyName}";
    }

    private function spendingCapKey(string $keyName): string
    {
        return self::CACHE_PREFIX."_capout_{$keyName}";
    }

    /**
     * Get aggregate statistics for all keys.
     *
     * @return array{total_keys: int, available_keys: int, total_rpm_remaining: int, total_rpd_remaining: int, keys: array}
     */
    public function getAggregateStats(): array
    {
        $keys = $this->getAllKeys();
        $totalRpmRemaining = 0;
        $totalRpdRemaining = 0;
        $availableKeys = 0;
        $keyStats = [];

        foreach ($keys as $keyInfo) {
            $stats = $this->getKeyStats($keyInfo['name']);
            $totalRpmRemaining += $stats['rpm_remaining'];
            $totalRpdRemaining += $stats['rpd_remaining'];
            
            if (!$stats['is_exhausted']) {
                $availableKeys++;
            }

            $keyStats[] = [
                'name' => $keyInfo['name'],
                'index' => $keyInfo['index'],
                'masked_key' => $this->maskKey($keyInfo['key']),
                'stats' => $stats,
            ];
        }

        return [
            'total_keys' => count($keys),
            'available_keys' => $availableKeys,
            'total_rpm_remaining' => $totalRpmRemaining,
            'total_rpd_remaining' => $totalRpdRemaining,
            'keys' => $keyStats,
        ];
    }

    /**
     * Add a new API key to .env file.
     *
     * @param string $apiKey The new API key value
     * @return string The name assigned to the key (e.g., GEMINI_TTS_API_KEY_3)
     */
    /**
     * Whether a key pasted in the admin can be saved. Google's keys no longer all
     * start with "AIza", so any plain run of letters, digits, ".", "_" and "-" is
     * taken; Google is then asked to list its models with the key (no speech, no
     * quota used). A key Google refuses is not saved; when Google cannot be
     * reached the key is saved, with a warning.
     *
     * @return array{ok: bool, checked: bool, message: string}
     */
    public function checkNewKey(string $apiKey): array
    {
        $apiKey = trim($apiKey);
        if (! preg_match('/^[A-Za-z0-9._\-]{20,256}$/', $apiKey)) {
            return ['ok' => false, 'checked' => false, 'message' => 'That does not look like an API key: paste the whole key from Google AI Studio, with no spaces.'];
        }
        if (in_array($apiKey, array_column($this->getAllKeys(), 'key'), true)) {
            return ['ok' => false, 'checked' => false, 'message' => 'This key is already saved.'];
        }

        $base = rtrim((string) config('services.gemini_tts.endpoint', 'https://generativelanguage.googleapis.com/v1beta'), '/');
        try {
            $response = Http::timeout(15)->withHeaders(['x-goog-api-key' => $apiKey])->get($base.'/models', ['pageSize' => 1]);
        } catch (\Throwable) {
            return ['ok' => true, 'checked' => false, 'message' => 'Saved, but Google could not be reached to check it.'];
        }
        if ($response->successful()) {
            return ['ok' => true, 'checked' => true, 'message' => 'Google accepted the key.'];
        }
        if (in_array($response->status(), [400, 401, 403], true)) {
            $why = trim((string) $response->json('error.message'));
            $why = str_replace($apiKey, '[key]', $why);

            return ['ok' => false, 'checked' => true, 'message' => 'Google did not accept this key'.($why !== '' ? ': '.mb_substr($why, 0, 200) : '.')];
        }

        return ['ok' => true, 'checked' => false, 'message' => 'Saved, but Google did not answer the check (HTTP '.$response->status().').'];
    }

    public function addKey(string $apiKey): string
    {
        $envPath = base_path('.env');
        $existingKeys = $this->getAllKeys();
        
        // Find the next available index
        $maxIndex = 1;
        foreach ($existingKeys as $keyInfo) {
            if ($keyInfo['index'] >= $maxIndex) {
                $maxIndex = $keyInfo['index'] + 1;
            }
        }

        $newKeyName = $maxIndex === 1 ? 'GEMINI_TTS_API_KEY' : "GEMINI_TTS_API_KEY_{$maxIndex}";

        // Append to .env (checked, with a copy of the old file kept: see EnvFile)
        $envContent = File::get($envPath);
        $envContent .= "\n{$newKeyName}=".EnvFile::value($apiKey);
        EnvFile::write($envPath, $envContent);

        Log::info('New Gemini API key added', ['name' => $newKeyName]);

        return $newKeyName;
    }

    /**
     * Remove an API key from .env file.
     *
     * @param string $keyName The env variable name to remove
     * @return bool
     */
    public function removeKey(string $keyName): bool
    {
        $envPath = base_path('.env');
        $envContent = File::get($envPath);

        $pattern = '/^' . preg_quote($keyName, '/') . '=.*/m';
        $newContent = preg_replace($pattern, '', $envContent);
        
        // Clean up double line breaks
        $newContent = preg_replace('/\n{3,}/', "\n\n", $newContent);
        
        EnvFile::write($envPath, trim($newContent) . "\n");

        // Clear cached stats for this key
        $this->clearKeyCache($keyName);

        Log::info('Gemini API key removed', ['name' => $keyName]);

        return true;
    }

    /**
     * Reset usage counters for all keys.
     */
    public function resetAllCounters(): void
    {
        foreach ($this->getAllKeys() as $keyInfo) {
            $this->clearKeyCache($keyInfo['name']);
        }

        Log::info('All Gemini key counters reset');
    }

    // ---------- Private helpers ----------

    private function rpmLimit(): int
    {
        return (int) config('services.gemini_tts.rpm_limit', self::DEFAULT_RPM_LIMIT);
    }

    private function getRpmUsage(string $keyName): int
    {
        return (int) Cache::get($this->cacheKey('rpm', $keyName), 0);
    }

    private function getTpmUsage(string $keyName): int
    {
        return (int) Cache::get($this->cacheKey('tpm', $keyName), 0);
    }

    private function getRpdUsage(string $keyName): int
    {
        $today = now()->format('Y-m-d');
        return (int) Cache::get($this->cacheKey('rpd_' . $today, $keyName), 0);
    }

    /**
     * A fixed one-minute window from the first request in it. Writing the count
     * again would restart the minute on every use, so under steady traffic a
     * key never looked free again and every request piled onto the first key.
     */
    private function incrementRpm(string $keyName): void
    {
        $cacheKey = $this->cacheKey('rpm', $keyName);
        Cache::add($cacheKey, 0, self::CACHE_TTL_MINUTE);
        Cache::increment($cacheKey);
    }

    private function incrementTpm(string $keyName, int $tokens): void
    {
        $cacheKey = $this->cacheKey('tpm', $keyName);
        Cache::add($cacheKey, 0, self::CACHE_TTL_MINUTE);
        Cache::increment($cacheKey, $tokens);
    }

    private function incrementRpd(string $keyName): void
    {
        $today = now()->format('Y-m-d');
        $cacheKey = $this->cacheKey('rpd_' . $today, $keyName);
        $current = (int) Cache::get($cacheKey, 0);
        Cache::put($cacheKey, $current + 1, self::CACHE_TTL_DAY);
    }

    private function clearKeyCache(string $keyName): void
    {
        $today = now()->format('Y-m-d');
        Cache::forget($this->cacheKey('rpm', $keyName));
        Cache::forget($this->cacheKey('tpm', $keyName));
        Cache::forget($this->cacheKey('rpd_' . $today, $keyName));
    }

    private function cacheKey(string $type, string $keyName): string
    {
        return self::CACHE_PREFIX . "_{$type}_{$keyName}";
    }

    private function maskKey(string $key): string
    {
        $length = strlen($key);
        if ($length <= 8) {
            return str_repeat('•', $length);
        }
        return substr($key, 0, 6) . '...' . substr($key, -4);
    }
}
