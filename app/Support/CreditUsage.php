<?php

namespace App\Support;

use App\Models\AgentConversation;
use App\Models\AudioGeneration;
use App\Models\User;
use App\Models\VideoDub;
use App\Services\PlanAccessService;
use App\Support\Agents\AgentAccess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Where a member's credits went this credit period, for the Usage page: what is left, what
 * used the rest (studio audio, the developer API, video dubbing, agent calls by agent, agent chats), and
 * each item with its credits, newest first. Read from what was charged, never estimated.
 */
final class CreditUsage
{
    /** Items listed at most on the page. */
    public const ITEMS = 60;

    /** Whether anything used credits this period (else there is nothing to show, nor a link to it). */
    public static function hasUse(array $usage): bool
    {
        return ! empty($usage['items']) || (int) ($usage['used'] ?? 0) > 0;
    }

    /**
     * @return array{period: array{starts_at: Carbon, ends_at: Carbon}, mode: string, total: int, left: int, used: int,
     *     parts: array<string, array{credits: int, seconds: int, count: int}>, agents: list<array{name: string, credits: int, seconds: int, count: int}>,
     *     items: list<array{kind: string, title: string, at: Carbon, seconds: int, credits: int}>}
     */
    public static function for(User $user): array
    {
        $id = (int) $user->id;
        $period = CreditPeriod::current($id);
        $from = $period['starts_at'];
        $snapshot = UsageSnapshot::for($id, app(PlanAccessService::class)->resolveQuotaPlanForUserId($id), CreditPeriod::counter($id));

        $audio = AudioGeneration::query()->with('textItem:id,title')
            ->where('user_id', $id)->where('status', 'done')->where('created_at', '>=', $from)
            ->latest('id')->limit(500)
            ->get(AudioGeneration::memberNewestColumns(['id', 'text_item_id', 'api_key_id', 'billable_chars', 'duration_seconds', 'created_at', 'is_dialogue']));
        // No calls before the agents tables exist (a deploy just ahead of its migration).
        $calls = AgentAccess::tablesReady() ? AgentConversation::query()->with('agent:id,name')
            ->where('user_id', $id)->where('free', false)->whereNotNull('settled_at')->where('started_at', '>=', $from)
            ->latest('id')->limit(500)
            ->get(['id', 'agent_id', 'channel', 'credits', 'billed_seconds', 'started_at', 'usage']) : collect();
        // A written chat is not a call: it has replies, not minutes.
        [$chats, $calls] = $calls->partition(fn (AgentConversation $c): bool => in_array($c->channel, ['chat', 'chat_link'], true));
        $dubs = rescue(fn () => VideoDub::query()
            ->where('user_id', $id)->where('status', 'done')->where('is_free', false)->where('created_at', '>=', $from)
            ->latest('id')->limit(200)->get(['id', 'original_name', 'video_seconds', 'reservation', 'created_at']), collect(), false);

        $items = collect();
        foreach ($audio as $g) {
            $items->push([
                'kind' => $g->api_key_id ? 'api' : 'studio',
                'title' => trim((string) ($g->textItem->title ?? '')),
                'at' => $g->created_at,
                'seconds' => (int) $g->duration_seconds,
                'credits' => max(0, (int) $g->billable_chars),
            ]);
        }
        foreach ($calls as $c) {
            $items->push([
                'kind' => 'agent',
                'title' => (string) ($c->agent->name ?? ''),
                'at' => $c->started_at,
                'seconds' => (int) $c->billed_seconds,
                'credits' => max(0, (int) $c->credits),
            ]);
        }
        foreach ($chats as $c) {
            $items->push([
                'kind' => 'chat',
                'title' => (string) ($c->agent->name ?? ''),
                'at' => $c->started_at,
                'seconds' => 0,
                'replies' => (int) ($c->usage['replies'] ?? 0),
                'credits' => max(0, (int) $c->credits),
            ]);
        }
        foreach ($dubs as $d) {
            $items->push([
                'kind' => 'dub',
                'title' => (string) ($d->original_name ?? ''),
                'at' => $d->created_at,
                'seconds' => (int) round((float) $d->video_seconds),
                'credits' => max(0, (int) ($d->reservation['credits'] ?? 0)),
            ]);
        }

        $parts = [];
        foreach (['studio', 'api', 'dub', 'agent', 'chat'] as $kind) {
            $of = $items->where('kind', $kind);
            $parts[$kind] = ['credits' => (int) $of->sum('credits'), 'seconds' => (int) $of->sum('seconds'), 'count' => $of->count(), 'replies' => (int) $of->sum('replies')];
        }
        $agents = $calls->groupBy('agent_id')->map(fn (Collection $rows) => [
            'name' => (string) ($rows->first()->agent->name ?? ''),
            'credits' => (int) $rows->sum('credits'),
            'seconds' => (int) $rows->sum('billed_seconds'),
            'count' => $rows->count(),
        ])->sortByDesc('credits')->values()->all();

        $total = max(0, (int) ($snapshot['plan_chars'] ?? 0));
        $left = max(0, (int) ($snapshot['remaining_chars'] ?? 0));

        return [
            'period' => ['starts_at' => $from, 'ends_at' => $period['ends_at']],
            'mode' => (string) ($snapshot['mode'] ?? 'monthly'),
            'total' => $total,
            'left' => $left,
            'used' => max(0, (int) ($snapshot['total_chars'] ?? 0)),
            'parts' => $parts,
            'agents' => $agents,
            'items' => $items->sortByDesc(fn ($i) => $i['at']?->getTimestamp() ?? 0)->take(self::ITEMS)->values()->all(),
        ];
    }
}
