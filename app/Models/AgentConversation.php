<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * One call with an agent: from the owner testing it (channel test), its call
 * button on a website (widget) or its link page (link).
 *
 * transcript is the conversation, turn by turn; lead the customer's details
 * the agent collected; caller who called, when the website said who is signed
 * in (CallerIdentity); summary what the call was about, written after it
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
        'call_key_hash',
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
        'caller',
        'outcome',
        'usage',
        'cost_usd',
        'reservation',
        'period_ym',
        'free',
        'settled_at',
    ];

    protected $hidden = ['call_key_hash'];

    protected $casts = [
        'started_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'ended_at' => 'datetime',
        'duration_seconds' => 'integer',
        'billed_seconds' => 'integer',
        'credits' => 'integer',
        'transcript' => 'array',
        'lead' => 'array',
        'caller' => 'array',
        'usage' => 'array',
        'cost_usd' => 'decimal:5',
        'reservation' => 'array',
        'free' => 'boolean',
        'settled_at' => 'datetime',
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

    /**
     * Whether calls can keep who called yet: on a server the code reaches a minute before its
     * migration (2026_11_23_120000), calls go on without it.
     */
    public static function keepsCaller(): bool
    {
        if (Cache::get('agents.conversations.caller') === true) {
            return true;
        }
        try {
            $has = Schema::hasColumn('agent_conversations', 'caller');
        } catch (\Throwable) {
            $has = false;
        }
        if ($has) {
            Cache::put('agents.conversations.caller', true, now()->addMinutes(10));
        }

        return $has;
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
