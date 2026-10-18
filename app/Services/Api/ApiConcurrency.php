<?php

namespace App\Services\Api;

use App\Support\Api\ApiError;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

/**
 * Limits how much API work runs at the same time while the client waits:
 * for each member (their plan's "generations at the same time"), and for the
 * whole site (listen.api.site_slots), so API requests never hold every web
 * worker and the studio and the rest of the site keep answering. Each running
 * request holds one slot of each (cache locks that expire on their own if
 * the process dies).
 */
class ApiConcurrency
{
    /** Most slots a plan can give one member, however its admin field is set. */
    public const MAX_MEMBER_SLOTS = 50;

    /**
     * Takes a free slot, or throws a 429 (the member's are all in use) or a
     * 503 (the site's are).
     */
    public function acquire(int $userId, int $slots): ApiSlot
    {
        $seconds = max(30, (int) config('listen.api.slot_seconds', 300));
        $slots = max(1, min($slots, self::MAX_MEMBER_SLOTS));

        $member = self::first("api-slot:{$userId}:", $slots, $seconds);
        if ($member === null) {
            throw new ApiError(429, ApiError::TYPE_RATE_LIMIT, 'concurrency_limit_exceeded',
                "Your plan allows {$slots} generations at the same time and all are running. Wait for one to finish, then try again.",
                null, ['Retry-After' => '2'], ['concurrency_limit' => $slots]);
        }

        $siteSlots = (int) config('listen.api.site_slots', 8);
        if ($siteSlots <= 0) {
            return new ApiSlot([$member]);
        }

        $site = self::first('api-site-slot:', $siteSlots, $seconds);
        if ($site === null) {
            $member->release();

            throw new ApiError(503, ApiError::TYPE_API, 'server_busy',
                'The service is busy right now. Please try again in a few seconds.', null, ['Retry-After' => '5']);
        }

        return new ApiSlot([$member, $site]);
    }

    private static function first(string $prefix, int $count, int $seconds): ?Lock
    {
        for ($slot = 1; $slot <= $count; $slot++) {
            $lock = Cache::lock($prefix.$slot, $seconds);
            if ($lock->get()) {
                return $lock;
            }
        }

        return null;
    }
}
