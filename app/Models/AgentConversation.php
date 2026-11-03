<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One call with an agent: from the owner testing it (channel test), its call
 * button on a website (widget) or its link page (link).
 *
 * transcript is the conversation, turn by turn; lead the customer's details
 * the agent collected; summary what the call was about, written after it
 * ended. billed_seconds and credits are what it took from the owner's plan.
 */
class AgentConversation extends Model
{
    public const LIVE = 'live';

    public const ENDED = 'ended';

    public const FAILED = 'failed';

    protected $fillable = [
        'agent_id',
        'user_id',
        'agent_widget_id',
        'public_id',
        'channel',
        'visitor_hash',
        'origin',
        'page_url',
        'country',
        'status',
        'end_reason',
        'started_at',
        'last_seen_at',
        'ended_at',
        'duration_seconds',
        'billed_seconds',
        'credits',
        'transcript',
        'summary',
        'lead',
        'outcome',
        'usage',
        'cost_usd',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'ended_at' => 'datetime',
        'duration_seconds' => 'integer',
        'billed_seconds' => 'integer',
        'credits' => 'integer',
        'transcript' => 'array',
        'lead' => 'array',
        'usage' => 'array',
        'cost_usd' => 'decimal:5',
    ];

    protected static function booted(): void
    {
        static::creating(function (AgentConversation $c): void {
            $c->public_id = $c->public_id ?: 'cnv_'.Str::lower(Str::random(24));
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function widget(): BelongsTo
    {
        return $this->belongsTo(AgentWidget::class, 'agent_widget_id');
    }

    public function isLive(): bool
    {
        return $this->status === self::LIVE;
    }
}
