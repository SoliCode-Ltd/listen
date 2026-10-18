<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One Idempotency-Key a member sent to the developer API, and the generation
 * the first request with it made (App\Services\Api\ApiIdempotency).
 */
class ApiIdempotencyKey extends Model
{
    protected $fillable = [
        'user_id',
        'key_hash',
        'fingerprint',
        'audio_generation_id',
        'expires_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'audio_generation_id' => 'integer',
        'expires_at' => 'datetime',
    ];

    /** Removes keys more than a day past their 24 hours, a batch at a time. */
    public static function pruneExpired(): void
    {
        do {
            $ids = self::query()->where('expires_at', '<', now()->subDay())->orderBy('id')->limit(1000)->pluck('id');
            if ($ids->isNotEmpty()) {
                self::query()->whereKey($ids->all())->delete();
            }
        } while ($ids->count() === 1000);
    }
}
