<?php

namespace App\Services\Agents;

use App\Models\Agent;
use App\Models\AgentContact;
use App\Models\AgentConversation;
use App\Models\AgentWidget;
use App\Services\Billing\MemberUsageMeter;
use App\Services\PlanAccessService;
use App\Support\Credits;
use App\Support\CreditPeriod;
use App\Support\UsageSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A call with an agent, from start to settled (App\Http\Controllers\AgentCallController):
 *
 * - start(): the owner's plan holds the call's longest length in credits
 *   (MemberUsageMeter::reserveCredits, as a dub does), or the call is one of
 *   the owner's free test seconds; a short-lived key locked to the agent's
 *   setup is made; the caller's browser gets the key and a call key of its own.
 * - ping(): the browser says the call is still on, with what was said.
 * - tool(): what the agent asked for during the call (save_contact ...).
 * - end(): the real length is charged and the rest of the hold given back,
 *   once. Calls whose browser went quiet are settled by settleStale().
 */
class AgentCalls
{
    public const PING_GRACE_SECONDS = 45;

    public function __construct(
        private GeminiClient $gemini,
        private MemberUsageMeter $meter,
        private PlanAccessService $plans,
    ) {}

    public static function creditsPerMinute(): int
    {
        return max(1, (int) config('listen.agents.credits_per_minute', 900));
    }

    public static function creditsFor(int $seconds): int
    {
        return (int) ceil(max(0, $seconds) * self::creditsPerMinute() / 60);
    }

    /** Free test seconds the owner has left (their own test calls, all agents). */
    public function freeTestSecondsLeft(int $userId): int
    {
        // A live free call counts its whole hold until it is settled.
        $used = AgentConversation::query()
            ->where('user_id', $userId)->where('channel', 'test')->where('free', true)
            ->get(['billed_seconds', 'reservation', 'settled_at'])
            ->sum(fn (AgentConversation $c) => $c->settled_at ? (int) $c->billed_seconds : (int) ($c->reservation['seconds'] ?? 0));

        return max(0, (int) config('listen.agents.free_test_seconds', 120) - $used);
    }

    /**
     * @param  array{origin?: ?string, page_url?: ?string, visitor?: ?string, country?: ?string}  $context
     * @return array{ok: bool, error?: string, conversation?: AgentConversation, call_key?: string, token?: string, max_seconds?: int, free?: bool}
     */
    public function start(Agent $agent, string $channel, ?AgentWidget $widget = null, array $context = []): array
    {
        $userId = (int) $agent->user_id;
        $max = max(30, min((int) $agent->max_call_seconds, (int) config('listen.agents.max_call_seconds', 600)));

        $free = false;
        // Listen's own agents (HouseAgent): their calls are on Listen, and short.
        if ($agent->setting('house')) {
            $free = true;
            $max = min($max, \App\Support\Agents\HouseAgent::MAX_SECONDS);
        } elseif ($channel === 'test') {
            $left = $this->freeTestSecondsLeft($userId);
            if ($left >= 20) {
                $free = true;
                $max = min($max, $left);
            }
        }

        $quotaPlan = $free ? null : $this->plans->resolveQuotaPlanForUserId($userId);
        if (! $free) {
            $affordable = $this->affordableSeconds($userId, $quotaPlan);
            if ($affordable < 30) {
                return ['ok' => false, 'error' => 'credits'];
            }
            $max = min($max, $affordable);
        }

        $callKey = Str::random(40);
        $base = [
            'agent_id' => $agent->id,
            'user_id' => $userId,
            'agent_widget_id' => $widget?->id,
            'call_key_hash' => hash('sha256', $callKey),
            'channel' => $channel,
            'visitor_hash' => isset($context['visitor']) ? hash('sha256', (string) $context['visitor']) : null,
            'origin' => Str::limit((string) ($context['origin'] ?? ''), 250, '') ?: null,
            'page_url' => Str::limit((string) ($context['page_url'] ?? ''), 490, '') ?: null,
            'country' => $context['country'] ?? null,
            'status' => AgentConversation::LIVE,
            'started_at' => now(),
            'last_seen_at' => now(),
            'free' => $free,
            'transcript' => [],
        ];

        try {
            if ($free) {
                $conversation = AgentConversation::create($base + [
                    'reservation' => ['seconds' => $max, 'credits' => 0, 'free' => true],
                ]);
            } else {
                $estimate = ['chars' => $max * Credits::CHARS_PER_SECOND, 'words' => 0, 'seconds' => $max,
                    'minutes' => (int) ceil($max / 60), 'credits' => self::creditsFor($max)];
                $conversation = $this->meter->reserveCredits($userId, $quotaPlan, $estimate,
                    fn (array $reservation) => AgentConversation::create($base + [
                        'reservation' => $reservation,
                        'period_ym' => $reservation['period'],
                    ]));
            }
        } catch (ValidationException) {
            return ['ok' => false, 'error' => 'credits'];
        }

        $key = $this->gemini->liveToken(LiveSession::setupFor($agent), $max + 20);
        if ($key === null) {
            $this->settle($conversation, 'failed', 0);

            return ['ok' => false, 'error' => 'busy'];
        }

        if ($widget) {
            $widget->forceFill(['last_seen_at' => now(), 'last_seen_origin' => $base['origin']])->save();
        }

        return ['ok' => true, 'conversation' => $conversation, 'call_key' => $callKey, 'token' => $key['token'], 'max_seconds' => $max, 'free' => $free];
    }

    /** Seconds of call the owner's plan can still pay for, at the agents' rate. */
    public function affordableSeconds(int $userId, $quotaPlan): int
    {
        $snapshot = UsageSnapshot::for($userId, $quotaPlan, CreditPeriod::counter($userId));
        $remaining = max(0, (int) ($snapshot['remaining_chars'] ?? 0));

        return (int) floor($remaining * 60 / self::creditsPerMinute());
    }

    public function findByKey(string $publicId, string $callKey): ?AgentConversation
    {
        $conversation = AgentConversation::where('public_id', $publicId)->first();
        if (! $conversation || ! hash_equals((string) $conversation->call_key_hash, hash('sha256', $callKey))) {
            return null;
        }

        return $conversation;
    }

    /**
     * @param  array<int, array{role?: string, text?: string}>  $turns  new turns since the last ping
     * @return array{stop: bool, seconds_left: int}
     */
    public function ping(AgentConversation $conversation, array $turns = []): array
    {
        if (! $conversation->isLive()) {
            return ['stop' => true, 'seconds_left' => 0];
        }
        $this->appendTurns($conversation, $turns);
        $conversation->last_seen_at = now();
        $conversation->save();
        $left = $this->secondsLeft($conversation);

        return ['stop' => $left <= 0, 'seconds_left' => $left];
    }

    /**
     * What the agent asked for during the call. Returns what the agent is told back.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function tool(AgentConversation $conversation, string $name, array $args): array
    {
        if ($name === 'save_contact') {
            $clean = fn ($v, int $max) => Str::limit(trim(strip_tags((string) $v)), $max, '');
            $lead = array_filter([
                'name' => $clean($args['name'] ?? '', 120),
                'phone' => $clean(preg_replace('/[^\d+]/', '', (string) ($args['phone'] ?? '')), 40),
                'email' => filter_var(trim((string) ($args['email'] ?? '')), FILTER_VALIDATE_EMAIL) ?: '',
                'note' => $clean($args['note'] ?? '', 500),
            ], fn ($v) => $v !== '' && $v !== null);
            if (($lead['phone'] ?? '') === '' && ($lead['email'] ?? '') === '') {
                return ['result' => 'missing', 'message' => 'Ask for a phone number or an email first.'];
            }
            $conversation->lead = array_merge($conversation->lead ?? [], $lead);
            $conversation->save();
            $this->rememberContact($conversation, $lead);

            return ['result' => 'saved'];
        }
        if ($name === 'end_call') {
            $conversation->end_reason = Str::limit((string) ($args['reason'] ?? 'done'), 30, '');
            $conversation->save();

            return ['result' => 'ok'];
        }

        return ['result' => 'unknown_tool'];
    }

    /**
     * @param  array<int, array{role?: string, text?: string}>  $turns
     * @param  array<string, mixed>|null  $usage  the token counts the call reported
     */
    public function end(AgentConversation $conversation, array $turns = [], ?array $usage = null, string $reason = 'done'): AgentConversation
    {
        $this->appendTurns($conversation, $turns);
        if ($usage !== null) {
            $conversation->usage = $usage;
            $conversation->cost_usd = self::costOf($usage);
        }
        // Kept before settling, which reads the call afresh under its lock.
        $conversation->save();
        $seconds = (int) min(
            max(0, $conversation->started_at->diffInSeconds(now())),
            (int) ($conversation->reservation['seconds'] ?? 0),
        );

        return $this->settle($conversation, $conversation->end_reason ?: $reason, $seconds);
    }

    /** Calls whose browser stopped saying they are on: charged up to the last time it did. */
    public function settleStale(): int
    {
        $count = 0;
        AgentConversation::query()->where('status', AgentConversation::LIVE)
            ->where('last_seen_at', '<', now()->subSeconds(self::PING_GRACE_SECONDS))
            ->orderBy('id')->limit(200)->get()
            ->each(function (AgentConversation $c) use (&$count): void {
                $seconds = (int) min(
                    max(0, $c->started_at->diffInSeconds($c->last_seen_at ?? $c->started_at)),
                    (int) ($c->reservation['seconds'] ?? 0),
                );
                $this->settle($c, 'left', $seconds);
                $count++;
            });

        return $count;
    }

    public function secondsLeft(AgentConversation $conversation): int
    {
        return max(0, (int) ($conversation->reservation['seconds'] ?? 0) - (int) $conversation->started_at->diffInSeconds(now()));
    }

    /** Charges $seconds of the hold and gives the rest back, once (under a lock). */
    protected function settle(AgentConversation $conversation, string $reason, int $seconds): AgentConversation
    {
        return DB::transaction(function () use ($conversation, $reason, $seconds): AgentConversation {
            $c = AgentConversation::query()->lockForUpdate()->find($conversation->id);
            if (! $c || $c->settled_at !== null) {
                return $c ?? $conversation;
            }
            $reservation = $c->reservation ?? [];
            $seconds = max(0, min($seconds, (int) ($reservation['seconds'] ?? 0)));
            $credits = $c->free ? 0 : min((int) ($reservation['credits'] ?? 0), self::creditsFor($seconds));
            if (! $c->free && isset($reservation['period'])) {
                try {
                    $this->meter->giveBackPart((int) $c->user_id, $reservation,
                        (int) ($reservation['credits'] ?? 0) - $credits,
                        (int) ($reservation['seconds'] ?? 0) - $seconds);
                } catch (\Throwable $e) {
                    Log::error('Agent call hold was not given back.', ['conversation' => $c->id, 'error' => $e->getMessage()]);
                }
            }
            $c->fill([
                'status' => $reason === 'failed' ? AgentConversation::FAILED : AgentConversation::ENDED,
                'end_reason' => $c->end_reason ?: $reason,
                'ended_at' => now(),
                'duration_seconds' => $seconds,
                'billed_seconds' => $seconds,
                'credits' => $credits,
                'settled_at' => now(),
            ])->save();

            return $c;
        }, 3);
    }

    /** @param  array<int, array{role?: string, text?: string}>  $turns */
    protected function appendTurns(AgentConversation $conversation, array $turns): void
    {
        $transcript = $conversation->transcript ?? [];
        foreach (array_slice($turns, 0, 60) as $turn) {
            $text = Str::limit(trim(strip_tags((string) ($turn['text'] ?? ''))), 2000, '');
            $role = ($turn['role'] ?? '') === 'agent' ? 'agent' : 'caller';
            if ($text === '') {
                continue;
            }
            $transcript[] = ['role' => $role, 'text' => $text, 'at' => (int) $conversation->started_at->diffInSeconds(now())];
        }
        $conversation->transcript = array_slice($transcript, -400);
    }

    /** @param  array<string, string>  $lead */
    protected function rememberContact(AgentConversation $conversation, array $lead): void
    {
        $match = AgentContact::query()->where('user_id', $conversation->user_id);
        if (($lead['phone'] ?? '') !== '') {
            $match->where('phone', $lead['phone']);
        } else {
            $match->where('email', $lead['email']);
        }
        $contact = $match->first() ?? new AgentContact(['user_id' => $conversation->user_id]);
        $contact->fill(array_filter([
            'agent_id' => $conversation->agent_id,
            'agent_conversation_id' => $conversation->id,
            'name' => $lead['name'] ?? $contact->name,
            'phone' => $lead['phone'] ?? $contact->phone,
            'email' => $lead['email'] ?? $contact->email,
            'note' => $lead['note'] ?? $contact->note,
            'last_contact_at' => now(),
        ], fn ($v) => $v !== null))->save();
    }

    /**
     * What a call cost at Google's prices, from the token counts it reported.
     *
     * @param  array<string, mixed>  $usage
     */
    public static function costOf(array $usage): float
    {
        $price = (array) config('listen.agents.price_per_million', []);
        $in = ['TEXT' => 0, 'AUDIO' => 0];
        foreach ((array) ($usage['promptTokensDetails'] ?? []) as $d) {
            $in[strtoupper((string) ($d['modality'] ?? 'TEXT'))] = ($in[strtoupper((string) ($d['modality'] ?? 'TEXT'))] ?? 0) + (int) ($d['tokenCount'] ?? 0);
        }
        $outAudio = 0;
        foreach ((array) ($usage['responseTokensDetails'] ?? []) as $d) {
            if (strtoupper((string) ($d['modality'] ?? '')) === 'AUDIO') {
                $outAudio += (int) ($d['tokenCount'] ?? 0);
            }
        }
        $thoughts = (int) ($usage['thoughtsTokenCount'] ?? 0);

        return round(
            ($in['TEXT'] ?? 0) * ($price['text_in'] ?? 0.75) / 1e6
            + ($in['AUDIO'] ?? 0) * ($price['audio_in'] ?? 3.0) / 1e6
            + $outAudio * ($price['audio_out'] ?? 12.0) / 1e6
            + $thoughts * ($price['text_out'] ?? 4.5) / 1e6,
            5,
        );
    }
}
