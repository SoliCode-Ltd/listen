<?php

namespace App\Services;

use App\Models\Plan;
use App\Services\Tts\ArabicVoiceMapper;
use App\Services\Tts\TtsRoutingService;
use App\Support\ArabicDialects;
use App\Support\GeminiTtsModel;
use App\Support\PaceAndPitch;
use App\Support\StandardVoiceEngine;
use App\Support\TtsStudioConfig;
use App\Support\VoiceCatalog;
use App\Support\VoiceTier;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TtsService
{
    private ?TtsRoutingService $routingService = null;
    private ?ArabicVoiceMapper $arabicMapper = null;

    /**
     * Inject the routing service (optional for backward compat).
     */
    public function setRoutingService(TtsRoutingService $routingService): void
    {
        $this->routingService = $routingService;
    }

    public function setArabicMapper(ArabicVoiceMapper $mapper): void
    {
        $this->arabicMapper = $mapper;
    }

    /**
     * Original synthesize method – BACKWARD COMPATIBLE.
     *
     * @return array{audio_path:string,duration_seconds:int|null,provider:string}
     */
    public function synthesize(
        string $text,
        string $targetLanguage,
        ?string $voiceName = null,
        string $tier = 'standard',
        bool $preferFast = false,
    ): array {
        $text = trim($text);
        if ($text === '') {
            throw new \RuntimeException('TTS text is empty.');
        }

        $mode = $preferFast ? 'fast' : 'quality';
        $voice = $this->normalizeLegacyGoogleVoice(
            $this->resolveVoiceName($targetLanguage, $voiceName, $tier),
            $targetLanguage,
            $tier,
        );
        $hash = sha1($mode.'|'.$targetLanguage.'|'.$tier.'|'.$voice.'|'.$text);

        if ($cached = $this->findCachedAudio($hash, $targetLanguage)) {
            return [
                'audio_path' => $cached,
                'duration_seconds' => $this->estimateDurationSeconds($text),
                'provider' => 'cache',
            ];
        }

        $generated = $this->synthesizeWithGoogleCloud($text, $targetLanguage, $voice);
        $cachedPath = $this->storeInCache($generated['audio_path'], $hash, $targetLanguage);

        return [
            'audio_path' => $cachedPath,
            'duration_seconds' => $generated['duration_seconds'] ?? $this->estimateDurationSeconds($text),
            'provider' => $generated['provider'] ?? 'tts-engine',
        ];
    }

    /**
     * Enhanced synthesize method using the routing service.
     *
     * Uses quality-aware provider selection (HD never falls back to a
     * standard voice), Arabic voice mapping, and telemetry logging.
     *
     * @param  string     $text           Text to synthesize
     * @param  string     $targetLanguage Language code
     * @param  array      $options        Extended options:
     *                                     - voice_name: string
     *                                     - tier: string
     *                                     - dialect: string (e.g. 'ar-SA')
     *                                     - emotion: string (e.g. 'warm')
     *                                     - gender: string
     *                                     - speed: float
     *                                     - pitch: float
     *                                     - speakers: array (dialogue mode)
     *                                     - fresh_take: bool (never answer from the
     *                                       saved copy of an identical earlier take)
     * @param  Plan|null  $plan           User's active plan
     * @param  int|null   $userId         User ID for telemetry
     * @return array
     */
    public function synthesizeAdvanced(
        string $text,
        string $targetLanguage,
        array $options = [],
        ?Plan $plan = null,
        ?int $userId = null,
    ): array {
        $text = trim($text);
        if ($text === '') {
            throw new \RuntimeException('TTS text is empty.');
        }

        // If routing service not available, fall back to legacy
        if (!$this->routingService) {
            return $this->synthesize(
                $text,
                $targetLanguage,
                $options['voice_name'] ?? null,
                $options['tier'] ?? 'standard',
            );
        }

        $mode = $this->routingService->getGlobalMode();
        $provider = $this->routingService->resolveProvider($mode, $plan, $options);

        // Build voice config using Arabic mapper if applicable
        $isArabic = in_array(strtolower($targetLanguage), ['ar', 'ar-xa']);
        $voiceConfig = $options;

        if (
            $isArabic
            && $this->arabicMapper
            && (isset($options['dialect']) || isset($options['emotion']) || isset($options['performance_style']) || isset($options['style']))
        ) {
            $voiceConfig = $this->arabicMapper->mapToProviderConfig($provider->name(), $options);
            // Preserve speakers for dialogue mode
            if (isset($options['speakers'])) {
                $voiceConfig['speakers'] = $options['speakers'];
            }
            // Preserve provider and tier hints so the routing service respects them
            if (isset($options['provider'])) {
                $voiceConfig['provider'] = $options['provider'];
            }
            if (isset($options['tier'])) {
                $voiceConfig['tier'] = $options['tier'];
            }
        }

        // The Arabic mapper returns a provider voice config and does not carry
        // the caller's custom prompt. Put it back so demo, dashboard, and jobs
        // still send it to Gemini.
        if (array_key_exists('style_prompt', $options)) {
            $voiceConfig['style_prompt'] = $options['style_prompt'];
        }

        $studio = $this->normalizeStudioStyleOptions($voiceConfig);
        $voiceConfig['performance_style'] = $studio['performance_style'];
        $voiceConfig['style'] = $studio['performance_style']; // Backward compatibility for providers expecting `style`.
        $voiceConfig['emotion'] = $studio['emotion'];

        // Check cache. A member making the same text again wants another reading
        // (and pays for it), so a fresh take is never answered from the saved copy.
        $cacheKey = $this->buildAdvancedCacheKey($text, $targetLanguage, $voiceConfig, $provider->name());
        if (empty($options['fresh_take']) && ($cached = $this->findCachedAudio($cacheKey, $targetLanguage))) {
            return [
                'audio_path' => $cached,
                'duration_seconds' => $this->estimateDurationSeconds($text),
                'provider' => 'cache',
                'model' => $provider->name() === 'gemini' ? GeminiTtsModel::resolve() : null,
                'latency_ms' => 0,
                'char_count' => mb_strlen($text),
                'cost_estimate_usd' => 0.0,
                'fallback_used' => false,
                'routing_mode' => $mode,
                'plan_slug' => $plan?->slug ?? 'free',
            ];
        }

        // Synthesize using routing service (an HD request that Gemini cannot make
        // throws here, so a failure is never cached).
        $result = $this->routingService->synthesize($text, $targetLanguage, $voiceConfig, $plan, $userId);

        $this->storeInCache($result['audio_path'], $cacheKey, $targetLanguage);

        return $result;
    }

    /**
     * Build cache key for advanced synthesis.
     */
    private function buildAdvancedCacheKey(string $text, string $lang, array $config, string $provider): string
    {
        $parts = [
            $provider,
            $lang,
            $config['voice_name'] ?? '',
            $config['performance_style'] ?? ($config['style'] ?? ''),
            $config['emotion'] ?? '',
            $config['dialect'] ?? '',
            (string) ($config['speed'] ?? '1.0'),
            (string) ($config['pitch'] ?? '0.0'),
            $text,
        ];

        // Extra parts are appended only when they change the audio. Leaving
        // them off for the default Gemini 2.5 model keeps the existing cache
        // valid, so a deploy does not regenerate every saved clip.
        $stylePrompt = trim((string) ($config['style_prompt'] ?? ''));
        if ($stylePrompt !== '') {
            $parts[] = 'prompt:'.$stylePrompt;
            // The member's own direction is now followed by the dialect and speed they picked.
            if ($provider === 'gemini') {
                $parts[] = 'prompt:with-choices';
            }
        }

        $speakers = $config['speakers'] ?? null;
        if (is_array($speakers) && $speakers !== []) {
            $encoded = json_encode($this->speakerCacheFingerprint($speakers), JSON_UNESCAPED_UNICODE);
            if (is_string($encoded) && $encoded !== '' && $encoded !== '[]') {
                $parts[] = 'speakers:'.$encoded;
            }
            // An Arabic dialogue in a dialect now has its lines rewritten into that dialect,
            // so recordings made before that are not reused.
            if ($provider === 'gemini' && in_array(strtolower($lang), ['ar', 'ar-xa'], true)
                && (trim((string) ($config['dialect'] ?? '')) !== '' || (is_string($encoded) && str_contains($encoded, '"dialect"')))) {
                $parts[] = 'script:dialect';
            }

            // A mood or delivery tag in a dialogue now directs its own turn, not every line.
            if ($provider === 'gemini' && \App\Support\GeminiVocalTags::prepare($text)['cues'] !== []) {
                $parts[] = 'cues:own-turn';
            }
        }

        // Chirp 3 HD standard voices sound different from WaveNet ones, so they
        // get their own cache entries; WaveNet keys stay as they were.
        if ($provider === 'google_cloud'
            && VoiceTier::normalize((string) ($config['tier'] ?? VoiceTier::STANDARD)) === VoiceTier::STANDARD
            && StandardVoiceEngine::usesChirp()
        ) {
            $parts[] = 'standard:'.StandardVoiceEngine::CHIRP3_HD;
        }

        if ($provider === 'gemini') {
            // Plain Fusha (for the script or a speaker) is now asked for as formal Arabic.
            $fushaSpeaker = is_array($speakers) && array_filter($speakers, fn ($speaker) => is_array($speaker) && ArabicDialects::isPlainFusha($speaker['dialect'] ?? null)) !== [];
            if (ArabicDialects::isPlainFusha($config['dialect'] ?? null) || $fushaSpeaker) {
                $parts[] = 'fusha:formal';
            }
            $model = GeminiTtsModel::resolve();
            if ($model !== GeminiTtsModel::CURRENT) {
                $parts[] = 'model:'.$model;
            }

            // Every speed and pitch step but 1.0x and 0 now has its own direction
            // (0.9x, 1.1x and 1.2x used to sound like 1.0x), so older recordings are not reused.
            if (PaceAndPitch::speed((float) ($config['speed'] ?? 1.0)) !== '' || PaceAndPitch::pitch((float) ($config['pitch'] ?? 0.0)) !== '') {
                $parts[] = 'pace:graded';
            }
        }

        return sha1(implode('|', $parts));
    }

    /**
     * @param  array<int, mixed>  $speakers
     * @return array<int, array{name:string,voice:string,dialect?:string}>
     */
    private function speakerCacheFingerprint(array $speakers): array
    {
        $fingerprint = [];
        foreach ($speakers as $speaker) {
            if (! is_array($speaker)) {
                continue;
            }

            $entry = [
                'name' => trim((string) ($speaker['name'] ?? $speaker['id'] ?? '')),
                'voice' => trim((string) ($speaker['voice_name'] ?? $speaker['voice'] ?? '')),
            ];
            // A speaker's own dialect changes the recording, so it is part of the key.
            $dialect = trim((string) ($speaker['dialect'] ?? ''));
            if ($dialect !== '') {
                $entry['dialect'] = $dialect;
            }
            $fingerprint[] = $entry;
        }

        return $fingerprint;
    }

    /**
     * @param  array<string, mixed>  $voiceConfig
     * @return array{performance_style:string,emotion:string}
     */
    private function normalizeStudioStyleOptions(array $voiceConfig): array
    {
        $performanceStyle = trim((string) ($voiceConfig['performance_style'] ?? $voiceConfig['style'] ?? ''));
        $emotion = trim((string) ($voiceConfig['emotion'] ?? ''));

        // Backward compatibility for old payloads that used emotion for style.
        if (
            $performanceStyle === ''
            && in_array($emotion, TtsStudioConfig::performanceStyleValues(), true)
            && ! in_array($emotion, TtsStudioConfig::emotionValues(), true)
        ) {
            $performanceStyle = $emotion;
            $emotion = 'neutral';
        }

        if ($performanceStyle === '') {
            $performanceStyle = 'neutral';
        }

        if ($emotion === '') {
            $emotion = 'neutral';
        }

        return [
            'performance_style' => $performanceStyle,
            'emotion' => $emotion,
        ];
    }

    private function synthesizeWithGoogleCloud(string $text, string $targetLanguage, string $voice): array
    {
        $endpoint = (string) config('services.google_tts.endpoint');
        $timeout = (int) config('services.google_tts.timeout_seconds', 30);
        $verifySsl = (bool) config('services.google_tts.verify_ssl', true);

        $payload = [
            'input' => [
                'text' => mb_substr($text, 0, 5000),
            ],
            'voice' => [
                'languageCode' => $targetLanguage === 'ar' ? 'ar-XA' : 'en-US',
                'name' => $voice,
                'ssmlGender' => $this->resolveSsmlGender($voice),
            ],
            'audioConfig' => [
                // This older path stores what the engine sends as .mp3, so it asks for MP3.
                'audioEncoding' => 'MP3',
                'speakingRate' => (float) config('services.google_tts.speaking_rate', 1.0),
                'pitch' => (float) config('services.google_tts.pitch', 0.0),
            ],
        ];

        $apiKey = trim((string) config('services.google_tts.api_key', ''));
        if ($apiKey !== '') {
            $response = $this->sendWithSslFallback(
                fn (bool $verify) => Http::timeout($timeout)
                    ->withOptions(['verify' => $verify])
                    ->withHeaders(['X-Goog-Api-Key' => $apiKey])
                    ->post($endpoint, $payload),
                $verifySsl,
                'Speech API request',
            );
        } else {
            $response = $this->sendWithSslFallback(
                fn (bool $verify) => Http::timeout($timeout)
                    ->withOptions(['verify' => $verify])
                    ->withToken($this->getGoogleAccessToken())
                    ->post($endpoint, $payload),
                $verifySsl,
                'Speech OAuth request',
            );
        }

        if (! $response->ok()) {
            throw new \RuntimeException('Speech request failed: '.$response->body());
        }

        $json = $response->json();
        $audioContent = is_array($json) ? ($json['audioContent'] ?? null) : null;
        if (! is_string($audioContent) || $audioContent === '') {
            throw new \RuntimeException('Speech service returned empty audio payload.');
        }

        $binary = base64_decode($audioContent, true);
        if ($binary === false || $binary === '') {
            throw new \RuntimeException('Failed to decode speech audio payload.');
        }

        $filename = 'audio/'.now()->format('Ymd_His').'_'.Str::random(12).'.mp3';
        $disk = Storage::disk('public');

        if (! $disk->exists('audio')) {
            $disk->makeDirectory('audio');
        }

        // The public disk reports a failed write instead of throwing: without this
        // the generation would point at a file that is not there.
        if (! $disk->put($filename, $binary)) {
            throw new \RuntimeException('The voice file could not be saved.');
        }

        return [
            'audio_path' => $filename,
            'duration_seconds' => $this->estimateDurationSeconds($text),
            'provider' => 'tts-engine',
        ];
    }

    private function getGoogleAccessToken(): string
    {
        $credentials = $this->loadGoogleCredentials();
        $clientEmail = (string) ($credentials['client_email'] ?? '');
        $privateKey = (string) ($credentials['private_key'] ?? '');

        if ($clientEmail === '' || $privateKey === '') {
            throw new \RuntimeException('Missing speech service credentials.');
        }

        $cacheKey = 'listen:google_tts_token:'.sha1($clientEmail);

        return Cache::remember($cacheKey, now()->addMinutes(50), function () use ($clientEmail, $privateKey) {
            $tokenUri = (string) config('services.google_tts.token_uri', 'https://oauth2.googleapis.com/token');
            $verifySsl = (bool) config('services.google_tts.verify_ssl', true);
            $now = time();

            $header = $this->base64UrlEncode(json_encode([
                'alg' => 'RS256',
                'typ' => 'JWT',
            ], JSON_THROW_ON_ERROR));

            $claims = $this->base64UrlEncode(json_encode([
                'iss' => $clientEmail,
                'scope' => 'https://www.googleapis.com/auth/cloud-platform',
                'aud' => $tokenUri,
                'exp' => $now + 3600,
                'iat' => $now,
            ], JSON_THROW_ON_ERROR));

            $unsigned = $header.'.'.$claims;
            $signature = '';
            $ok = openssl_sign($unsigned, $signature, $privateKey, OPENSSL_ALGO_SHA256);
            if (! $ok) {
                throw new \RuntimeException('Unable to sign OAuth JWT assertion.');
            }

            $assertion = $unsigned.'.'.$this->base64UrlEncode($signature);

            $response = $this->sendWithSslFallback(
                fn (bool $verify) => Http::asForm()
                    ->timeout(15)
                    ->withOptions(['verify' => $verify])
                    ->post($tokenUri, [
                        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                        'assertion' => $assertion,
                    ]),
                $verifySsl,
                'OAuth token request',
            );

            if (! $response->ok()) {
                throw new \RuntimeException('Failed fetching OAuth token: '.$response->body());
            }

            $token = $response->json('access_token');
            if (! is_string($token) || $token === '') {
                throw new \RuntimeException('OAuth token response missing access_token.');
            }

            return $token;
        });
    }

    private function loadGoogleCredentials(): array
    {
        $json = trim((string) config('services.google_tts.credentials_json', ''));
        if ($json !== '') {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $path = trim((string) config('services.google_tts.credentials_path', ''));
        if ($path !== '' && is_file($path)) {
            $contents = file_get_contents($path);
            $decoded = is_string($contents) ? json_decode($contents, true) : null;
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        throw new \RuntimeException('Speech service credentials are not configured.');
    }

    private function resolveVoiceName(string $targetLanguage, ?string $voiceName, string $tier): string
    {
        $voiceName = trim((string) $voiceName);

        if (
            $voiceName !== ''
            && VoiceCatalog::isAllowedForLanguage($voiceName, $targetLanguage, $tier)
        ) {
            return $voiceName;
        }

        $defaultVoice = VoiceCatalog::defaultForLanguage($targetLanguage, $tier);
        if ($defaultVoice === '') {
            throw new \RuntimeException('No voice is available for the selected language and voice quality.');
        }

        return $defaultVoice;
    }

    private function resolveSsmlGender(string $voiceName): string
    {
        $meta = VoiceCatalog::voiceMeta($voiceName);
        $gender = strtolower((string) ($meta['gender'] ?? ''));

        return match ($gender) {
            'male' => 'MALE',
            'female' => 'FEMALE',
            default => 'NEUTRAL',
        };
    }

    /**
     * Legacy path always uses Google Cloud. If the selected voice belongs to
     * another provider (Gemini), map to a valid Google voice automatically.
     */
    private function normalizeLegacyGoogleVoice(string $voiceName, string $targetLanguage, string $tier): string
    {
        $voiceName = trim($voiceName);
        if ($voiceName === '') {
            return '';
        }

        $provider = VoiceCatalog::providerForName($voiceName);
        if ($provider === null) {
            return $voiceName;
        }

        if ($provider === 'google_cloud') {
            return VoiceCatalog::providerVoiceIdForName($voiceName) ?: $voiceName;
        }

        $baseLanguage = str_starts_with(strtolower($targetLanguage), 'ar') ? 'ar'
            : (str_starts_with(strtolower($targetLanguage), 'en') ? 'en' : strtolower($targetLanguage));

        $googleVoice = VoiceCatalog::defaultForLanguageByProvider($baseLanguage, 'google_cloud', $tier);
        if ($googleVoice !== '') {
            return VoiceCatalog::providerVoiceIdForName($googleVoice) ?: $googleVoice;
        }

        return $voiceName;
    }

    private function findCachedAudio(string $hash, string $targetLanguage): ?string
    {
        $disk = Storage::disk('public');
        $extensions = ['mp3', 'wav', 'ogg'];

        foreach ($extensions as $extension) {
            $path = "audio/cache/{$targetLanguage}_{$hash}.{$extension}";
            // An empty file (a copy cut short) is not a saved voice: it is made again.
            if ($disk->exists($path) && $disk->size($path) > 0) {
                return $path;
            }
        }

        return null;
    }

    private function storeInCache(string $sourcePath, string $hash, string $targetLanguage): string
    {
        $disk = Storage::disk('public');
        $extension = strtolower((string) pathinfo($sourcePath, PATHINFO_EXTENSION));
        if ($extension === '') {
            $extension = 'mp3';
        }

        $cachePath = "audio/cache/{$targetLanguage}_{$hash}.{$extension}";

        if (! $disk->exists($cachePath) || $disk->size($cachePath) === 0) {
            $dir = dirname($disk->path($cachePath));
            if (! is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            // The cache is only a shortcut: when the copy fails, the voice is used
            // from where it was saved, and a copy cut short is not kept for later.
            if (! $disk->copy($sourcePath, $cachePath)) {
                $disk->delete($cachePath);

                return $sourcePath;
            }
        }

        return $cachePath;
    }

    private function estimateDurationSeconds(string $text): int
    {
        $wordEstimate = max(
            str_word_count(strip_tags($text)),
            (int) ceil(mb_strlen($text) / 5)
        );

        return max(3, (int) ceil($wordEstimate / 2.6));
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function sendWithSslFallback(callable $sender, bool $verifySsl, string $context)
    {
        try {
            return $sender($verifySsl);
        } catch (\Throwable $e) {
            if (! $this->shouldRetryWithoutSsl($e, $verifySsl)) {
                throw $e;
            }

            Log::warning($context.' failed SSL verification on local environment; retrying once without verification.');

            return $sender(false);
        }
    }

    private function shouldRetryWithoutSsl(\Throwable $e, bool $verifySsl): bool
    {
        if (! $verifySsl || ! app()->environment('local')) {
            return false;
        }

        $message = strtolower($e->getMessage());

        return str_contains($message, 'curl error 60')
            || str_contains($message, 'ssl certificate problem')
            || str_contains($message, 'unable to get local issuer certificate');
    }
}
