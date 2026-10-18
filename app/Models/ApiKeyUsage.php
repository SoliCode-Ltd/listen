<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * What one API key spent in one calendar month. The member's plan credits are
 * still counted in usage_counters, as for the dashboard; this row only backs
 * the key's own budget and the per-key figures in the dashboard and admin.
 */
class ApiKeyUsage extends Model
{
    protected $fillable = [
        'api_key_id',
        'period_ym',
        'requests',
        'credits',
    ];

    protected $casts = [
        'requests' => 'integer',
        'credits' => 'integer',
    ];

    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(ApiKey::class);
    }

    /**
     * Adds (or with negative numbers, gives back) requests and credits for a
     * key's month. Never goes below zero.
     */
    public static function apply(int $apiKeyId, string $periodYm, int $requestsDelta, int $creditsDelta): void
    {
        if ($requestsDelta === 0 && $creditsDelta === 0) {
            return;
        }

        DB::transaction(function () use ($apiKeyId, $periodYm, $requestsDelta, $creditsDelta): void {
            $row = self::query()
                ->where('api_key_id', $apiKeyId)
                ->where('period_ym', $periodYm)
                ->lockForUpdate()
                ->first();

            if (! $row) {
                // The key may have been deleted with its member; nothing to count against.
                if (! ApiKey::query()->whereKey($apiKeyId)->exists()) {
                    return;
                }
                $row = self::create([
                    'api_key_id' => $apiKeyId,
                    'period_ym' => $periodYm,
                    'requests' => 0,
                    'credits' => 0,
                ]);
            }

            $row->update([
                'requests' => max(0, (int) $row->requests + $requestsDelta),
                'credits' => max(0, (int) $row->credits + $creditsDelta),
            ]);
        });
    }
}
