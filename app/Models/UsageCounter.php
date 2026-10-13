<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UsageCounter extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'period_ym',
        'total_minutes',
        'total_chars',
        'bonus_chars',
        'total_requests',
    ];

    protected $casts = [
        'bonus_chars' => 'integer',
        'total_chars' => 'integer',
        'total_requests' => 'integer',
        'total_minutes' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** "October 2026" for period_ym "2026-10". */
    public function monthLabel(): string
    {
        try {
            return \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $this->period_ym.'-01')->format('F Y');
        } catch (\Throwable) {
            return (string) $this->period_ym;
        }
    }

    public function isCurrentMonth(): bool
    {
        return $this->period_ym === now()->format('Y-m');
    }
}
