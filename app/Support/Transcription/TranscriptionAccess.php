<?php

namespace App\Support\Transcription;

use App\Models\Plan;
use Illuminate\Support\Facades\Schema;

/**
 * What a plan includes of speech to text ("Transcribe"), as set on the plan
 * (Admin → Plans → Transcribe). The minutes come from the plan only: there are
 * no packs, top-ups or pay-as-you-go for transcription.
 */
final class TranscriptionAccess
{
    /** Whether the plans table has the transcription columns yet (before the migration it has not). */
    public static function planColumns(): bool
    {
        return once(fn (): bool => rescue(fn () => Schema::hasColumn('plans', 'transcription_enabled'), false, false));
    }

    public static function planIncludes(?Plan $plan): bool
    {
        return $plan !== null && self::planColumns() && (bool) $plan->transcription_enabled && (int) $plan->transcription_minutes_month > 0;
    }

    /** Minutes of audio a month on the plan (0 when it has no transcription). */
    public static function monthlyMinutes(?Plan $plan): int
    {
        return self::planIncludes($plan) ? max(0, (int) $plan->transcription_minutes_month) : 0;
    }

    /** The longest file the plan takes, in minutes (0 when it has no transcription). */
    public static function maxFileMinutes(?Plan $plan): int
    {
        return self::planIncludes($plan) ? max(1, (int) $plan->transcription_max_file_minutes) : 0;
    }

    /** Jobs one member may have working at once. */
    public static function concurrentJobs(?Plan $plan): int
    {
        return self::planIncludes($plan) ? max(1, (int) $plan->transcription_concurrent_jobs) : 0;
    }

    /**
     * What a minute of audio costs Listen on this plan when every transcript
     * uses the most the plan allows (timings or speakers cost more than plain text).
     */
    public static function costPerMinute(?Plan $plan): float
    {
        $costs = (array) config('listen.transcription.cost_per_minute', []);
        $timed = $plan !== null && ((bool) $plan->transcription_timestamps || (bool) $plan->transcription_speakers);

        return (float) ($costs[$timed ? 'timed' : 'plain'] ?? 0);
    }
}
