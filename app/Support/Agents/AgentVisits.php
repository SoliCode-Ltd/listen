<?php

namespace App\Support\Agents;

use App\Models\AgentConversation;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * A visit: everything one visitor did with one agent in one sitting. They write in the chat,
 * press call from the same card, write again: that is three sides of one conversation, and the
 * admin reads it as one entry, in order.
 *
 * Each written chat and each call stays its own row (it has its own credits, its own recording,
 * its own cost). What joins them is the first one's id, kept on every later one
 * (reservation.visit): set when the later one starts, from what the browser itself named (the
 * chat a call was made from, the call a chat follows) or, failing that, the same browser on the
 * same agent within GAP_MINUTES of the last one. The owner's own tests are never joined.
 */
final class AgentVisits
{
    /** A new conversation this long after the last one's last sign of life is a new visit. */
    public const GAP_MINUTES = 30;

    /** The id of the conversation a visit began with (its own, when it began one). */
    public static function firstId(AgentConversation $conversation): int
    {
        return (int) ($conversation->reservation['visit'] ?? $conversation->id);
    }

    /**
     * A conversation that has just started joins the visit it belongs to: the one $with is in
     * (named by the browser, with its key), else this visitor's last one with this agent if it
     * was only a moment ago.
     */
    public static function join(AgentConversation $new, ?AgentConversation $with = null): void
    {
        if ($new->channel === 'test' || isset($new->reservation['visit'])) {
            return;
        }
        $earlier = $with !== null && (int) $with->agent_id === (int) $new->agent_id && (int) $with->id !== (int) $new->id && $with->channel !== 'test' ? $with : null;
        if ($earlier === null && filled($new->visitor_hash)) {
            $since = ($new->started_at ?? now())->copy()->subMinutes(self::GAP_MINUTES);
            $earlier = AgentConversation::query()
                ->where('agent_id', $new->agent_id)->where('visitor_hash', $new->visitor_hash)
                ->where('id', '<', $new->id)->where('channel', '!=', 'test')
                ->where(fn (Builder $q) => $q->where('ended_at', '>=', $since)->orWhere('last_seen_at', '>=', $since)->orWhere('started_at', '>=', $since))
                ->orderByDesc('id')->first();
        }
        if ($earlier === null) {
            return;
        }
        $new->reservation = ['visit' => self::firstId($earlier)] + (array) ($new->reservation ?? []);
        $new->save();
    }

    /**
     * Every conversation of the visit this one is in, in the order they started (one, when it
     * is the whole visit). Read once for each conversation shown.
     *
     * @return Collection<int, AgentConversation>
     */
    public static function parts(AgentConversation $conversation): Collection
    {
        if ($conversation->relationLoaded('visitParts')) {
            return $conversation->getRelation('visitParts');
        }
        $first = self::firstId($conversation);
        $parts = AgentConversation::query()->with(['agent:id,public_id,user_id,name,business_name,language,settings,deleted_at'])
            ->where(fn (Builder $q) => $q->whereKey($first)->orWhere('reservation->visit', $first))
            ->orderBy('started_at')->orderBy('id')->get();
        if ($parts->isEmpty()) {
            $parts = new Collection([$conversation]);
        }
        $conversation->setRelation('visitParts', $parts);

        return $parts;
    }

    /** A list of visits: only the conversation each one began with. */
    public static function firstOnly(Builder $query): Builder
    {
        return $query->whereNull('agent_conversations.reservation->visit');
    }

    /**
     * Visits with any conversation that matches: the first one itself, or a later one of the
     * visit ($matches narrows a query on the conversations' table, columns unqualified).
     *
     * @param  Closure(Builder): mixed  $matches
     */
    public static function withAny(Builder $query, Closure $matches): Builder
    {
        $later = AgentConversation::query()->whereNotNull('reservation->visit');
        $matches($later);

        return $query->where(function (Builder $visit) use ($matches, $later): void {
            $visit->where(function (Builder $own) use ($matches): void {
                $matches($own);
            })->orWhereIn('agent_conversations.id', $later->select('reservation->visit'));
        });
    }

    /**
     * Joins the conversations of the last days that were kept before visits were: the same rule,
     * in the order they happened. Returns how many were joined to an earlier one.
     */
    public static function joinPast(int $days = 45): int
    {
        $joined = 0;
        $firstOf = [];  // conversation id => the id its visit began with
        $last = [];     // agent|visitor => ['first' => id, 'until' => Carbon]
        AgentConversation::query()->where('started_at', '>=', now()->subDays($days))->where('channel', '!=', 'test')
            ->orderBy('id')->get(['id', 'agent_id', 'visitor_hash', 'channel', 'started_at', 'last_seen_at', 'ended_at', 'reservation'])
            ->each(function (AgentConversation $c) use (&$joined, &$firstOf, &$last): void {
                $kept = (array) ($c->reservation ?? []);
                $key = filled($c->visitor_hash) ? $c->agent_id.'|'.$c->visitor_hash : null;
                $first = isset($kept['visit']) ? (int) $kept['visit'] : null;
                if ($first === null) {
                    // The call a chat was kept as following, else the same browser a moment before.
                    $named = isset($kept['after_call']) ? ($firstOf[(int) $kept['after_call']] ?? null) : null;
                    $before = $key !== null ? ($last[$key] ?? null) : null;
                    $first = $named ?? ($before !== null && $c->started_at !== null && $before['until']->gte($c->started_at->copy()->subMinutes(self::GAP_MINUTES)) ? $before['first'] : null);
                    if ($first !== null && $first !== (int) $c->id) {
                        $c->reservation = ['visit' => $first] + $kept;
                        $c->save();
                        $joined++;
                    }
                }
                $firstOf[(int) $c->id] = $first ?? (int) $c->id;
                if ($key !== null) {
                    $until = collect([$c->ended_at, $c->last_seen_at, $c->started_at])->filter()->max() ?? now();
                    $last[$key] = ['first' => $firstOf[(int) $c->id], 'until' => Carbon::instance($until)];
                }
            });

        return $joined;
    }
}
