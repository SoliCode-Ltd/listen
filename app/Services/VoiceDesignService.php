<?php

namespace App\Services;

use App\Models\DesignedVoice;
use App\Models\User;
use App\Services\Tts\GeminiKeyManager;
use App\Support\AudioDuration;
use App\Support\ShareableAudio;
use App\Support\VoiceDesign;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Voice Design through Gemini 3.8 Flash TTS: a voice made from a description.
 *
 * 1. POST /v1beta/voices with type "prompted" and the description as
 *    prompted.input, once for each take, all at once on one key. Each answer
 *    is a stored voice_... id, which lives in that key's project only, with a
 *    short sample of its own.
 * 2. Each take speaks the member's line (POST /interactions with its id), so
 *    the takes are compared saying the same words; a take's own sample stands
 *    in when its line does not come back.
 * 3. The member keeps one: it is named and saved, and the other takes are
 *    deleted there and here. Takes nobody kept are deleted after
 *    DRAFT_MINUTES (voice-design:prune), since each holds one of the 200
 *    voices a project may store.
 *
 * VoiceCloningService does the speaking and holds the site's keys.
 */
class VoiceDesignService
{
    public const MODEL = VoiceCloningService::MODEL;

    /** Unkept takes are deleted after this long. */
    public const DRAFT_MINUTES = 120;

    /** What every take's display name starts with on the engine's side, so a lost one can be found. */
    public const DISPLAY_TAG = 'Listen design';

    /**
     * The server that made a voice (its address's host). Staging shares the
     * speech keys with live and holds a copy of live's members, so a server
     * only ever deletes on the engine the voices it made itself.
     */
    public static function siteTag(): string
    {
        return mb_substr((string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'local'), 0, 60);
    }

    /** Whether this server made the voice, so may delete it on the engine. */
    public static function madeHere(DesignedVoice $voice): bool
    {
        return data_get($voice->metadata, 'site') === self::siteTag();
    }

    public function __construct(private readonly VoiceCloningService $voices) {}

    /**
     * A short idea written out as a full description (age, timbre, accent,
     * pace, character), in the language the idea is written in. Text only:
     * no audio is made. Null when the writer did not answer.
     */
    public function enhance(string $idea, string $language): ?string
    {
        $speaks = $language === 'en'
            ? 'The voice speaks English: name its accent.'
            : 'The voice speaks Arabic: name the Arabic dialect it speaks (or Modern Standard Arabic).';
        $prompt = 'You write voice descriptions for a voice design studio. Rewrite the idea below as one or two vivid sentences that describe a single voice: '
            .'its age, gender, timbre and texture, accent, pace and personality. '.$speaks.' '
            .'Describe the sound only. Never name or imitate a real or famous person, and never quote lines for it to say. '
            .'Write in the same language as the idea. Answer with the description alone, without quotes, in under 400 characters.'
            ."\n\nIdea: ".trim($idea);

        $text = $this->clean((string) $this->voices->generateText($prompt, 20));

        return $text !== '' ? mb_substr($text, 0, VoiceDesign::MAX_DESCRIPTION) : null;
    }

    /**
     * A line the character would say, to hear them by: in the voice's
     * language (and the dialect its description names), at most $maxChars.
     * A plain line of the site's own when the writer did not answer.
     */
    public function previewLine(string $description, string $language, int $maxChars = 200): string
    {
        $maxChars = max(VoiceDesign::MIN_PREVIEW, min(VoiceDesign::MAX_PREVIEW, $maxChars));
        $target = min($maxChars, 180);
        $speaks = $language === 'en'
            ? 'Write it in English.'
            : 'Write it in Arabic letters, in the dialect the description names (Modern Standard Arabic if it names none).';
        $prompt = 'Write one line this character would say aloud, so a listener hears who they are: their attitude, their world, their way of speaking. '
            .$speaks.' Between 60 and '.$target.' characters. No quotes, stage directions, sound tags or names of real people. Answer with the line alone.'
            ."\n\nCharacter: ".trim($description);

        $line = $this->clean((string) $this->voices->generateText($prompt, 15));
        if ($line === '' || mb_strlen($line) > $maxChars || ($language !== 'en' && ! preg_match('/\p{Arabic}/u', $line))) {
            $line = (string) trans('voice_design.fallback_line', [], $language === 'en' ? 'en' : 'ar');
        }

        return mb_substr($line, 0, $maxChars);
    }

    /**
     * Makes the takes of one design and has each speak the line. The takes
     * are drafts of $user's until one is kept; their earlier drafts are
     * cleared first.
     *
     * @param  array{description:string, language:string, gender:?string, line:string, projection:int, pace:int, variety:int}  $input
     * @return array{batch:string, takes:list<DesignedVoice>, spoken:int, error:?string, error_code:?string}
     */
    public function design(User $user, array $input, int $takes): array
    {
        $this->discardDrafts((int) $user->id);
        $batch = (string) Str::uuid();
        $takes = max(1, min(3, $takes));
        $failure = fn (string $error, string $code): array => ['batch' => $batch, 'takes' => [], 'spoken' => 0, 'error' => $error, 'error_code' => $code];

        $keys = $this->keysWithRoom();
        if ($keys === []) {
            return $failure('No Gemini key has room for voice design right now.', 'quota');
        }

        $last = ['error' => 'The voice could not be designed.', 'code' => 'server'];
        foreach ($keys as $key) {
            $created = $this->createTakes($key, $user, $input, $takes, $batch);
            if ($created['voices'] === []) {
                $last = ['error' => $created['error'], 'code' => $created['code']];
                // Another key's project may have room, or a key Google refused.
                if (in_array($created['code'], ['quota', 'key', 'limit', 'network'], true)) {
                    continue;
                }

                break;
            }

            $spoken = $this->speakTakes($key, $created['voices'], $input['line']);
            $rows = [];
            $heard = 0;
            foreach ($created['voices'] as $take => $voice) {
                $audio = $spoken[$take] ?? null;
                if ($audio !== null) {
                    $heard++;
                }
                $audio ??= $voice['sample'];
                $path = $audio !== null ? $this->storeAudio($audio) : null;
                if ($path === null) {
                    // Nothing to hear: the take is no use, and keeps a voice slot.
                    $this->deleteRemote($voice['id'], $key['name']);

                    continue;
                }
                $rows[] = DesignedVoice::query()->create([
                    'user_id' => $user->id,
                    'status' => DesignedVoice::DRAFT,
                    'batch' => $batch,
                    'description' => $input['description'],
                    'engine_prompt' => $voice['prompt'],
                    'language' => $input['language'],
                    'gender' => $input['gender'],
                    'preview_text' => isset($spoken[$take]) ? $input['line'] : null,
                    'preview_audio_path' => $path,
                    'provider_voice_key' => $voice['id'],
                    'key_name' => $key['name'],
                    'take' => $take,
                    'metadata' => [
                        'site' => self::siteTag(),
                        'projection' => $input['projection'],
                        'pace' => $input['pace'],
                        'variety' => $input['variety'],
                        'spoke_line' => isset($spoken[$take]),
                        'seconds' => AudioDuration::ofStoredFile($path),
                    ],
                ]);
            }

            if ($rows === []) {
                return $failure('The takes came back without audio.', 'audio');
            }

            return ['batch' => $batch, 'takes' => $rows, 'spoken' => $heard, 'error' => null, 'error_code' => null];
        }

        return $failure($last['error'], $last['code']);
    }

    /**
     * Keeps one take under the member's name for it; the other takes of its
     * design are deleted.
     */
    public function keep(DesignedVoice $take, string $name): DesignedVoice
    {
        $take->forceFill(['status' => DesignedVoice::SAVED, 'name' => $name, 'kept_at' => now()])->save();

        DesignedVoice::query()->drafts()->where('user_id', $take->user_id)
            ->where('batch', $take->batch)->whereKeyNot($take->id)->get()
            ->each(fn (DesignedVoice $other) => $this->purge($other));

        return $take;
    }

    /** The member's takes still waiting to be kept (but $exceptBatch's), deleted. */
    public function discardDrafts(int $userId, ?string $exceptBatch = null): void
    {
        DesignedVoice::query()->drafts()->where('user_id', $userId)
            ->when($exceptBatch !== null, fn ($q) => $q->where('batch', '!=', $exceptBatch))
            ->get()
            ->each(fn (DesignedVoice $draft) => $this->purge($draft));
    }

    /**
     * Takes nobody kept, older than $minutes, deleted everywhere. Then, on
     * each key, the engine's own list is read for takes this server made but
     * never heard back about (a reply lost on the way): those older than a
     * day and unknown here are deleted too. Takes another server made are
     * left to it (purge() only deletes this server's on the engine).
     *
     * @return array{drafts:int, strays:int}
     */
    public function prune(int $minutes = self::DRAFT_MINUTES): array
    {
        $drafts = 0;
        DesignedVoice::query()->drafts()->where('created_at', '<', now()->subMinutes($minutes))
            ->orderBy('id')->limit(500)->get()
            ->each(function (DesignedVoice $draft) use (&$drafts): void {
                $this->purge($draft);
                $drafts++;
            });

        $strays = 0;
        $known = DesignedVoice::withTrashed()->whereNotNull('provider_voice_key')->pluck('provider_voice_key')->flip();
        foreach ($this->voices->apiKeys(self::MODEL) as $key) {
            foreach ($this->listDesigned($key) as $voice) {
                if (! str_starts_with($voice['display_name'], self::DISPLAY_TAG.' · '.self::siteTag().' · ') || isset($known[$voice['id']])
                    || ! preg_match('/ · (\d{12})$/', $voice['display_name'], $made)
                    || \Carbon\Carbon::createFromFormat('YmdHi', $made[1], 'UTC')->greaterThan(now()->subDay())) {
                    continue;
                }
                $this->deleteRemote($voice['id'], (string) $key['name']);
                $strays++;
            }
        }

        return ['drafts' => $drafts, 'strays' => $strays];
    }

    /**
     * A designed voice removed everywhere: the engine's voice, its take's
     * audio, and the row ($keepRow: soft-deleted, so the member's history
     * still names the voice their earlier audio was made with).
     */
    public function purge(DesignedVoice $voice, bool $keepRow = false): void
    {
        // A voice another server made (live's, in staging's copy of the members) stays on the engine.
        if (self::madeHere($voice)) {
            $this->deleteRemote($voice->provider_voice_key, $voice->key_name);
        }
        if (filled($voice->preview_audio_path)) {
            Storage::disk('public')->delete((string) $voice->preview_audio_path);
        }
        $keepRow ? $voice->delete() : $voice->forceDelete();
    }

    /**
     * A member's saved voice speaking a script, always on the key whose
     * project holds it.
     *
     * @return array<string, mixed> as VoiceCloningService::synthesizeWithClonedVoice()
     */
    public function synthesize(DesignedVoice $voice, string $text, string $style = ''): array
    {
        $audio = $this->voices->synthesizeOnKey($text, (string) $voice->provider_voice_key, $style, $voice->key_name);
        // Speaking keeps the voice alive on the engine's side (it is deleted after a year unused).
        $voice->forceFill(['last_used_at' => now()])->saveQuietly();

        return $audio;
    }

    /**
     * @param  array{key:string, name:string}  $key
     * @param  array{description:string, language:string, gender:?string, line:string, projection:int, pace:int, variety:int}  $input
     * @return array{voices: array<int, array{id:string, sample:?string, prompt:string}>, error:string, code:string}
     */
    private function createTakes(array $key, User $user, array $input, int $takes, string $batch): array
    {
        $prompts = [];
        foreach (range(1, $takes) as $take) {
            $prompts[$take] = VoiceDesign::enginePrompt($input['description'], $input['projection'], $input['pace'], $input['variety'], $take);
        }
        $body = function (int $take, bool $full) use ($input, $prompts, $user, $batch): array {
            $voice = [
                'model' => self::MODEL,
                'type' => 'prompted',
                // Tagged with the server and when it was made, so voice-design:prune finds this server's
                // lost takes only, and can tell one from a take still being made.
                'display_name' => self::DISPLAY_TAG.' · '.self::siteTag().' · U'.$user->id.' · '.substr($batch, 0, 8).' · '.$take.' · '.now()->utc()->format('YmdHi'),
                'prompted' => ['input' => $prompts[$take]],
            ];
            // Optional on the engine's side: left out if it refuses them.
            if ($full) {
                $voice['language_code'] = $input['language'] === 'en' ? 'en-US' : 'ar-XA';
                if (in_array($input['gender'], VoiceDesign::GENDERS, true)) {
                    $voice['gender'] = $input['gender'];
                }
            }

            return ['store' => true, 'voice' => $voice];
        };

        $voices = [];
        $error = 'The voice could not be designed.';
        $code = 'server';
        foreach ([true, false] as $full) {
            $pending = array_values(array_diff(range(1, $takes), array_keys($voices)));
            $responses = $this->pool($key, 90, function (Pool $pool, callable $request) use ($pending, $body, $full): void {
                foreach ($pending as $take) {
                    $request($pool->as((string) $take))->post($this->url('/voices'), $body($take, $full));
                }
            });
            $retryPlain = false;
            foreach ($pending as $take) {
                $response = $responses[(string) $take] ?? null;
                if (! $response instanceof Response) {
                    $error = $response instanceof \Throwable ? $response->getMessage() : 'No reply from the voice service.';
                    $code = 'network';

                    continue;
                }
                $this->noteKeyState($key, $response);
                $id = $response->successful() ? $this->voices->findVoiceId($response->json() ?? []) : null;
                if ($id !== null) {
                    $sample = $this->voices->extractAudioBytes(['output_audio' => $response->json('sample_audio') ?? $response->json('sampleAudio')]);
                    $voices[$take] = ['id' => $id, 'sample' => $sample !== '' ? $this->voices->ensurePlayableAudio($sample) : null, 'prompt' => $prompts[$take]];

                    continue;
                }
                $error = $response->successful() ? 'The voice service answered without a voice id.' : $this->voices->responseError($response);
                $code = $this->classify($response);
                $retryPlain = $retryPlain || ($response->status() === 400 && preg_match('/language|gender|INVALID_ARGUMENT/i', (string) $response->body()));
            }
            if (count($voices) === $takes || ! $retryPlain) {
                break;
            }
        }

        if ($voices === []) {
            Log::warning('Voice design: no take was made.', ['key' => $key['name'], 'code' => $code, 'error' => mb_substr($error, 0, 300)]);
        }
        ksort($voices);

        return ['voices' => $voices, 'error' => $error, 'code' => $code];
    }

    /**
     * Each take speaking the line, all at once on the key that holds them.
     *
     * @param  array{key:string, name:string}  $key
     * @param  array<int, array{id:string, sample:?string, prompt:string}>  $voices
     * @return array<int, string> playable WAV bytes by take
     */
    private function speakTakes(array $key, array $voices, string $line): array
    {
        $responses = $this->pool($key, 90, function (Pool $pool, callable $request) use ($voices, $line): void {
            foreach ($voices as $take => $voice) {
                $request($pool->as((string) $take))->post($this->url('/interactions'), $this->voices->interactionPayload($line, $voice['id'], ''));
            }
        });

        $audio = [];
        foreach (array_keys($voices) as $take) {
            $response = $responses[(string) $take] ?? null;
            if (! $response instanceof Response) {
                continue;
            }
            $this->noteKeyState($key, $response);
            $bytes = $response->successful() ? $this->voices->extractAudioBytes($response->json() ?? []) : '';
            if ($bytes !== '') {
                $audio[$take] = $this->voices->ensurePlayableAudio($bytes);
            } else {
                Log::info('Voice design: a take did not speak its line; its own sample is used.', [
                    'key' => $key['name'], 'take' => $take, 'status' => $response->status(),
                ]);
            }
        }

        return $audio;
    }

    /**
     * Requests sent together on one key. $send gets the pool and a function
     * that gives each request the key's headers and options.
     *
     * @param  array{key:string, name:string}  $key
     * @return array<string, Response|\Throwable>
     */
    private function pool(array $key, int $timeout, callable $send): array
    {
        $options = $this->voices->httpOptions();
        $request = fn ($pending) => $pending->timeout($timeout)->connectTimeout(20)->withOptions($options)
            ->withHeaders(['Content-Type' => 'application/json', 'x-goog-api-key' => $key['key']]);

        try {
            return Http::pool(fn (Pool $pool) => $send($pool, $request));
        } catch (ConnectionException $e) {
            return [];
        }
    }

    /**
     * The site's keys that may make voices now, best first.
     *
     * @return list<array{key:string, name:string}>
     */
    private function keysWithRoom(): array
    {
        $manager = app(GeminiKeyManager::class);

        return array_values(array_filter(
            $this->voices->apiKeys(self::MODEL),
            fn (array $key): bool => ! $manager->isOutForToday((string) $key['name'], self::MODEL),
        ));
    }

    /** A daily limit, a spending cap or a refused key set the key aside, as for every other voice. */
    private function noteKeyState(array $key, Response $response): void
    {
        $manager = app(GeminiKeyManager::class);
        $body = (string) $response->body();
        if (GeminiKeyManager::isDailyQuotaError($response->status(), $body) || GeminiKeyManager::isKeyProblem($response->status(), $body)) {
            $manager->markOutForToday((string) $key['name'], self::MODEL);
        }
        if (GeminiKeyManager::isSpendingCapError($response->status(), $body)) {
            $manager->markOutForSpendingCap((string) $key['name']);
        }
    }

    /** What went wrong, in the words the controller answers with. */
    private function classify(Response $response): string
    {
        $body = (string) $response->body();

        return match (true) {
            GeminiKeyManager::isKeyProblem($response->status(), $body) => 'key',
            // A project that holds as many voices as it may.
            (bool) preg_match('/maximum|too many voices|voice limit|limit of \d+ voices/i', $body) && $response->status() !== 429 => 'limit',
            $response->status() === 429 || (bool) preg_match('/RESOURCE_EXHAUSTED|quota/i', $body) => 'quota',
            (bool) preg_match('/SAFETY|blocked|policy|not allowed|prohibited|public figure|real person/i', $body) => 'refused',
            $response->status() === 400 => 'invalid',
            default => 'server',
        };
    }

    /**
     * @param  array{key:string, name:string}  $key
     * @return list<array{id:string, display_name:string}>
     */
    private function listDesigned(array $key): array
    {
        $found = [];
        $token = null;
        for ($page = 0; $page < 5; $page++) {
            try {
                $response = Http::timeout(30)->connectTimeout(20)->withOptions($this->voices->httpOptions())
                    ->withHeaders(['x-goog-api-key' => $key['key']])
                    ->get($this->url('/voices'), array_filter(['type' => 'prompted', 'pageSize' => 100, 'pageToken' => $token]));
            } catch (\Throwable) {
                break;
            }
            if (! $response->successful()) {
                break;
            }
            foreach ((array) $response->json('voices', []) as $voice) {
                $id = is_array($voice) ? $this->voices->findVoiceId(['id' => $voice['id'] ?? null, 'name' => $voice['name'] ?? null]) : null;
                // The engine lists prebuilt voices after the stored ones.
                if ($id !== null && ($voice['type'] ?? 'prompted') === 'prompted') {
                    $found[] = ['id' => $id, 'display_name' => (string) ($voice['display_name'] ?? $voice['displayName'] ?? '')];
                }
            }
            $token = $response->json('nextPageToken') ?? $response->json('next_page_token');
            if (! $token) {
                break;
            }
        }

        return $found;
    }

    /** Deletes the engine's voice, on the key whose project holds it. A voice already gone is fine. */
    private function deleteRemote(?string $voiceId, ?string $keyName): void
    {
        $voiceId = trim((string) $voiceId);
        if ($voiceId === '' || ! str_starts_with($voiceId, 'voice_')) {
            return;
        }
        $keys = $this->voices->apiKeys(self::MODEL);
        $key = collect($keys)->firstWhere('name', $keyName) ?? ($keyName === null ? ($keys[0] ?? null) : null);
        if ($key === null) {
            return;
        }

        try {
            $response = Http::timeout(30)->connectTimeout(20)->withOptions($this->voices->httpOptions())
                ->withHeaders(['x-goog-api-key' => $key['key']])
                ->delete($this->url('/voices/'.rawurlencode($voiceId)));
            if (! $response->successful() && $response->status() !== 404) {
                Log::warning('Voice design: a voice could not be deleted.', ['voice_id' => $voiceId, 'key' => $keyName, 'status' => $response->status()]);
            }
        } catch (\Throwable $e) {
            Log::warning('Voice design: deleting a voice failed.', ['voice_id' => $voiceId, 'exception' => $e->getMessage()]);
        }
    }

    /** A take's audio as an MP3 at the studio's loudness (a WAV when this server cannot encode), on the public disk. */
    private function storeAudio(string $wav): ?string
    {
        $packaged = ShareableAudio::package($wav);
        $path = 'audio/designed/'.now()->format('Ymd').'/'.Str::uuid().'.'.$packaged['extension'];

        return Storage::disk('public')->put($path, $packaged['bytes']) ? $path : null;
    }

    private function url(string $path): string
    {
        return rtrim((string) config('services.gemini_tts.endpoint', 'https://generativelanguage.googleapis.com/v1beta'), '/').$path;
    }

    /** A written answer without quotes, labels or line breaks around it. */
    private function clean(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        $text = preg_replace('/^(description|line|voice|الوصف|الجملة)\s*[:：]\s*/iu', '', $text) ?? $text;

        return trim($text, " \t\"'“”«»„‟`*");
    }
}
