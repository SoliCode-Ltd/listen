<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A paid purchase that came from a Google Ads click, kept for the "Conversions
 * from clicks" upload (App\Services\Billing\PurchaseAdsConversionExport).
 */
class AdsPurchase extends Model
{
    public const SOURCE_CARD = 'card';

    public const SOURCE_MANUAL = 'manual';

    protected $fillable = [
        'user_id',
        'source',
        'reference',
        'gclid',
        'value_minor',
        'currency',
        'purchased_at',
    ];

    protected $casts = [
        'value_minor' => 'integer',
        'purchased_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
