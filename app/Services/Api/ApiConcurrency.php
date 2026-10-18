<?php

namespace App\Services\Api;

use App\Support\Api\ApiError;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

/**
 * Limits how many generations one member runs through the API at the same
 * time, so one integration cannot hold every web worker. Each running
 * generation holds one of the member's slots (a cache lock that expires on
 * its own if the process dies).
 */
class ApiConcurrency
{
    /**
     * Takes a free slot, or throws a 429 when all are in use.
     */
    public function acquire(int $userId, int $slots): Lock
    {
        $seconds = max(30, (int) config('listen.api.slot_seconds', 300));

        for ($slot = 1; $slot <= max(1, $slots); $slot++) {
            $lock = Cache::lock("api-slot:{$userId}:{$slot}", $seconds);
            if ($lock->get()) {
                return $lock;
            }
        }

        throw new ApiError(429, ApiError::TYPE_RATE_LIMIT, 'concurrency_limit_exceeded',
            "Your plan allows {$slots} generations at the same time and all are running. Wait for one to finish, then try again.",
            null, ['Retry-After' => '2'], ['concurrency_limit' => $slots]);
    }
}
