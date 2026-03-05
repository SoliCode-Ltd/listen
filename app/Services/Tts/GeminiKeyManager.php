<?php

namespace App\Services\Tts;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
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
    public function getAvailableKey(): ?array
    {
        $keys = $this->getAllKeys();

        foreach ($keys as $keyInfo) {
            $stats = $this->getKeyStats($keyInfo['name']);
            
            // Check if key has capacity
            if ($stats['rpm_remaining'] > 0 && 
                $stats['rpd_remaining'] > 0 && 
                $stats['tpm_remaining'] > 1000) { // Need at least 1K tokens
                return $keyInfo;
            }
        }

        return null;
    }

    /**
     * Get usage statistics for a specific key.
     *
     * @param string $keyName The env variable name (e.g., GEMINI_TTS_API_KEY_2)
     * @return array{rpm_used: int, rpm_limit: int, rpm_remaining: int, tpm_used: int, tpm_limit: int, tpm_remaining: int, rpd_used: int, rpd_limit: int, rpd_remaining: int, is_exhausted: bool}
     */
    public function getKeyStats(string $keyName): array
    {
        $rpmLimit = (int) env('GEMINI_TTS_RPM_LIMIT', self::DEFAULT_RPM_LIMIT);
        $tpmLimit = (int) env('GEMINI_TTS_TPM_LIMIT', self::DEFAULT_TPM_LIMIT);
        $rpdLimit = (int) env('GEMINI_TTS_RPD_LIMIT', self::DEFAULT_RPD_LIMIT);

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
        $rpmLimit = (int) env('GEMINI_TTS_RPM_LIMIT', self::DEFAULT_RPM_LIMIT);
        Cache::put($cacheKey, $rpmLimit, self::CACHE_TTL_MINUTE);

        Log::warning('Gemini key marked as exhausted', ['key' => $keyName]);
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

        // Append to .env
        $envContent = File::get($envPath);
        $envContent .= "\n{$newKeyName}={$apiKey}";
        File::put($envPath, $envContent);

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
        
        File::put($envPath, trim($newContent) . "\n");

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

    private function incrementRpm(string $keyName): void
    {
        $cacheKey = $this->cacheKey('rpm', $keyName);
        $current = (int) Cache::get($cacheKey, 0);
        Cache::put($cacheKey, $current + 1, self::CACHE_TTL_MINUTE);
    }

    private function incrementTpm(string $keyName, int $tokens): void
    {
        $cacheKey = $this->cacheKey('tpm', $keyName);
        $current = (int) Cache::get($cacheKey, 0);
        Cache::put($cacheKey, $current + $tokens, self::CACHE_TTL_MINUTE);
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
