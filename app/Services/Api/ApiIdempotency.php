<?php

namespace App\Services\Api;

use App\Models\AudioGeneration;
use App\Support\Api\ApiError;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * The Idempotency-Key header for generation requests: a client that retries
 * after a timeout or a dropped connection sends the same key, and gets the
 * first request's result instead of a second generation (and a second
 * charge). Keys are kept for 24 hours, for each member. A key sent again with
 * a different body is refused; a key whose generation failed may be retried,
 * since nothing was charged.
 */
class ApiIdempotency
{
    public const HEADER = 'Idempotency-Key';

    private const TTL_HOURS = 24;

    /**
     * @return array{key:string, fingerprint:string}|null null when the request sent no key
     */
    public function keyFor(Request $request, int $userId): ?array
    {
        $raw = trim((string) $request->header(self::HEADER, ''));
        if ($raw === '') {
            return null;
        }
        if (strlen($raw) > 255) {
            throw ApiError::invalid('invalid_idempotency_key', 'Idempotency-Key must be at most 255 characters.', self::HEADER, 400);
        }

        return [
            'key' => 'api-idempotency:'.$userId.':'.hash('sha256', $raw),
            'fingerprint' => hash('sha256', $request->method().' '.$request->path().' '.json_encode($request->all())),
        ];
    }

    /**
     * The generation an earlier request with this key made, if it should be
     * replayed. Throws when the key was used for a different request.
     *
     * @param  array{key:string, fingerprint:string}  $idempotency
     */
    public function replay(array $idempotency, int $userId): ?AudioGeneration
    {
        $saved = Cache::get($idempotency['key']);
        if (! is_array($saved)) {
            return null;
        }
        if (($saved['fingerprint'] ?? null) !== $idempotency['fingerprint']) {
            throw ApiError::invalid('idempotency_key_reused', 'This Idempotency-Key was already used for a different request. Use a new key for a new request.', self::HEADER);
        }

        $generation = AudioGeneration::query()->where('user_id', $userId)->whereKey((int) ($saved['generation_id'] ?? 0))->first();
        if (! $generation || $generation->status === 'failed') {
            // Nothing was charged for it: let the retry run.
            Cache::forget($idempotency['key']);

            return null;
        }

        return $generation;
    }

    /**
     * Holds the key while the first request runs, so a retry sent meanwhile
     * waits for no second generation.
     *
     * @param  array{key:string, fingerprint:string}  $idempotency
     */
    public function lock(array $idempotency): Lock
    {
        $lock = Cache::lock($idempotency['key'].':lock', max(60, (int) config('listen.api.slot_seconds', 300)));
        if (! $lock->get()) {
            throw new ApiError(409, ApiError::TYPE_INVALID_REQUEST, 'idempotent_request_in_progress',
                'A request with this Idempotency-Key is still running. Wait for it to finish, then send it again to get its result.',
                self::HEADER, ['Retry-After' => '2']);
        }

        return $lock;
    }

    /**
     * @param  array{key:string, fingerprint:string}  $idempotency
     */
    public function remember(array $idempotency, AudioGeneration $generation): void
    {
        Cache::put($idempotency['key'], [
            'fingerprint' => $idempotency['fingerprint'],
            'generation_id' => (int) $generation->id,
        ], now()->addHours(self::TTL_HOURS));
    }
}
