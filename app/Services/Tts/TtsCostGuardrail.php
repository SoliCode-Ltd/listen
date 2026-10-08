<?php

namespace App\Services\Tts;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Site-wide daily ceilings on speech generation (config listen.cost_guardrails).
 *
 * They sit on top of every plan and demo limit, so a bug or an abuse path
 * elsewhere cannot run up an unlimited bill in one day. A limit of 0 turns
 * that ceiling off.
 */
class TtsCostGuardrail
{
    /**
     * @throws TtsCostLimitException
     */
    public function assertCanSynthesize(string $provider, int $chars): void
    {
        $chars = max(0, $chars);

        $totalLimit = $this->limit('daily_total_char_limit');
        if ($totalLimit > 0 && ($this->used('total_chars') + $chars) > $totalLimit) {
            $this->refuse('daily_total_char_limit', $provider, $totalLimit);
        }

        if ($provider !== 'gemini') {
            return;
        }

        $geminiCharLimit = $this->limit('daily_gemini_char_limit');
        if ($geminiCharLimit > 0 && ($this->used('gemini_chars') + $chars) > $geminiCharLimit) {
            $this->refuse('daily_gemini_char_limit', $provider, $geminiCharLimit);
        }

        $geminiRequestLimit = $this->limit('daily_gemini_request_limit');
        if ($geminiRequestLimit > 0 && ($this->used('gemini_requests') + 1) > $geminiRequestLimit) {
            $this->refuse('daily_gemini_request_limit', $provider, $geminiRequestLimit);
        }
    }

    public function record(string $provider, int $chars): void
    {
        $chars = max(0, $chars);

        $this->increment('total_chars', $chars);

        if ($provider === 'gemini') {
            $this->increment('gemini_chars', $chars);
            $this->increment('gemini_requests', 1);
        }
    }

    /**
     * @return array{total_chars:int,gemini_chars:int,gemini_requests:int}
     */
    public function usageToday(): array
    {
        return [
            'total_chars' => $this->used('total_chars'),
            'gemini_chars' => $this->used('gemini_chars'),
            'gemini_requests' => $this->used('gemini_requests'),
        ];
    }

    private function limit(string $name): int
    {
        return max(0, (int) config("listen.cost_guardrails.{$name}", 0));
    }

    private function used(string $counter): int
    {
        return (int) Cache::get($this->key($counter), 0);
    }

    private function increment(string $counter, int $amount): void
    {
        if ($amount <= 0) {
            return;
        }

        $key = $this->key($counter);
        Cache::add($key, 0, now()->endOfDay()->addHour());
        Cache::increment($key, $amount);
    }

    private function key(string $counter): string
    {
        return 'tts:guardrail:'.now()->format('Ymd').':'.$counter;
    }

    /**
     * @throws TtsCostLimitException
     */
    private function refuse(string $limitName, string $provider, int $limit): never
    {
        Log::warning('Daily speech cost limit reached; request refused.', [
            'limit' => $limitName,
            'value' => $limit,
            'provider' => $provider,
            'usage_today' => $this->usageToday(),
        ]);

        throw new TtsCostLimitException("Daily speech limit reached ({$limitName}).");
    }
}
