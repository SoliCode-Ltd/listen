<?php

namespace App\Models;

use App\Support\CreditPeriod;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class UsageCounter extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'period_ym',
        'total_minutes',
        'total_seconds',
        'total_chars',
        'bonus_chars',
        'total_requests',
    ];

    protected $casts = [
        'bonus_chars' => 'integer',
        'total_chars' => 'integer',
        'total_requests' => 'integer',
        'total_minutes' => 'integer',
        'total_seconds' => 'integer',
    ];

    /**
     * Whole minutes for a month's seconds: rounded up once for the month,
     * never per generation.
     */
    public static function minutesFor(int $seconds): int
    {
        return intdiv(max(0, $seconds) + 59, 60);
    }

    /**
     * Add (or with a negative number, give back) seconds of audio, keeping
     * total_minutes in step.
     */
    public function addSeconds(int $seconds): void
    {
        if ($seconds === 0) {
            return;
        }

        if ($seconds > 0) {
            $this->increment('total_seconds', $seconds);
        } else {
            static::query()->whereKey($this->getKey())->update([
                'total_seconds' => DB::raw('CASE WHEN total_seconds > '.(-$seconds).' THEN total_seconds - '.(-$seconds).' ELSE 0 END'),
            ]);
        }

        $this->refresh();
        $this->forceFill(['total_minutes' => self::minutesFor((int) $this->total_seconds)])->save();
    }

    /** "2 min 05 s" for the admin. */
    public function audioLabel(): string
    {
        $seconds = (int) $this->total_seconds;
        if ($seconds < 60) {
            return $seconds.' s';
        }
        if ($seconds < 3600) {
            return intdiv($seconds, 60).' min '.sprintf('%02d', $seconds % 60).' s';
        }

        return intdiv($seconds, 3600).' h '.sprintf('%02d', intdiv($seconds % 3600, 60)).' min';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * "October 2026" for a calendar month ("2026-10"), "From 14 Oct 2026" for a
     * credit period that follows a plan's own dates ("2026-10-14 08:37").
     */
    public function monthLabel(): string
    {
        $key = (string) $this->period_ym;
        try {
            return CreditPeriod::followsPlan($key)
                ? 'From '.Carbon::createFromFormat('Y-m-d H:i', $key, 'UTC')->format('j M Y')
                : Carbon::createFromFormat('Y-m-d', $key.'-01')->format('F Y');
        } catch (\Throwable) {
            return $key;
        }
    }

    /** Whether this is the member's credit period now. */
    public function isCurrentMonth(): bool
    {
        return $this->user_id !== null && $this->period_ym === CreditPeriod::current((int) $this->user_id)['key'];
    }
}
