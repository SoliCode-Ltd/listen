<?php

namespace App\Services\Api;

use App\Models\ApiIdempotencyKey;
use App\Models\AudioGeneration;
use App\Support\Api\ApiError;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * The Idempotency-Key header for generation requests: a client that retries
 * after a timeout or a dropped connection sends the same key, and gets the
 * first request's result instead of a second generation (and a second
 * charge). Keys are kept for 24 hours, for each member, in the database
 * (api_idempotency_keys), and tied to the generation the moment it exists.
 *
 * A key sent again with a different body is refused; a key whose generation
 * failed (and so was given back) may be used again.
 */
class ApiIdempotency
{
    public const HEADER = 'Idempotency-Key';

    private const TTL_HOURS = 24;

    /**
     * A key held by a request that has not recorded its generation yet, for
     * longer than this, belongs to a request that ended before charging
     * anything: a retry may take it over.
     */
    private const CLAIM_SECONDS = 120;

    private static ?bool $available = null;

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
        if (! self::available()) {
            return null;
        }

        return [
            'key' => hash('sha256', $raw),
            'fingerprint' => hash('sha256', $request->method().' '.$request->path().' '.json_encode($request->all())),
        ];
    }

    /**
     * Takes the key for this request, or returns the generation an earlier
     * request with it made, to be sent again. Throws when the key was used for
     * a different request, or its request is still running (a background
     * request instead gets its generation back, whatever its state).
     *
     * @param  array{key:string, fingerprint:string}  $idempotency
     */
    public function begin(array $idempotency, int $userId, bool $async): ?AudioGeneration
    {
        try {
            return DB::transaction(function () use ($idempotency, $userId, $async): ?AudioGeneration {
                $row = ApiIdempotencyKey::query()
                    ->where('user_id', $userId)
                    ->where('key_hash', $idempotency['key'])
                    ->lockForUpdate()
                    ->first();
                if ($row && $row->expires_at->isPast()) {
                    $row->delete();
                    $row = null;
                }

                if (! $row) {
                    ApiIdempotencyKey::query()->create([
                        'user_id' => $userId,
                        'key_hash' => $idempotency['key'],
                        'fingerprint' => $idempotency['fingerprint'],
                        'expires_at' => now()->addHours(self::TTL_HOURS),
                    ]);

                    return null;
                }

                if (! hash_equals((string) $row->fingerprint, $idempotency['fingerprint'])) {
                    // Made, then deleted by the member: never made (and charged) again for this key.
                    if (hash_equals((string) $row->fingerprint, self::deletedFingerprint($idempotency['fingerprint']))) {
                        throw new ApiError(410, ApiError::TYPE_INVALID_REQUEST, 'idempotent_result_deleted',
                            'The generation made with this Idempotency-Key was deleted. Send a new Idempotency-Key to generate it again.',
                            self::HEADER);
                    }

                    throw ApiError::invalid('idempotency_key_reused', 'This Idempotency-Key was already used for a different request. Use a new key for a new request.', self::HEADER);
                }

                if ($row->audio_generation_id === null) {
                    if ($row->updated_at !== null && $row->updated_at->gt(now()->subSeconds(self::CLAIM_SECONDS))) {
                        throw self::inProgress(null);
                    }
                    // Its request ended before it reserved anything: this one takes the key.
                    $row->touch();

                    return null;
                }

                $generation = AudioGeneration::query()->where('user_id', $userId)->whereKey($row->audio_generation_id)->first();
                if (! $generation || $generation->status === 'failed') {
                    // Failed (its credits were given back) or deleted: this request makes it again.
                    $row->forceFill(['audio_generation_id' => null])->save();

                    return null;
                }

                if ($generation->status === 'done' || $async) {
                    return $generation;
                }

                throw self::inProgress($generation);
            }, 3);
        } catch (UniqueConstraintViolationException) {
            // Another request with the same key took it a moment ago.
            throw self::inProgress(null);
        }
    }

    /**
     * Ties the key to the generation its request made. Runs inside the
     * reservation, so the key and the charge are saved together.
     *
     * @param  array{key:string, fingerprint:string}  $idempotency
     */
    public function attach(array $idempotency, int $userId, AudioGeneration $generation): void
    {
        ApiIdempotencyKey::query()
            ->where('user_id', $userId)
            ->where('key_hash', $idempotency['key'])
            ->update(['audio_generation_id' => (int) $generation->id, 'updated_at' => now()]);
    }

    /**
     * Lets the key go when its request ended before making anything (refused
     * by a limit, a validation error), so the client can send it again.
     *
     * @param  array{key:string, fingerprint:string}  $idempotency
     */
    public function abandon(array $idempotency, int $userId): void
    {
        try {
            ApiIdempotencyKey::query()
                ->where('user_id', $userId)
                ->where('key_hash', $idempotency['key'])
                ->whereNull('audio_generation_id')
                ->delete();
        } catch (\Throwable $e) {
            Log::warning('Could not release an Idempotency-Key.', ['error' => $e->getMessage()]);
        }
    }

    /**
     * A member deleted a generation a key made. Deleting it clears the key's
     * link, which would let a retry with that key make and charge it again:
     * the key is kept instead, marked as having made something now deleted,
     * until it expires.
     */
    public static function markResultDeleted(AudioGeneration $generation): void
    {
        if ($generation->api_key_id === null || ! self::available()) {
            return;
        }

        try {
            ApiIdempotencyKey::query()
                ->where('user_id', $generation->user_id)
                ->where('audio_generation_id', $generation->id)
                ->get()
                ->each(fn (ApiIdempotencyKey $row) => $row->forceFill([
                    'fingerprint' => self::deletedFingerprint((string) $row->fingerprint),
                ])->save());
        } catch (\Throwable $e) {
            Log::warning('Could not keep the Idempotency-Key of a deleted generation.', ['error' => $e->getMessage()]);
        }
    }

    private static function deletedFingerprint(string $fingerprint): string
    {
        return hash('sha256', 'deleted:'.$fingerprint);
    }

    private static function inProgress(?AudioGeneration $generation): ApiError
    {
        return $generation !== null
            ? new ApiError(409, ApiError::TYPE_INVALID_REQUEST, 'idempotent_request_in_progress',
                'The request with this Idempotency-Key is still being generated. Fetch it with GET /v1/history/'.$generation->id.'.',
                self::HEADER, ['Retry-After' => '2'], ['history_item_id' => (int) $generation->id])
            : new ApiError(409, ApiError::TYPE_INVALID_REQUEST, 'idempotent_request_in_progress',
                'A request with this Idempotency-Key is still running. Wait for it to finish, then send it again to get its result.',
                self::HEADER, ['Retry-After' => '2']);
    }

    /** Before the api_idempotency_keys migration has run on a server, keys are not kept. */
    /** Whether the table is there (it is made by a migration run by hand on the servers). */
    public static function available(): bool
    {
        if (self::$available === null) {
            try {
                self::$available = Schema::hasTable('api_idempotency_keys');
            } catch (\Throwable) {
                self::$available = false;
            }
            if (! self::$available) {
                Log::warning('The api_idempotency_keys table is missing: run the migrations. Idempotency-Key is ignored until then.');
            }
        }

        return self::$available;
    }
}
