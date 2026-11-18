<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A sale sent to ChatGPT Ads from the server (App\Services\Billing\ChatGptAdsConversions).
 */
class ChatGptAdsEvent extends Model
{
    /** A paid Stripe invoice. */
    public const SOURCE_CARD = 'card';

    /** An approved InstaPay / Vodafone Cash payment. */
    public const SOURCE_MANUAL = 'manual';

    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    /** OpenAI refused it, or it could not be sent after every try. */
    public const STATUS_FAILED = 'failed';

    /** Older than OpenAI takes (7 days) before it could be sent. */
    public const STATUS_EXPIRED = 'expired';

    protected $table = 'chatgpt_ads_events';

    protected $fillable = [
        'user_id',
        'reference',
        'source',
        'event_id',
        'payload',
        'status',
        'attempts',
        'response_status',
        'error',
        'sent_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'attempts' => 'integer',
        'response_status' => 'integer',
        'sent_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
