<?php

namespace App\Services;

use App\Models\ClonedVoice;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\Tts\GeminiKeyManager;
use App\Support\VoiceCloningAccess;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Voice cloning through Gemini 3.8 Flash TTS voice replication.
 *
 * Flow:
 * 1. POST /v1beta/voices with a reference clip and a separate consent clip.
 * 2. Store the returned voice_... id.
 * 3. Synthesize later by passing that id to Gemini 3.8 Flash TTS.
 */
class VoiceCloningService
{
    public const MODEL = 'gemini-3.8-flash-tts';

    /**
     * Text models that can rewrite a script into another dialect.
     * The speech model reads the script word for word, so the accent
     * has to be in the words as well as in the style direction.
     *
     * @var array<int, string>
     */
    private const TRANSCRIPT_MODELS = [
        'gemini-3.5-flash',
        'gemini-3.6-flash',
    ];

    /**
     * Consent lines the speaker records.
     * English stays the official sentence. Arabic writes Google as جوجل.
     */
    public const CONSENT_SCRIPTS = [
        'en' => 'I am the owner of this voice and I consent to Google using this voice to create a synthetic voice model.',
        'en-US' => 'I am the owner of this voice and I consent to Google using this voice to create a synthetic voice model.',
        'en-GB' => 'I am the owner of this voice and I consent to Google using this voice to create a synthetic voice model.',
        'en-AU' => 'I am the owner of this voice and I consent to Google using this voice to create a synthetic voice model.',
        'ar' => 'أنا مالك هذا الصوت وأوافق على أن تستخدم جوجل هذا الصوت لإنشاء نموذج صوتي اصطناعي.',
        'ar-XA' => 'أنا مالك هذا الصوت وأوافق على أن تستخدم جوجل هذا الصوت لإنشاء نموذج صوتي اصطناعي.',
    ];

    /**
     * @return array{success: bool, voice_cloning_key?: string, error?: string, error_code?: string, http_status?: int|null, response_body?: string}
     */
    public function generateVoiceCloningKey(ClonedVoice $clonedVoice): array
    {
        try {
            if (! filled($clonedVoice->consent_audio_path)) {
                return $this->failure('A separate consent recording is required.', 'consent');
            }
            if ($this->apiKeys() === []) {
                return $this->failure('No Gemini API key is configured (GEMINI_TTS_API_KEY).', 'key');
            }

            $reference = $this->prepareAudio($clonedVoice->source_audio_path);
            $consent = $this->prepareAudio($clonedVoice->consent_audio_path);
            $languageCode = $clonedVoice->language_code ?: 'ar-XA';

            // A unique tag lets us find the voice again if Gemini created it
            // but failed to send the reply (HTTP 500 "Error translating server response to JSON").
            $displayName = Str::limit((string) $clonedVoice->name, 50, '')
                .' · L'.$clonedVoice->id.'-'.Str::lower(Str::random(5));

            $voice = [
                'model' => self::MODEL,
                'type' => 'replicated',
                'display_name' => $displayName,
                'replicated' => [
                    'source_audio' => [
                        'mime_type' => $reference['mime'],
                        'data' => base64_encode($reference['bytes']),
                    ],
                    'consent_audio' => [
                        'mime_type' => $consent['mime'],
                        'data' => base64_encode($consent['bytes']),
                    ],
                ],
            ];

            $attempts = [
                ['path' => '/voices', 'payload' => ['store' => true, 'voice' => $voice]],
                ['path' => '/voices', 'payload' => ['store' => true, 'voice' => $voice]],
                ['path' => '/voices?store=true', 'payload' => $voice],
            ];

            $response = null;
            foreach ($attempts as $index => $attempt) {
                $response = $this->request('POST', $attempt['path'], $attempt['payload'], 90);
                $voiceId = $this->extractVoiceId($response);

                if ($response->successful() && $voiceId !== null) {
                    return ['success' => true, 'voice_cloning_key' => $voiceId];
                }

                $serverSide = $response->serverError() || $response->successful();
                if (! $serverSide || self::isConsentRejection((string) $response->body())) {
                    break;
                }

                Log::warning('Gemini voice replication returned no voice id; looking it up.', [
                    'cloned_voice_id' => $clonedVoice->id,
                    'attempt' => $index + 1,
                    'status' => $response->status(),
                    'body' => mb_substr((string) $response->body(), 0, 1000),
                ]);

                $recovered = $this->findStoredVoiceByDisplayName($displayName);
                if ($recovered !== null) {
                    return ['success' => true, 'voice_cloning_key' => $recovered];
                }

                if ($index < count($attempts) - 1) {
                    $this->pause(1500);
                }
            }

            $errorMessage = $response->successful()
                ? 'Gemini accepted the request but returned no voice id.'
                : (self::consentRejectionMessage((string) $response->body()) ?? $this->responseError($response));
            $body = mb_substr((string) $response->body(), 0, 2000);
            Log::error('Gemini voice replication failed.', [
                'cloned_voice_id' => $clonedVoice->id,
                'status' => $response->status(),
                'error' => $errorMessage,
                'body' => $body,
                'language_code' => $languageCode,
                'source_mime' => $reference['mime'],
                'source_bytes' => strlen($reference['bytes']),
                'consent_mime' => $consent['mime'],
                'consent_bytes' => strlen($consent['bytes']),
            ]);

            return $this->failure(
                $errorMessage,
                $this->classifyError($errorMessage.' '.$body, $response->status()),
                $response->status(),
                $body,
            );
        } catch (\Throwable $e) {
            Log::error('Gemini voice replication exception.', [
                'cloned_voice_id' => $clonedVoice->id,
                'exception' => get_class($e).': '.$e->getMessage(),
            ]);

            $message = $e->getMessage();
            $code = str_contains(strtolower($message), 'audio file not found') ? 'audio' : 'network';

            return $this->failure($message, $code);
        }
    }

    /**
     * @return array{audio_content: string, audio_path: string, provider: string, model: string, latency_ms: int, cost_estimate_usd: float, char_count: int, duration_seconds: null}
     */
    public function synthesizeWithClonedVoice(
        string $text,
        string $languageCode,
        string $voiceCloningKey,
        string $style = '',
    ): array {
        $text = trim(mb_substr($text, 0, 5000));
        if ($text === '') {
            throw new \RuntimeException('TTS text is empty.');
        }

        $startTime = microtime(true);
        $style = trim($style);
        $response = $this->request('POST', '/interactions', $this->interactionPayload($text, $voiceCloningKey, $style), 90);

        if (! $response->successful() && $this->shouldFallbackToGenerateContent($response)) {
            $response = $this->request('POST', '/models/'.self::MODEL.':generateContent', $this->generateContentPayload($text, $voiceCloningKey, $style), 90);
        }

        if (! $response->successful()) {
            $errorMsg = $this->responseError($response);
            throw new \RuntimeException('Cloned voice synthesis failed: '.$errorMsg);
        }

        $audioContent = $this->extractAudioBytes($response->json() ?? []);
        if ($audioContent === '') {
            throw new \RuntimeException('Cloned voice synthesis returned no audio.');
        }

        $audioContent = $this->ensurePlayableAudio($audioContent);
        $extension = str_starts_with($audioContent, 'RIFF') ? 'wav' : 'mp3';
        $filename = 'audio/cloned/'.now()->format('Ymd').'/'.Str::uuid().'.'.$extension;
        Storage::disk('public')->put($filename, $audioContent);

        return [
            'audio_content' => $audioContent,
            'audio_path' => $filename,
            'provider' => 'gemini',
            'model' => self::MODEL,
            'latency_ms' => (int) ((microtime(true) - $startTime) * 1000),
            'cost_estimate_usd' => $this->estimateCost(mb_strlen($text)),
            'char_count' => mb_strlen($text),
            'duration_seconds' => null,
        ];
    }

    /**
     * The speech model reads the script word for word and copies the recording accent.
     * A chosen dialect is written into the script first, then spoken in that accent.
     * No dialect leaves the script unchanged.
     */
    public function speakableText(string $text, ?string $dialect): string
    {
        $text = trim($text);
        $instruction = $this->clonedAccentInstruction($dialect);
        if ($text === '' || $instruction === '') {
            return $text;
        }

        $cacheKey = 'listen:clone-dialect:v1:'.sha1($instruction.'|'.$text);
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $rewritten = $this->rewriteTranscript($text, $instruction);
        if ($rewritten === null || $rewritten === '') {
            return $text;
        }

        Cache::put($cacheKey, $rewritten, now()->addDays(7));

        return $rewritten;
    }

    /**
     * Direction for one generation. The stored voice already carries the person.
     * A selected accent has to be a short style line, or the recording accent is copied.
     */
    public function deliveryStyle(
        ?string $dialect,
        string $performanceStyle = 'neutral',
        string $emotion = 'neutral',
        ?string $customPrompt = null,
        float $speed = 1.0,
        float $pitch = 0.0,
    ): string {
        $parts = [];
        $accent = $this->clonedAccentInstruction($dialect);
        if ($accent !== '') {
            $parts[] = $accent;
        }

        $custom = trim((string) $customPrompt);
        if ($custom !== '') {
            $parts[] = $custom;
        }

        $performance = strtolower(trim($performanceStyle));
        if ($performance !== '' && $performance !== 'neutral') {
            $parts[] = 'Performance style: '.str_replace(['_', '-'], ' ', $performance).'.';
        }

        $emotion = strtolower(trim($emotion));
        if ($emotion !== '' && $emotion !== 'neutral') {
            $parts[] = 'Emotional tone: '.str_replace(['_', '-'], ' ', $emotion).'.';
        }

        if ($speed > 0 && abs($speed - 1) >= 0.15) {
            $parts[] = $speed < 1 ? 'Speak a little more slowly.' : 'Speak a little more quickly.';
        }

        if ($pitch <= -3) {
            $parts[] = 'Use a deeper, lower-pitched voice.';
        } elseif ($pitch >= 3) {
            $parts[] = 'Use a higher-pitched, brighter voice.';
        }

        return trim(implode(' ', $parts));
    }

    private function clonedAccentInstruction(?string $dialect): string
    {
        $code = strtoupper(str_replace('_', '-', trim((string) $dialect)));
        if ($code === '') {
            return '';
        }

        return match ($code) {
            'AR-SA' => 'Najdi Saudi colloquial Arabic, the way someone from Riyadh talks to a friend. Pronounce ج as the English j, never as the Egyptian hard g. Saudi Gulf rhythm and Saudi wording.',
            'AR-AE' => 'Emirati Gulf Arabic, the way someone from Dubai talks. Do not use an Egyptian accent.',
            'AR-KW' => 'Kuwaiti Gulf Arabic. Do not use an Egyptian accent.',
            'AR-QA' => 'Qatari Gulf Arabic. Do not use an Egyptian accent.',
            'AR-BH' => 'Bahraini Gulf Arabic. Do not use an Egyptian accent.',
            'AR-OM' => 'Omani Arabic. Do not use an Egyptian accent.',
            'AR-YE' => 'Yemeni Arabic. Do not use an Egyptian accent.',
            'AR-EG' => 'Cairene Egyptian colloquial Arabic. Pronounce ج like the English g, with Cairo rhythm.',
            'AR-LB' => 'Lebanese Levantine Arabic. Do not use an Egyptian accent.',
            'AR-SY' => 'Syrian Levantine Arabic. Do not use an Egyptian accent.',
            'AR-JO' => 'Jordanian Levantine Arabic, the way someone from Amman talks. Do not use an Egyptian accent.',
            'AR-PS' => 'Palestinian Levantine Arabic. Do not use an Egyptian accent.',
            'AR-IQ' => 'Iraqi Arabic. Do not use an Egyptian accent.',
            'AR-MA' => 'Moroccan Arabic. Do not use an Egyptian accent or Modern Standard Arabic.',
            'AR-DZ' => 'Algerian Arabic. Do not use an Egyptian accent.',
            'AR-TN' => 'Tunisian Arabic. Do not use an Egyptian accent.',
            'AR-LY' => 'Libyan Arabic. Do not use an Egyptian accent.',
            'AR-SD' => 'Sudanese Arabic. Do not use an Egyptian accent.',
            'AR-XA', 'AR' => 'Modern Standard Arabic (Fusha) with clear formal pronunciation, not the colloquial accent of the recording.',
            'EN-US' => 'General American English accent.',
            'EN-GB' => 'British English accent.',
            'EN-AU' => 'Australian English accent.',
            'EN-IN' => 'Indian English accent.',
            'EN-ZA' => 'South African English accent.',
            default => 'Speak in this accent: '.$code.'.',
        };
    }

    private function rewriteTranscript(string $text, string $instruction): ?string
    {
        $prompt = "Rewrite this transcript so it is spoken in this variety:\n"
            .$instruction."\n\n"
            ."Rules:\n"
            ."- Keep the same meaning, names, numbers, and sentence order.\n"
            ."- Use the everyday words and grammar of that variety.\n"
            ."- Do not add a title, notes, labels, or quotation marks.\n"
            ."- If it is already in that variety, return it unchanged.\n"
            ."- Return only the spoken transcript.\n\n"
            ."Transcript:\n".$text;

        foreach (self::TRANSCRIPT_MODELS as $model) {
            try {
                $response = $this->request('POST', '/models/'.$model.':generateContent', [
                    'contents' => [[
                        'role' => 'user',
                        'parts' => [['text' => $prompt]],
                    ]],
                    'generationConfig' => [
                        'temperature' => 0.2,
                    ],
                ], 15);
            } catch (\Throwable $e) {
                Log::warning('Cloned-voice dialect rewrite failed.', [
                    'model' => $model,
                    'error' => $e->getMessage(),
                ]);

                return null;
            }

            if ($response->successful()) {
                $raw = data_get($response->json(), 'candidates.0.content.parts.0.text');

                return is_string($raw) ? $this->cleanSpokenTranscript($raw, $text) : $text;
            }

            if (! $this->transcriptModelMissing($response)) {
                Log::warning('Cloned-voice dialect rewrite was rejected.', [
                    'model' => $model,
                    'status' => $response->status(),
                ]);

                return null;
            }
        }

        return null;
    }

    private function transcriptModelMissing(Response $response): bool
    {
        if ($response->status() === 404) {
            return true;
        }

        if ($response->status() !== 400) {
            return false;
        }

        $message = strtolower($this->responseError($response));

        return str_contains($message, 'not found')
            || str_contains($message, 'no longer available')
            || str_contains($message, 'not supported');
    }

    private function cleanSpokenTranscript(string $raw, string $original): string
    {
        $text = trim($raw);
        $text = preg_replace('/^```[a-z]*\s*/i', '', $text) ?? $text;
        $text = preg_replace('/\s*```$/', '', $text) ?? $text;
        $text = preg_replace('/^(spoken transcript|transcript)\s*:\s*/i', '', trim($text)) ?? $text;
        $text = trim($text, "\"' \n\r\t");

        if ($text === '' || str_contains($text, 'Rewrite this transcript')) {
            return $original;
        }

        if (mb_strlen($text) > max(mb_strlen($original) * 2, mb_strlen($original) + 400)) {
            return $original;
        }

        return $text;
    }

    /**
     * Cheap read-only call that proves the key, network, and region can reach
     * the Voices API before any audio is sent.
     *
     * @return array{ok: bool, status: int|null, body: string}
     */
    public function probeVoicesApi(): array
    {
        try {
            $response = $this->request('GET', '/voices', ['type' => 'replicated', 'pageSize' => 1], 30);

            return [
                'ok' => $response->successful(),
                'status' => $response->status(),
                'body' => mb_substr((string) $response->body(), 0, 1500),
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'status' => null, 'body' => get_class($e).': '.$e->getMessage()];
        }
    }

    public function configuredKeyCount(): int
    {
        return count($this->apiKeys());
    }

    /**
     * @return array<int, array{id: string, display_name: string}>
     */
    public function listStoredVoices(int $maxPages = 5): array
    {
        $voices = [];
        $pageToken = null;

        for ($page = 0; $page < $maxPages; $page++) {
            $query = ['type' => 'replicated', 'pageSize' => 100];
            if ($pageToken) {
                $query['pageToken'] = $pageToken;
            }

            $response = $this->request('GET', '/voices', $query, 30);
            if (! $response->successful()) {
                break;
            }

            foreach ((array) $response->json('voices', []) as $voice) {
                if (! is_array($voice)) {
                    continue;
                }
                $id = $this->findVoiceId(['id' => $voice['id'] ?? null, 'name' => $voice['name'] ?? null]);
                if ($id === null) {
                    continue;
                }
                $voices[] = [
                    'id' => $id,
                    'display_name' => (string) ($voice['display_name'] ?? $voice['displayName'] ?? ''),
                ];
            }

            $pageToken = $response->json('nextPageToken') ?? $response->json('next_page_token');
            if (! $pageToken) {
                break;
            }
        }

        return $voices;
    }

    private function pause(int $milliseconds): void
    {
        if ((bool) config('services.gemini_tts.voice_retry_pause', true)) {
            usleep($milliseconds * 1000);
        }
    }

    private function findStoredVoiceByDisplayName(string $displayName): ?string
    {
        try {
            foreach ([0, 2000] as $delay) {
                if ($delay > 0) {
                    $this->pause($delay);
                }
                foreach ($this->listStoredVoices() as $voice) {
                    if ($voice['display_name'] === $displayName) {
                        return $voice['id'];
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Gemini replicated voice lookup failed.', ['exception' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Remove a cloned voice everywhere: the Gemini voice, both recordings, and the row.
     */
    public function purge(ClonedVoice $voice): void
    {
        $this->deleteReplicatedVoice($voice->provider_voice_key);

        foreach ([$voice->source_audio_path, $voice->consent_audio_path] as $path) {
            if (filled($path)) {
                Storage::disk('local')->delete((string) $path);
            }
        }

        $voice->delete();
    }

    public function deleteReplicatedVoice(?string $voiceId): void
    {
        $voiceId = trim((string) $voiceId);
        if ($voiceId === '' || ! str_starts_with($voiceId, 'voice_')) {
            return;
        }

        try {
            $response = $this->request('DELETE', '/voices/'.rawurlencode($voiceId), [], 30);
            if (! $response->successful() && $response->status() !== 404) {
                Log::warning('Gemini replicated voice delete failed.', [
                    'voice_id' => $voiceId,
                    'status' => $response->status(),
                    'error' => $this->responseError($response),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Gemini replicated voice delete exception.', [
                'voice_id' => $voiceId,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    public static function isEnabled(): bool
    {
        return (string) SiteSetting::get('voice_cloning_enabled', '1') === '1';
    }

    public static function maxVoicesPerUser(): int
    {
        return (int) SiteSetting::get('voice_cloning_max_per_user', 5);
    }

    public static function maxVoicesForPlan(?Plan $plan): int
    {
        $siteDefault = max(1, self::maxVoicesPerUser());

        if (! $plan) {
            return $siteDefault;
        }

        $configured = (int) ($plan->voice_cloning_max_voices ?? 0);

        // The column default is 0, which means "use the site-wide limit".
        return $configured > 0 ? $configured : $siteDefault;
    }

    /**
     * Whether this plan includes voice cloning.
     * The global kill switch still has to be on.
     */
    public static function planAllows(?Plan $plan): bool
    {
        return self::isEnabled() && (bool) ($plan?->voice_cloning_enabled ?? false);
    }

    /**
     * Slot count for the private preview account.
     * A plan max of 0 uses the site-wide default. Turning the plan toggle off still returns 0.
     */
    public static function maxVoicesForViewer(?User $user, ?Plan $plan): int
    {
        if (! VoiceCloningAccess::allows($user, $plan) || ! self::planAllows($plan)) {
            return 0;
        }

        return self::maxVoicesForPlan($plan);
    }

    /**
     * Display progress for a cloning job. It only moves forward within a status.
     */
    public static function progressPercent(string $status, int $elapsedSeconds): int
    {
        $elapsed = max(0, $elapsedSeconds);

        return match (strtolower(trim($status))) {
            'pending' => min(36, 12 + intdiv($elapsed, 3)),
            'validating' => min(66, 40 + intdiv($elapsed, 3)),
            'processing' => min(96, 70 + intdiv($elapsed, 3)),
            'ready', 'failed' => 100,
            default => 0,
        };
    }

    public static function elapsedSeconds(mixed $from): int
    {
        if (! $from) {
            return 0;
        }

        return max(0, (int) \Carbon\Carbon::parse($from)->diffInSeconds(now(), true));
    }

    public static function minSampleDuration(): int
    {
        return max(10, (int) SiteSetting::get('voice_cloning_min_duration', 10));
    }

    public static function maxSampleDuration(): int
    {
        return 30;
    }

    public static function minConsentDuration(): int
    {
        return 3;
    }

    public static function maxUploadSizeMb(): int
    {
        return (int) SiteSetting::get('voice_cloning_max_upload_mb', 25);
    }

    public static function allowedMimeTypes(): array
    {
        return [
            'audio/wav',
            'audio/x-wav',
            'audio/wave',
            'audio/mpeg',
            'audio/mp3',
            'audio/mp4',
            'audio/x-m4a',
            'audio/m4a',
            'audio/ogg',
            'audio/webm',
            'audio/flac',
            'video/mp4',
            'application/mp4',
            'audio/3gpp',
            'video/3gpp',
            'audio/3gpp2',
            'video/3gpp2',
            'video/webm',
        ];
    }

    public static function allowedExtensions(): array
    {
        return ['wav', 'mp3', 'mp4', 'm4a', 'ogg', 'webm', 'flac'];
    }

    public static function consentScriptFor(?string $languageCode): string
    {
        $languageCode = trim((string) $languageCode);
        if (isset(self::CONSENT_SCRIPTS[$languageCode])) {
            return self::CONSENT_SCRIPTS[$languageCode];
        }

        $prefix = strtolower(substr($languageCode, 0, 2));

        return self::CONSENT_SCRIPTS[$prefix] ?? self::CONSENT_SCRIPTS['en'];
    }

    /**
     * @return array<string, string>
     */
    public static function consentScriptsByLocale(): array
    {
        return [
            'ar-XA' => self::CONSENT_SCRIPTS['ar-XA'],
            'en-US' => self::CONSENT_SCRIPTS['en-US'],
            'en-GB' => self::CONSENT_SCRIPTS['en-GB'],
            'en-AU' => self::CONSENT_SCRIPTS['en-AU'],
        ];
    }

    public static function publicFailureMessage(ClonedVoice $voice): string
    {
        $code = (string) data_get($voice->metadata, 'failure_code', '');

        return match ($code) {
            'consent' => __('ui.voice_cloning.failure_consent'),
            'duration' => __('ui.voice_cloning.failure_duration', [
                'min' => self::minSampleDuration(),
                'max' => self::maxSampleDuration(),
            ]),
            'audio' => __('ui.voice_cloning.failure_audio'),
            'region' => __('ui.voice_cloning.failure_region'),
            'key' => __('ui.voice_cloning.failure_key'),
            'quota' => __('ui.voice_cloning.failure_quota'),
            'network' => __('ui.voice_cloning.failure_network'),
            'server' => __('ui.voice_cloning.failure_server'),
            'consent_phrase' => __('ui.voice_cloning.failure_consent_phrase'),
            default => __('ui.voice_cloning.processing_failed_generic'),
        };
    }

    /**
     * Raw provider detail for the private preview account only.
     */
    public static function technicalFailureDetail(ClonedVoice $voice): string
    {
        $status = data_get($voice->metadata, 'http_status');
        $error = trim((string) (data_get($voice->metadata, 'provider_error') ?: $voice->failure_reason));
        $raw = trim((string) data_get($voice->metadata, 'provider_response', ''));
        if ($error === '' && $raw === '') {
            return '';
        }

        $detail = ($status ? "HTTP {$status}: " : '').mb_substr($error, 0, 400);
        if ($raw !== '' && ! str_contains($detail, $raw)) {
            $detail .= "\n\n".mb_substr($raw, 0, 1500);
        }

        return $detail;
    }

    /**
     * Studio-facing diagnostic text. Provider names stay out of the product UI.
     */
    public static function studioFailureDetail(ClonedVoice $voice): string
    {
        return self::redactProviderBrand(self::technicalFailureDetail($voice));
    }

    public static function redactProviderBrand(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        $brand = app()->getLocale() === 'ar' ? 'منصة إسمع' : 'Listen Studio';
        $text = preg_replace('#https?://\S+#i', '', $text) ?? $text;
        $text = str_ireplace(['googleapis', 'GEMINI_', 'gemini-', 'google.'], '', $text);
        $text = preg_replace('/\b(gemini|google)\b/i', $brand, $text) ?? $text;
        $text = preg_replace("/[ \t]{2,}/", ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * Finished clones keep their plan slot. Anything that has not
     * succeeded can be removed by the owner.
     */
    public static function canUserDelete(ClonedVoice $voice): bool
    {
        return $voice->status !== 'ready';
    }

    /**
     * Whether a failed voice can be sent again without recording.
     * Only browser WAV uploads qualify; older WebM files need ffmpeg.
     */
    public static function canRetry(ClonedVoice $voice): bool
    {
        return $voice->isFailed()
            && ! self::needsNewConsent($voice)
            && filled($voice->source_audio_path)
            && filled($voice->consent_audio_path)
            && str_ends_with(strtolower((string) $voice->source_audio_path), '.wav')
            && str_ends_with(strtolower((string) $voice->consent_audio_path), '.wav');
    }

    public static function needsNewConsent(ClonedVoice $voice): bool
    {
        return in_array((string) data_get($voice->metadata, 'failure_code'), ['consent', 'consent_phrase'], true);
    }

    /**
     * A consent rejection keeps the voice sample, so only the sentence needs
     * recording again.
     */
    public static function canReplaceConsent(ClonedVoice $voice): bool
    {
        return $voice->isFailed()
            && self::needsNewConsent($voice)
            && filled($voice->source_audio_path)
            && str_ends_with(strtolower((string) $voice->source_audio_path), '.wav');
    }

    /* ------------------------------------------------------------------ */
    /*  Private helpers                                                    */
    /* ------------------------------------------------------------------ */

    /**
     * @return array{success: false, error: string, error_code: string, http_status: int|null, response_body: string}
     */
    private function failure(string $error, string $code, ?int $status = null, string $body = ''): array
    {
        return [
            'success' => false,
            'error' => $error,
            'error_code' => $code,
            'http_status' => $status,
            'response_body' => $body,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function interactionPayload(string $text, string $voiceId, string $style): array
    {
        $content = [
            'type' => 'text',
            'text' => $text,
        ];
        if ($style !== '') {
            $content['annotations'] = [[
                'type' => 'speech_metadata',
                'style' => $style,
            ]];
        }

        return [
            'model' => self::MODEL,
            'input' => [[
                'type' => 'user_input',
                'content' => [$content],
            ]],
            'response_format' => [
                'type' => 'audio',
                'mime_type' => 'audio/wav',
                'sample_rate' => 24000,
            ],
            'generation_config' => [
                'speech_config' => [
                    ['voice' => $voiceId],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function generateContentPayload(string $text, string $voiceId, string $style): array
    {
        $part = ['text' => $text];
        if ($style !== '') {
            $part['speech_metadata'] = ['style' => $style];
        }

        return [
            'contents' => [[
                'role' => 'user',
                'parts' => [$part],
            ]],
            'generationConfig' => [
                'responseModalities' => ['AUDIO'],
                'speechConfig' => [
                    'voiceConfig' => [
                        'voice' => $voiceId,
                    ],
                ],
            ],
        ];
    }

    private function request(string $method, string $path, array $payload, int $timeout): Response
    {
        $baseUrl = rtrim((string) config('services.gemini_tts.endpoint', 'https://generativelanguage.googleapis.com/v1beta'), '/');
        $url = $baseUrl.$path;
        $verify = (bool) config('services.gemini_tts.verify_ssl', true);
        $keys = $this->apiKeys();

        if ($keys === []) {
            throw new \RuntimeException('Gemini API key is not configured.');
        }

        $options = ['verify' => $verify];
        $proxy = trim((string) config('services.gemini_tts.voice_proxy', ''));
        if ($proxy !== '') {
            $options['proxy'] = $proxy;
        }

        $last = null;
        foreach ($keys as $index => $keyInfo) {
            $pending = Http::timeout($timeout)
                ->connectTimeout(20)
                ->withOptions($options)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'x-goog-api-key' => $keyInfo['key'],
                ]);

            $response = match (strtoupper($method)) {
                'DELETE' => $pending->delete($url),
                'GET' => $pending->get($url, $payload),
                default => $pending->post($url, $payload),
            };

            $last = $response;
            if ($response->status() !== 429 || $index === array_key_last($keys)) {
                return $response;
            }
        }

        if (! $last instanceof Response) {
            throw new \RuntimeException('Gemini voice request failed.');
        }

        return $last;
    }

    /**
     * @return array<int, array{key: string, name: string}>
     */
    private function apiKeys(): array
    {
        $configured = trim((string) config('services.gemini_tts.api_key', ''));
        if ($configured !== '') {
            return [['key' => $configured, 'name' => 'config']];
        }

        try {
            $manager = app(GeminiKeyManager::class);
            $available = $manager->getAvailableKey();
            if (is_array($available) && filled($available['key'] ?? null)) {
                return [$available];
            }

            return $manager->getAllKeys();
        } catch (\Throwable) {
            return [];
        }
    }

    private function extractVoiceId(Response $response): ?string
    {
        $json = $response->json();
        if (! is_array($json)) {
            return null;
        }

        return $this->findVoiceId($json);
    }

    private function findVoiceId(mixed $value): ?string
    {
        if (is_string($value)) {
            $value = trim($value);
            if ($value === '') {
                return null;
            }
            if (str_contains($value, '/')) {
                $value = (string) Str::afterLast($value, '/');
            }

            return (str_starts_with($value, 'voice_') || str_starts_with($value, 'voicekey_'))
                ? $value
                : null;
        }

        if (! is_array($value)) {
            return null;
        }

        foreach (['id', 'voiceId', 'voice_id', 'name', 'key'] as $key) {
            if (! array_key_exists($key, $value)) {
                continue;
            }
            $found = $this->findVoiceId($value[$key]);
            if ($found !== null) {
                return $found;
            }
        }

        foreach ($value as $child) {
            if (! is_array($child)) {
                continue;
            }
            $found = $this->findVoiceId($child);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private function extractAudioBytes(array $json): string
    {
        $inline = data_get($json, 'output_audio.data')
            ?? data_get($json, 'outputAudio.data');
        $decoded = $this->decodeAudio($inline);
        if ($decoded !== '') {
            return $decoded;
        }

        $steps = $json['steps'] ?? [];
        if (is_array($steps)) {
            foreach (array_reverse($steps) as $step) {
                if (! is_array($step) || ($step['type'] ?? '') !== 'model_output') {
                    continue;
                }
                foreach ($step['content'] ?? [] as $part) {
                    if (! is_array($part)) {
                        continue;
                    }
                    if (($part['type'] ?? '') === 'audio') {
                        $decoded = $this->decodeAudio($part['data'] ?? null);
                        if ($decoded !== '') {
                            return $decoded;
                        }
                    }
                }
            }
        }

        foreach (data_get($json, 'candidates.0.content.parts', []) as $part) {
            if (! is_array($part)) {
                continue;
            }
            $decoded = $this->decodeAudio(data_get($part, 'inlineData.data'));
            if ($decoded !== '') {
                return $decoded;
            }
        }

        return '';
    }

    private function decodeAudio(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            return '';
        }

        $decoded = base64_decode($value, true);

        return is_string($decoded) ? $decoded : '';
    }

    private function ensurePlayableAudio(string $audio): string
    {
        if (str_starts_with($audio, 'RIFF') || str_starts_with($audio, 'ID3') || str_starts_with($audio, "\xFF\xFB")) {
            return $audio;
        }

        if ((strlen($audio) % 2) !== 0) {
            $audio = substr($audio, 0, -1);
        }

        $dataLength = strlen($audio);
        $sampleRate = 24000;
        $channels = 1;
        $bitsPerSample = 16;
        $byteRate = $sampleRate * $channels * ($bitsPerSample / 8);
        $blockAlign = $channels * ($bitsPerSample / 8);

        return 'RIFF'
            .pack('V', 36 + $dataLength)
            .'WAVE'
            .'fmt '
            .pack('V', 16)
            .pack('v', 1)
            .pack('v', $channels)
            .pack('V', $sampleRate)
            .pack('V', $byteRate)
            .pack('v', $blockAlign)
            .pack('v', $bitsPerSample)
            .'data'
            .pack('V', $dataLength)
            .$audio;
    }

    /**
     * @return array{bytes: string, mime: string}
     */
    private function prepareAudio(string $path): array
    {
        $bytes = $this->loadAudioBytes($path);
        $converted = $this->convertToWav($bytes, $path);
        if ($converted !== null) {
            return ['bytes' => $converted, 'mime' => 'audio/wav'];
        }

        return [
            'bytes' => $bytes,
            'mime' => $this->mimeForPath($path, $bytes),
        ];
    }

    /**
     * Best-effort resample to 24 kHz mono WAV. Many shared hosts disable
     * exec(), so this must never throw; the browser already uploads WAV.
     */
    private function convertToWav(string $bytes, string $path): ?string
    {
        if (! self::canRunCommands()) {
            return null;
        }

        $ffmpeg = self::ffmpegPath();
        if ($ffmpeg === null) {
            return null;
        }

        $source = null;
        $target = null;

        try {
            $sourceExt = strtolower(pathinfo($path, PATHINFO_EXTENSION) ?: 'bin');
            $tempDir = storage_path('app/voice-cloning-tmp');
            if (! is_dir($tempDir)) {
                @mkdir($tempDir, 0775, true);
            }

            $source = $tempDir.'/'.Str::uuid().'.'.$sourceExt;
            $target = $tempDir.'/'.Str::uuid().'.wav';
            if (@file_put_contents($source, $bytes) === false) {
                return null;
            }

            $command = sprintf(
                '%s -y -i %s -ac 1 -ar 24000 -sample_fmt s16 %s 2>&1',
                escapeshellarg($ffmpeg),
                escapeshellarg($source),
                escapeshellarg($target),
            );
            $output = [];
            $code = 1;
            exec($command, $output, $code);
            $wav = ($code === 0 && is_file($target)) ? file_get_contents($target) : false;

            return is_string($wav) && $wav !== '' ? $wav : null;
        } catch (\Throwable $e) {
            Log::info('Voice cloning audio conversion skipped.', ['reason' => $e->getMessage()]);

            return null;
        } finally {
            if ($source) {
                @unlink($source);
            }
            if ($target) {
                @unlink($target);
            }
        }
    }

    private static function canRunCommands(): bool
    {
        if (! function_exists('exec') || ! function_exists('escapeshellarg')) {
            return false;
        }

        $disabled = array_map('trim', explode(',', strtolower((string) ini_get('disable_functions'))));

        return ! in_array('exec', $disabled, true) && ! in_array('escapeshellarg', $disabled, true);
    }

    private static function ffmpegPath(): ?string
    {
        $configured = trim((string) config('services.gemini_tts.ffmpeg_path', ''));
        $candidates = array_filter([
            $configured,
            '/usr/bin/ffmpeg',
            '/usr/local/bin/ffmpeg',
            '/opt/homebrew/bin/ffmpeg',
            '/opt/bin/ffmpeg',
        ]);

        foreach ($candidates as $candidate) {
            try {
                if (@is_file($candidate) && @is_executable($candidate)) {
                    return $candidate;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    private function loadAudioBytes(string $path): string
    {
        if (Storage::disk('local')->exists($path)) {
            return Storage::disk('local')->get($path);
        }

        if (Storage::disk('public')->exists($path)) {
            return Storage::disk('public')->get($path);
        }

        throw new \RuntimeException("Audio file not found: {$path}");
    }

    private function mimeForPath(string $path, string $bytes = ''): string
    {
        if (str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WAVE') {
            return 'audio/wav';
        }
        if (str_starts_with($bytes, 'fLaC')) {
            return 'audio/flac';
        }
        if (str_starts_with($bytes, 'OggS')) {
            return 'audio/ogg';
        }
        if (str_starts_with($bytes, 'ID3') || str_starts_with($bytes, "\xFF\xFB") || str_starts_with($bytes, "\xFF\xF3")) {
            return 'audio/mpeg';
        }

        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'wav' => 'audio/wav',
            'mp3' => 'audio/mpeg',
            'mp4', 'm4a' => 'audio/mp4',
            'ogg' => 'audio/ogg',
            'webm' => 'audio/webm',
            'flac' => 'audio/flac',
            default => 'application/octet-stream',
        };
    }

    private function responseError(Response $response): string
    {
        $message = $response->json('error.message')
            ?? $response->json('error.status')
            ?? $response->body();

        return mb_substr(trim((string) $message), 0, 500);
    }

    /**
     * Gemini can wrap a consent rejection (really a 400) inside a 500
     * "Error translating server response to JSON" DebugInfo detail.
     */
    public static function isConsentRejection(string $body): bool
    {
        $haystack = strtolower($body);

        return str_contains($haystack, 'consent flow failed')
            || str_contains($haystack, "phrase didn't match")
            || str_contains($haystack, 'phrase didn\\\'t match')
            || str_contains($haystack, 'finish_reason_input_vr');
    }

    public static function consentRejectionMessage(string $body): ?string
    {
        if (! self::isConsentRejection($body)) {
            return null;
        }

        if (preg_match('/The recorded phrase didn.{1,3}t match[^"\\\\\[]*/i', $body, $match)) {
            return 'Consent flow failed: '.trim(str_replace("\\'", "'", $match[0]));
        }

        return 'Consent flow failed: Gemini could not verify the consent sentence.';
    }

    private function classifyError(string $message, int $status): string
    {
        $haystack = strtolower($message);
        if (self::isConsentRejection($message)) {
            return 'consent_phrase';
        }
        if ($status >= 500) {
            return 'server';
        }
        if (str_contains($haystack, 'location') || str_contains($haystack, 'region') || str_contains($haystack, 'country') || str_contains($haystack, 'not available in your')) {
            return 'region';
        }
        if (str_contains($haystack, 'api key') || str_contains($haystack, 'api_key') || $status === 401 || ($status === 403 && str_contains($haystack, 'permission'))) {
            return 'key';
        }
        if ($status === 429 || str_contains($haystack, 'quota') || str_contains($haystack, 'resource_exhausted') || str_contains($haystack, 'rate limit')) {
            return 'quota';
        }
        if (str_contains($haystack, 'consent') || str_contains($haystack, 'speaker') || str_contains($haystack, 'verif')) {
            return 'consent';
        }
        if (str_contains($haystack, 'duration') || str_contains($haystack, 'too long') || str_contains($haystack, 'too short') || str_contains($haystack, 'audio length')) {
            return 'duration';
        }
        if ($status === 400) {
            return 'audio';
        }

        return 'provider';
    }

    private function shouldFallbackToGenerateContent(Response $response): bool
    {
        if (! in_array($response->status(), [404, 400], true)) {
            return false;
        }

        $message = strtolower($this->responseError($response));

        return str_contains($message, 'not found')
            || str_contains($message, 'unknown')
            || str_contains($message, 'interaction');
    }

    private function estimateCost(int $charCount): float
    {
        $ratePerMillion = (float) config('services.gemini_tts.cost_per_million_chars', 0.0);

        return round(($charCount / 1_000_000) * $ratePerMillion, 6);
    }
}
