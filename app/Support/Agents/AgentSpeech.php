<?php

namespace App\Support\Agents;

use App\Models\Agent;
use App\Models\AgentConversation;
use App\Services\Tts\GeminiKeyManager;
use App\Support\ArabicDialects;
use App\Support\EnglishAccents;
use App\Support\GeminiSpend;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\StreamInterface;

/**
 * The studio voice in a call, for an agent whose settings say voice_engine = studio (Listen's own
 * agent first, while the owner listens to it). The live model still listens, thinks, answers and
 * uses its tools; the call client (public/js/agent-call.js) takes the words it says and speaks them
 * with the studio's speech model, a phrase at a time, streamed as each is made (its first sound
 * comes 0.6 to 0.9 s after the phrase is asked for, and the rest comes faster than it plays).
 *
 * The voice is the agent's own, directed the way that made the owner's test caller sound human: an
 * ordinary person at work on the phone, in the agent's dialect, never a presenter or someone reading.
 * The live voice stays the fallback: the client plays it whenever this one fails or is slow.
 *
 * A call's key only speaks while its call is live, a phrase at most MAX_CHARS long and at most
 * CHARS_PER_MINUTE for each minute the call may last; what it costs is added to the call's cost.
 */
final class AgentSpeech
{
    public const MODEL = 'gemini-3.8-flash-tts';

    /** The longest phrase one request speaks. */
    public const MAX_CHARS = 400;

    /** What a call may speak, for each minute it may last (people say about 900 a minute). */
    public const CHARS_PER_MINUTE = 1500;

    public static function enabled(?Agent $agent): bool
    {
        return $agent !== null && $agent->setting('voice_engine') === 'studio';
    }

    /** The phrase as the speech model takes it: one line, no control characters, at most MAX_CHARS. */
    public static function clean(string $text): string
    {
        $text = (string) preg_replace('/[\p{Cc}\p{Cf}]+/u', ' ', $text);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_substr($text, 0, self::MAX_CHARS);
    }

    /** The speaking direction: the agent's dialect (as the studio says it), spoken like a person at work. */
    public static function style(Agent $agent): string
    {
        $person = AgentPersona::voiceGender($agent) === 'male' ? 'man' : 'woman';
        $business = trim((string) $agent->business_name) ?: 'this business';
        if ($agent->language === 'en') {
            $accent = trim((string) (EnglishAccents::ALL[$agent->dialect ?? '']['prompt'] ?? $agent->setting('accent_prompt', '')));
            $speech = 'natural, everyday English'.($accent !== '' ? " with this accent: {$accent}" : '');
            $markers = '';
        } else {
            $code = $agent->dialect ?: 'ar-XA-WH';
            $name = ArabicDialects::prompt($code) ?? 'Arabic';
            $speech = ArabicDialects::isPlainFusha($code)
                ? "{$name}, clear and natural, the way a friendly person speaks it on the phone (never like a news reader)"
                : "everyday spoken {$name}, as someone from there speaks it, with its authentic sounds";
            $markers = (string) (ArabicDialects::markers($code) ?? '');
        }

        return "Speak like an ordinary {$person} who works at {$business}, talking with a customer on the phone: natural, relaxed, warm and friendly, at an everyday pace, in {$speech}."
            .($markers !== '' ? " Dialect markers to make clearly audible: {$markers}" : '')
            .' Not a presenter, an announcer or someone reading: just a real person chatting on the phone at work.';
    }

    /**
     * Counts the phrase against the call's allowance. False once the call has spoken what it may.
     */
    public static function reserve(AgentConversation $conversation, string $text): bool
    {
        $minutes = max(1, (int) ceil(((int) ($conversation->reservation['seconds'] ?? 600)) / 60));
        $key = 'agent-speech:'.$conversation->id.':chars';
        Cache::add($key, 0, now()->addHours(3));
        $used = (int) Cache::increment($key, mb_strlen($text));

        return $used <= $minutes * self::CHARS_PER_MINUTE;
    }

    /**
     * Asks the speech model for the phrase and returns its answer as it streams (Server-Sent Events,
     * each with a piece of 16-bit mono PCM at 24 kHz), or null when no key could make it.
     */
    public static function open(Agent $agent, string $text): ?StreamInterface
    {
        $payload = [
            'contents' => [['role' => 'user', 'parts' => [['text' => $text, 'speech_metadata' => ['style' => self::style($agent)]]]]],
            'generationConfig' => [
                'responseModalities' => ['AUDIO'],
                'speechConfig' => ['voiceConfig' => ['voice' => $agent->live_voice ?: 'Achernar']],
            ],
        ];
        $base = rtrim((string) config('services.gemini_tts.endpoint', 'https://generativelanguage.googleapis.com/v1beta'), '/');
        $manager = app(GeminiKeyManager::class);
        $keys = array_values(array_filter($manager->keysInOrder(self::MODEL), fn ($k) => filled($k['key'] ?? null)));
        $configured = trim((string) config('services.gemini_tts.api_key', ''));
        if ($configured !== '' && ! in_array($configured, array_column($keys, 'key'), true)) {
            array_unshift($keys, ['key' => $configured, 'name' => 'GEMINI_TTS_API_KEY']);
        }
        foreach (array_slice($keys, 0, 3) as $key) {
            try {
                $response = Http::withOptions(['stream' => true])->timeout(20)->connectTimeout(5)
                    ->withHeaders(['Content-Type' => 'application/json', 'x-goog-api-key' => $key['key']])
                    ->post($base.'/models/'.self::MODEL.':streamGenerateContent?alt=sse', $payload);
            } catch (\Throwable $e) {
                Log::warning('Agent studio voice: request failed.', ['error' => $e->getMessage()]);

                return null;
            }
            if ($response->successful()) {
                return $response->toPsrResponse()->getBody();
            }
            $body = (string) $response->body();
            $badKey = GeminiKeyManager::isKeyProblem($response->status(), $body);
            if (filled($key['name'] ?? null) && ($badKey || GeminiKeyManager::isDailyQuotaError($response->status(), $body))) {
                $manager->markOutForToday((string) $key['name'], self::MODEL);
            }
            if (! $badKey && ! in_array($response->status(), [429, 403], true)) {
                Log::warning('Agent studio voice: refused.', ['status' => $response->status()]);

                return null;
            }
        }

        return null;
    }

    /**
     * Reads the speech model's stream and hands each piece of PCM to $write as it comes. Returns the
     * model's token counts (or null when it gave none).
     *
     * @param  callable(string): void  $write
     * @return array<string, mixed>|null
     */
    public static function pump(StreamInterface $body, callable $write): ?array
    {
        $buffer = '';
        $usage = null;
        $line = function (string $line) use ($write, &$usage): void {
            $line = trim($line);
            if (! str_starts_with($line, 'data:')) {
                return;
            }
            $event = json_decode(trim(substr($line, 5)), true);
            if (! is_array($event)) {
                return;
            }
            foreach ((array) data_get($event, 'candidates.0.content.parts', []) as $part) {
                $pcm = is_string($part['inlineData']['data'] ?? null) ? base64_decode($part['inlineData']['data'], true) : false;
                if (is_string($pcm) && $pcm !== '') {
                    $write($pcm);
                }
            }
            if (is_array($event['usageMetadata'] ?? null)) {
                $usage = $event['usageMetadata'];
            }
        };
        while (! $body->eof()) {
            $buffer .= $body->read(8192);
            while (($at = strpos($buffer, "\n")) !== false) {
                $line(substr($buffer, 0, $at));
                $buffer = substr($buffer, $at + 1);
            }
        }
        if ($buffer !== '') {
            $line($buffer);
        }

        return $usage;
    }

    /** Adds what a phrase cost to the call (read when the call ends: spent()). */
    public static function record(AgentConversation $conversation, ?array $usage): void
    {
        if ($usage === null) {
            return;
        }
        $cost = GeminiSpend::price(self::MODEL, (int) ($usage['promptTokenCount'] ?? 0), (int) ($usage['candidatesTokenCount'] ?? 0));
        $key = 'agent-speech:'.$conversation->id.':micro-usd';
        Cache::add($key, 0, now()->addHours(3));
        Cache::increment($key, (int) round($cost * 1e6));
    }

    /** What the studio voice cost this call so far, in dollars. */
    public static function spent(AgentConversation $conversation): float
    {
        return ((int) Cache::get('agent-speech:'.$conversation->id.':micro-usd', 0)) / 1e6;
    }
}
