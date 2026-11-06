<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

/**
 * A website an agent's call button loaded on: allowed (it showed) or refused (not on the
 * button's list), when it was first and last seen, and how it was added. Written by
 * AgentCallController::widget at most every ten minutes a website, so a busy site costs one
 * small write now and then, never one a page view.
 */
class AgentWidgetSite extends Model
{
    public $timestamps = false;

    protected $fillable = ['agent_id', 'agent_widget_id', 'origin', 'allowed', 'installed_via', 'first_seen_at', 'last_seen_at'];

    protected $casts = [
        'allowed' => 'boolean',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function widget(): BelongsTo
    {
        return $this->belongsTo(AgentWidget::class, 'agent_widget_id');
    }

    /** The button was asked for on this website: noted once every ten minutes (a site and whether it was allowed). */
    public static function note(AgentWidget $widget, ?string $origin, bool $allowed, ?string $via = null): void
    {
        $origin = AgentWidget::normalizeOrigin($origin);
        if ($origin === null || strlen($origin) > 190) {
            return;
        }
        // Listen's own address is where every link page lives: a website of the agent's only for
        // Listen's own agent, whose button is on Listen's pages.
        if ($origin === AgentWidget::normalizeOrigin(url('/')) && ! filled($widget->agent?->setting('house'))) {
            return;
        }
        if (! Cache::add('agent-site:'.$widget->id.':'.md5($origin).':'.(int) $allowed, 1, now()->addMinutes(10))) {
            return;
        }
        try {
            $now = now();
            $found = static::query()->where('agent_widget_id', $widget->id)->where('origin', $origin)->first();
            if ($found) {
                $found->forceFill(array_filter([
                    'allowed' => $allowed,
                    'last_seen_at' => $now,
                    'installed_via' => $via ?: null,
                ], fn ($v) => $v !== null))->save();

                return;
            }
            static::query()->insertOrIgnore([
                'agent_id' => $widget->agent_id, 'agent_widget_id' => $widget->id, 'origin' => $origin, 'allowed' => $allowed,
                'installed_via' => $via ?: null, 'first_seen_at' => $now, 'last_seen_at' => $now,
            ]);
        } catch (\Throwable) {
            // Before the migration has run, or a moment the database is busy: the button still shows.
        }
    }

    /** "clinic.example" (or "clinic.example:8080"): the website as a person reads it. */
    public function host(): string
    {
        return (string) preg_replace('#^https?://(www\.)?#', '', $this->origin);
    }
}
