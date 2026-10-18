<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\AudioGeneration;
use App\Models\Plan;
use App\Models\UsageCounter;
use App\Models\User;
use App\Services\Api\ApiConcurrency;
use App\Services\Api\ApiIdempotency;
use App\Services\Api\ApiSpeechService;
use App\Support\Api\ApiError;
use App\Support\Api\ApiPlanAccess;
use App\Support\Api\ApiPresenter;
use App\Support\AudioFormats;
use App\Support\UsageEstimator;
use App\Support\UsageSnapshot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * POST /v1/text-to-speech/{voice_id}        → the audio file
 * POST /v1/text-to-speech/{voice_id}/async  → 202 and a history item to poll
 */
class TextToSpeechController extends Controller
{
    public function __construct(
        private readonly ApiSpeechService $speech,
        private readonly ApiConcurrency $concurrency,
        private readonly ApiIdempotency $idempotency,
    ) {}

    public function convert(Request $request, string $voiceId): Response
    {
        [$user, $apiKey, $plan, $quotaPlan] = $this->context($request);
        $userId = (int) $user->id;
        $idempotency = $this->idempotency->keyFor($request, $userId);

        $previous = $idempotency !== null ? $this->idempotency->begin($idempotency, $userId, false) : null;
        if ($previous !== null) {
            $format = strtolower((string) $request->input('output_format', 'mp3'));
            $this->speech->assertOutputFormat($format, $plan);

            return $this->audioResponse($request, $previous, $format, $quotaPlan, $plan, true);
        }

        try {
            $spec = $this->speech->prepare($request, $user, $plan, $quotaPlan, $voiceId, false);
            $slot = $this->concurrency->acquire($userId, ApiPlanAccess::concurrency($plan));
            try {
                // Never cut a paid generation short: it finishes (and is charged or
                // given back) even if the client stops waiting.
                ignore_user_abort(true);
                if (function_exists('set_time_limit')) {
                    @set_time_limit(max(300, (int) ini_get('max_execution_time')));
                }
                $generation = $this->speech->generate($user, $apiKey, $plan, $quotaPlan, $spec, false,
                    $idempotency !== null ? fn (AudioGeneration $made) => $this->idempotency->attach($idempotency, $userId, $made) : null);

                if ($generation->status !== 'done' || blank($generation->audio_path)) {
                    $charged = $generation->status === 'done';
                    throw new ApiError(502, ApiError::TYPE_API, 'generation_failed',
                        $charged
                            ? 'The audio was made but could not be stored. Contact support with the request_id.'
                            : ($generation->error_message ?: 'Voice generation failed.').' No credits were charged.',
                        null, ['X-History-Item-Id' => (string) $generation->id], ['history_item_id' => (int) $generation->id]);
                }

                // Still inside the slot: turning it into another file type takes the server's time too.
                return $this->audioResponse($request, $generation, $spec['output_format'], $quotaPlan, $plan, false, true);
            } finally {
                $slot->release();
            }
        } catch (\Throwable $e) {
            if ($idempotency !== null) {
                $this->idempotency->abandon($idempotency, $userId);
            }

            throw $e;
        }
    }

    public function convertAsync(Request $request, string $voiceId): JsonResponse
    {
        [$user, $apiKey, $plan, $quotaPlan] = $this->context($request);
        $userId = (int) $user->id;
        $idempotency = $this->idempotency->keyFor($request, $userId);

        $generation = $idempotency !== null ? $this->idempotency->begin($idempotency, $userId, true) : null;
        $replayed = $generation !== null;
        if ($replayed) {
            $chars = ApiPresenter::historyItem($generation)['character_count'];
        } else {
            try {
                $spec = $this->speech->prepare($request, $user, $plan, $quotaPlan, $voiceId, true);
                $generation = $this->speech->generate($user, $apiKey, $plan, $quotaPlan, $spec, true,
                    $idempotency !== null ? fn (AudioGeneration $made) => $this->idempotency->attach($idempotency, $userId, $made) : null);
                $chars = $spec['estimate']['chars'];
            } catch (\Throwable $e) {
                if ($idempotency !== null) {
                    $this->idempotency->abandon($idempotency, $userId);
                }

                throw $e;
            }
        }

        return response()->json(ApiPresenter::historyItem($generation), 202, array_filter([
            'Location' => url('/v1/history/'.$generation->id),
            'X-History-Item-Id' => (string) $generation->id,
            'X-Character-Count' => (string) $chars,
            'Idempotent-Replayed' => $replayed ? 'true' : null,
        ]));
    }

    /**
     * The finished audio, in the asked-for type when it can be made (otherwise
     * as stored; Content-Type and X-Output-Format always say which).
     */
    private function audioResponse(Request $request, AudioGeneration $generation, string $format, ?Plan $quotaPlan, ?Plan $plan, bool $replayed = false, bool $holdingSlot = false): Response
    {
        $audio = $holdingSlot || ! self::needsConversion($generation, $format)
            ? self::audioBytes($generation, $format)
            : $this->withSlot($request, $plan, fn () => self::audioBytes($generation, $format));
        if ($audio === null) {
            throw new ApiError(500, ApiError::TYPE_API, 'audio_unavailable',
                'The audio was made but could not be read. Fetch it from GET /v1/history/{history_item_id}/audio.',
                null, ['X-History-Item-Id' => (string) $generation->id], ['history_item_id' => (int) $generation->id]);
        }

        $counter = UsageCounter::query()->where('user_id', $generation->user_id)->where('period_ym', $generation->created_at?->format('Y-m') ?? now()->format('Y-m'))->first();
        $remaining = $counter ? UsageSnapshot::for((int) $generation->user_id, $quotaPlan, $counter)['remaining_chars'] : null;

        $headers = [
            'Content-Type' => AudioFormats::ALL[$audio['format']]['mime'],
            'Content-Length' => (string) strlen($audio['bytes']),
            'Content-Disposition' => 'inline; filename="listen-'.$generation->id.'.'.$audio['format'].'"',
            'X-History-Item-Id' => (string) $generation->id,
            'X-Output-Format' => $audio['format'],
            'X-Character-Count' => (string) (int) ($generation->billable_chars ?? UsageEstimator::billableChars((string) $generation->input_text)),
            'X-Credits-Used' => (string) ApiPresenter::creditsUsed($generation),
            'X-Audio-Duration-Seconds' => (string) (int) $generation->duration_seconds,
        ];
        if ($remaining !== null) {
            $headers['X-Credits-Remaining'] = (string) $remaining;
        }
        if ($replayed) {
            // The first request's result again: nothing new was generated or charged.
            $headers['Idempotent-Replayed'] = 'true';
        }

        return response($audio['bytes'], 200, $headers);
    }

    /** Whether sending the audio in this format means converting the stored file. */
    public static function needsConversion(AudioGeneration $generation, string $format): bool
    {
        $stored = strtolower(pathinfo((string) $generation->audio_path, PATHINFO_EXTENSION));

        return $format !== (isset(AudioFormats::ALL[$stored]) ? $stored : 'mp3');
    }

    /**
     * Runs $work (a file conversion) inside one of the member's and the site's
     * slots, so conversions cannot pile up and take the server's processors.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public function withSlot(Request $request, ?Plan $plan, callable $work): mixed
    {
        $slot = $this->concurrency->acquire((int) $request->user()->id, ApiPlanAccess::concurrency($plan));
        try {
            return $work();
        } finally {
            $slot->release();
        }
    }

    /**
     * @return array{bytes:string, format:string}|null
     */
    public static function audioBytes(AudioGeneration $generation, string $format): ?array
    {
        $path = ltrim((string) $generation->audio_path, '/');
        $disk = Storage::disk('public');
        if ($path === '' || str_contains($path, '..') || ! $disk->exists($path)) {
            return null;
        }

        $stored = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $stored = isset(AudioFormats::ALL[$stored]) ? $stored : 'mp3';
        if ($format !== $stored) {
            $converted = AudioFormats::convert($disk->path($path), $format);
            if ($converted !== null) {
                return ['bytes' => $converted, 'format' => $format];
            }
        }

        $bytes = $disk->get($path);

        return is_string($bytes) && $bytes !== '' ? ['bytes' => $bytes, 'format' => $stored] : null;
    }

    /**
     * @return array{0:User, 1:ApiKey, 2:?Plan, 3:?Plan}
     */
    private function context(Request $request): array
    {
        return [
            $request->user(),
            $request->attributes->get('api_key'),
            $request->attributes->get('api_plan'),
            $request->attributes->get('api_quota_plan'),
        ];
    }
}
