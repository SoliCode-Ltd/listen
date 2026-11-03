<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * A Listen agent: a voice that answers a business's customers on its website,
 * its link page or a QR code, in their dialect (App\Support\Agents).
 *
 * script holds what the agent is, what it must never do, its steps, when it
 * passes the customer to a person and what it collects; knowledge holds the
 * business facts it answers from; choices the setup's policy questions with
 * the answers picked. speaker_id is the voice library speaker the owner chose,
 * live_voice the real-time voice it speaks with.
 */
class Agent extends Model
{
    use SoftDeletes;

    public const DRAFT = 'draft';

    public const READY = 'ready';

    public const LIVE = 'live';

    public const PAUSED = 'paused';

    public const BUSINESS_TYPES = ['healthcare', 'real_estate', 'retail', 'restaurants', 'beauty', 'education', 'automotive', 'services', 'travel', 'other'];

    public const JOBS = ['answer', 'leads', 'booking', 'orders', 'support', 'reception'];

    protected $fillable = [
        'user_id',
        'public_id',
        'name',
        'business_name',
        'business_type',
        'jobs',
        'website_url',
        'language',
        'dialect',
        'speaker_id',
        'live_voice',
        'first_message',
        'script',
        'knowledge',
        'choices',
        'settings',
        'status',
        'max_call_seconds',
        'tested_at',
        'went_live_at',
    ];

    protected $casts = [
        'jobs' => 'array',
        'script' => 'array',
        'choices' => 'array',
        'settings' => 'array',
        'max_call_seconds' => 'integer',
        'tested_at' => 'datetime',
        'went_live_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Agent $agent): void {
            $agent->public_id = $agent->public_id ?: 'agt_'.Str::lower(Str::random(24));
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sources(): HasMany
    {
        return $this->hasMany(AgentSource::class);
    }

    public function widgets(): HasMany
    {
        return $this->hasMany(AgentWidget::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(AgentConversation::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(AgentContact::class);
    }

    public function scopeOwnedBy(Builder $query, User|int $user): Builder
    {
        return $query->where('user_id', $user instanceof User ? $user->id : $user);
    }

    public function isLive(): bool
    {
        return $this->status === self::LIVE;
    }

    /** The widget every agent has: its call button and its link page. */
    public function mainWidget(): AgentWidget
    {
        return $this->widgets()->oldest('id')->first() ?? AgentWidget::createFor($this);
    }

    /** The agent's orb colours (components/orb), the same every time for one agent. */
    public function orbName(): string
    {
        $names = ['listen', 'arabic', 'dialogue', 'places', 'emotions', 'english', 'creator', 'longform', 'pro'];

        return $names[crc32((string) $this->public_id) % count($names)];
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings ?? [], $key, $default);
    }
}
