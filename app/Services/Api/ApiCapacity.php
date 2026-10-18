<?php

namespace App\Services\Api;

use App\Support\Api\ApiError;
use Illuminate\Support\Facades\Cache;

/**
 * The developer API's share of the site's daily speech ceiling
 * (listen.cost_guardrails.daily_gemini_char_limit). The studio, the browser
 * extension and the API draw on the same daily ceiling; without a share, one
 * busy integration could use it all and leave every other member without the
 * premium voices until the next day.
 */
final class ApiCapacity
{
    /** Refuses a request that would take the API past its share today. */
    public static function assertRoom(int $chars): void
    {
        $limit = self::limit();
        if ($limit <= 0) {
            return;
        }

        if (self::usedToday() + max(0, $chars) > $limit) {
            throw new ApiError(503, ApiError::TYPE_API, 'capacity_reached',
                'The API has reached its speech capacity for today. Please try again tomorrow; generations already accepted are not affected.',
                null, ['Retry-After' => (string) max(60, now()->diffInSeconds(now()->endOfDay(), true))]);
        }
    }

    /** Counts an accepted request against today's share. */
    public static function record(int $chars): void
    {
        if ($chars <= 0 || self::limit() <= 0) {
            return;
        }

        Cache::add(self::key(), 0, now()->endOfDay()->addHour());
        Cache::increment(self::key(), $chars);
    }

    public static function usedToday(): int
    {
        return (int) Cache::get(self::key(), 0);
    }

    /** Characters the API may use today; 0 when there is no ceiling. */
    public static function limit(): int
    {
        $ceiling = max(0, (int) config('listen.cost_guardrails.daily_gemini_char_limit', 0));
        $share = (float) config('listen.api.daily_share', 0.6);
        if ($ceiling <= 0 || $share <= 0 || $share >= 1) {
            return 0;
        }

        return (int) floor($ceiling * $share);
    }

    private static function key(): string
    {
        return 'api:capacity:'.now()->format('Ymd');
    }
}
