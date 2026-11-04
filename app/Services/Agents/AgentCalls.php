<?php

namespace App\Services\Agents;

use App\Models\Agent;
use App\Models\AgentContact;
use App\Models\AgentConversation;
use App\Models\AgentWidget;
use App\Services\Billing\MemberUsageMeter;
use App\Services\PlanAccessService;
use App\Support\Agents\AgentAccess;
use App\Support\Agents\AgentCost;
use App\Support\Agents\WaitingLine;
use App\Support\Credits;
use App\Support\CreditPeriod;
use App\Support\UsageSnapshot;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
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

    /** Owner-side reasons a call could not start: the caller only hears that the agent is not available. */
    public const OWNER_REASONS = ['credits', 'minutes', 'daily', 'plan', 'suspended'];

    public static function creditsPerMinute(): int
    {
        return AgentAccess::creditsPerMinute();
    }

    /** Credits for $seconds of calls: the site's rate, or the agent's own when it costs more (AgentCost::rateFor). */
    public static function creditsFor(int $seconds, ?Agent $agent = null): int
    {
        $rate = $agent ? AgentCost::rateFor($agent, $seconds) : self::creditsPerMinute();

        return (int) ceil(max(0, $seconds) * $rate / 60);
    }

    /** Free test seconds the owner has left (their own test calls, all agents). */
    public function freeTestSecondsLeft(int $userId): int
    {
        // A live free call counts its whole hold until it is settled.
        $used = AgentConversation::query()
            ->where('user_id', $userId)->where('channel', 'test')->where('free', true)
            ->get(['billed_seconds', 'reservation', 'settled_at'])
            ->sum(fn (AgentConversation $c) => $c->settled_at ? (int) $c->billed_seconds : (int) ($c->reservation['seconds'] ?? 0));

        return max(0, AgentAccess::freeTestSeconds() - $used);
    }

    /**
     * Seconds of calls since $since: settled calls by what they were billed, live ones by their
     * whole hold (they may still use it). Filtered by owner, agent, free or paid, and channel
     * ('test' for the owners' own test calls, 'calls' for callers' calls).
     */
    public function secondsSince(\DateTimeInterface $since, ?int $userId = null, ?int $agentId = null, ?bool $free = null, ?string $channel = null): int
    {
        return (int) AgentConversation::query()
            ->where('started_at', '>=', $since)
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
            ->when($agentId !== null, fn ($q) => $q->where('agent_id', $agentId))
            ->when($free !== null, fn ($q) => $q->where('free', $free))
            ->when($channel === 'test', fn ($q) => $q->where('channel', 'test'))
            ->when($channel === 'calls', fn ($q) => $q->where('channel', '!=', 'test'))
            ->get(['billed_seconds', 'reservation', 'settled_at'])
            ->sum(fn (AgentConversation $c) => $c->settled_at ? (int) $c->billed_seconds : (int) ($c->reservation['seconds'] ?? 0));
    }

    /** Call seconds an owner has used in their credit period (free calls aside). */
    public function periodSeconds(int $userId): int
    {
        $period = CreditPeriod::current($userId);

        return (int) AgentConversation::query()->where('user_id', $userId)->where('free', false)
            ->where(fn ($q) => $q->where('period_ym', $period['key'])
                ->orWhere(fn ($q) => $q->whereNull('period_ym')->where('started_at', '>=', $period['starts_at'])))
            ->get(['billed_seconds', 'reservation', 'settled_at'])
            ->sum(fn (AgentConversation $c) => $c->settled_at ? (int) $c->billed_seconds : (int) ($c->reservation['seconds'] ?? 0));
    }

    /** Calls on now across all of an owner's agents (a call that stopped reporting counts until it could have ended). */
    /** The owner's start lock, held (null when the cache cannot lock, or another start keeps it too long). */
    private function startLock(int $userId): ?\Illuminate\Contracts\Cache\Lock
    {
        try {
            $lock = Cache::lock('agent-call-start:'.$userId, 10);

            return $lock->block(5) ? $lock : null;
        } catch (\BadMethodCallException|\Illuminate\Contracts\Cache\LockTimeoutException) {
            return null;
        }
    }

    public function liveCalls(int $userId): int
    {
        $longest = AgentAccess::MAX_CALL_MINUTES * 60 + 30;

        return AgentConversation::query()->where('user_id', $userId)->where('status', AgentConversation::LIVE)
            ->where('channel', '!=', 'test')->where('started_at', '>', now()->subSeconds($longest))->count();
    }

    /**
     * Why the owner's own test call would be refused right now, before they press the button:
     * 'stopped', 'suspended', 'plan' (free test seconds used and no agents on the plan),
     * 'minutes' (the plan's call minutes for the month used) or 'credits'; null when it can start.
     * The same checks start() makes for a test call, leaving out the site's passing limits.
     */
    public function testBlock(Agent $agent): ?string
    {
        $userId = (int) $agent->user_id;
        if (AgentAccess::callsStopped()) {
            return 'stopped';
        }
        if ($agent->setting('suspended')) {
            return 'suspended';
        }
        if ($this->freeTestSecondsLeft($userId) >= 20) {
            return null;
        }
        $limits = AgentAccess::limitsFor($userId);
        if (! $limits['included']) {
            return 'plan';
        }
        if ($limits['minutes'] > 0 && $limits['minutes'] * 60 - $this->periodSeconds($userId) < 30) {
            return 'minutes';
        }
        $quotaPlan = $this->plans->resolveQuotaPlanForUserId($userId);

        return $this->affordableSeconds($userId, $quotaPlan, $agent, 60) < 30 ? 'credits' : null;
    }

    /**
     * @param  array{origin?: ?string, page_url?: ?string, visitor?: ?string, country?: ?string, network?: ?string, free_only?: bool, caller?: ?array{email: string, name: ?string}}  $context
     * @return array{ok: bool, error?: string, conversation?: AgentConversation, call_key?: string, token?: string, max_seconds?: int, free?: bool}
     */
    public function start(Agent $agent, string $channel, ?AgentWidget $widget = null, array $context = []): array
    {
        $userId = (int) $agent->user_id;
        $house = (bool) $agent->setting('house');
        // "Stop all calls now" in Agents → Settings, and an agent the team suspended.
        if (AgentAccess::callsStopped()) {
            return ['ok' => false, 'error' => 'stopped'];
        }
        if (! $house && $agent->setting('suspended')) {
            return $this->refused($agent, $channel, 'suspended');
        }
        $limits = $house ? AgentAccess::limits(null) : AgentAccess::limitsFor($userId);
        $max = max(30, min((int) $agent->max_call_seconds, $limits['call_seconds']));

        $free = false;
        $today = now()->startOfDay();
        // Listen's own agents (HouseAgent): their calls are on Listen, short, and within their own
        // daily budget, so a flood on the public pages never stops the members' agents.
        if ($house) {
            $free = true;
            $site = AgentAccess::houseDailyMinutes() * 60 - $this->secondsSince($today, free: true, channel: 'calls');
            if ($site < 20) {
                return ['ok' => false, 'error' => 'busy'];
            }
            $max = min($max, \App\Support\Agents\HouseAgent::MAX_SECONDS, $site);
        } elseif ($channel === 'test') {
            $left = $this->freeTestSecondsLeft($userId);
            // The demo page's calls (DemoAgent) only ever use the free test seconds, never credits.
            if (! empty($context['free_only']) && $left < 20) {
                return ['ok' => false, 'error' => 'free_used'];
            }
            if ($left >= 20) {
                // Free test calls have a daily budget for the whole site, so accounts made only for
                // the free minutes cannot run up the bill.
                $site = AgentAccess::freeDailyMinutes() * 60 - $this->secondsSince($today, free: true, channel: 'test');
                if ($site < 20) {
                    return ['ok' => false, 'error' => 'busy'];
                }
                $free = true;
                $max = min($max, $left, $site);
            } elseif (! $limits['included']) {
                return ['ok' => false, 'error' => 'plan'];
            }
        } elseif (! $limits['included']) {
            // A plan without agents: the agent can be built and tested, not called.
            return $this->refused($agent, $channel, 'plan');
        }

        // One start at a time for an owner's callers, from the calls-at-once check until the call is
        // kept, so callers arriving together never pass the plan's number between them.
        $lock = $house || $channel === 'test' ? null : $this->startLock($userId);
        try {
            if (! $house) {
                // The members' calls together, a day (Agents → Settings): a ceiling on what the site spends.
                if (($siteMinutes = AgentAccess::siteDailyMinutes()) > 0) {
                    $site = $siteMinutes * 60 - $this->secondsSince($today, free: false);
                    if ($site < 30) {
                        return ['ok' => false, 'error' => 'busy'];
                    }
                    $max = min($max, $site);
                }
                // Calls at the same time across all the owner's agents (their plan's number; one agent
                // can use them all). A caller who finds them all taken waits in line, and the first to
                // wait gets the next free one (WaitingLine).
                if ($channel !== 'test') {
                    $waiter = WaitingLine::waiter($context['visitor'] ?? null);
                    $line = new WaitingLine($userId);
                    if ($this->liveCalls($userId) + $line->ahead($waiter) >= $limits['concurrent']) {
                        return $this->linesBusy($agent, $channel, $line, $waiter);
                    }
                    if ($waiter !== null) {
                        $line->leave($waiter);
                    }
                }
            }

            $quotaPlan = $free ? null : $this->plans->resolveQuotaPlanForUserId($userId);
            if (! $free) {
                // The plan's call minutes a month (0: only the credits limit them).
                if ($limits['minutes'] > 0) {
                    $left = $limits['minutes'] * 60 - $this->periodSeconds($userId);
                    if ($left < 30) {
                        return $this->refused($agent, $channel, 'minutes');
                    }
                    $max = min($max, $left);
                }
                // The owner's own daily limit on this agent (its Setup tab), for callers.
                $daily = (int) $agent->setting('daily_minutes', 0);
                if ($daily > 0 && $channel !== 'test') {
                    $left = $daily * 60 - $this->secondsSince($today, agentId: $agent->id, free: false, channel: 'calls');
                    if ($left < 30) {
                        return $this->refused($agent, $channel, 'daily');
                    }
                    $max = min($max, $left);
                }
                $affordable = $this->affordableSeconds($userId, $quotaPlan, $agent, $max);
                if ($affordable < 30) {
                    return $this->refused($agent, $channel, 'credits');
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
            // Who is calling, when the website signed its signed-in visitor (CallerIdentity).
            if (! empty($context['caller']) && AgentConversation::keepsCaller()) {
                $base['caller'] = $context['caller'];
            }

            try {
                if ($free) {
                    $conversation = AgentConversation::create($base + [
                        'reservation' => ['seconds' => $max, 'credits' => 0, 'free' => true],
                    ]);
                } else {
                    $estimate = ['chars' => $max * Credits::CHARS_PER_SECOND, 'words' => 0, 'seconds' => $max,
                        'minutes' => (int) ceil($max / 60), 'credits' => self::creditsFor($max, $agent)];
                    $conversation = $this->meter->reserveCredits($userId, $quotaPlan, $estimate,
                        fn (array $reservation) => AgentConversation::create($base + [
                            'reservation' => $reservation,
                            'period_ym' => $reservation['period'],
                        ]));
                }
            } catch (ValidationException) {
                return ['ok' => false, 'error' => 'credits'];
            }
        } finally {
            $lock?->release();
        }

        $key = $this->gemini->liveToken(LiveSession::setupFor($agent), $max + 20);
        if ($key === null) {
            $this->settle($conversation, 'failed', 0);

            return ['ok' => false, 'error' => 'busy'];
        }
        // The caller's network, kept with the call for a while: a caller who abuses the agent or
        // keeps leaving calls hanging is stopped on the whole network, not just one browser id.
        if (! empty($context['network'])) {
            Cache::put('agent-call-net:'.$conversation->id, hash('sha256', (string) $context['network']), now()->addHours(2));
        }

        if ($widget) {
            $widget->forceFill(['last_seen_at' => now(), 'last_seen_origin' => $base['origin']])->save();
        }
        // A caller who waited for a line (or tried a little earlier) got through: not a missed call.
        if ($channel !== 'test' && ($waiter = WaitingLine::waiter($context['visitor'] ?? null)) !== null
            && Cache::pull('agent-missed-by:'.$agent->id.':'.$waiter)) {
            $this->unmissed($agent);
        }

        return ['ok' => true, 'conversation' => $conversation, 'call_key' => $callKey, 'token' => $key['token'], 'max_seconds' => $max, 'free' => $free];
    }

    /**
     * Every line on the owner's plan is taken: the caller waits in line (the call button tries
     * again every few seconds) and may leave their number instead (callBack()). A caller counts
     * once as a missed call, and no longer once they get through.
     *
     * @return array{ok: false, error: string, position: ?int, retry_after: int}
     */
    protected function linesBusy(Agent $agent, string $channel, WaitingLine $line, ?string $waiter): array
    {
        $position = null;
        if ($waiter !== null) {
            $position = $line->join($waiter, $agent->id)['position'];
            if (Cache::add('agent-missed-by:'.$agent->id.':'.$waiter, true, now()->addMinutes(30))) {
                $this->refused($agent, $channel, 'lines');
            }
            Cache::put('agent-callback-ok:'.$agent->id.':'.$waiter, true, now()->addMinutes(30));
        }

        return ['ok' => false, 'error' => 'lines', 'position' => $position, 'retry_after' => WaitingLine::RETRY_SECONDS];
    }

    /**
     * A caller who found every line busy leaves their name and number, so the business calls them
     * back: they are among the agent's customers (the Customers tab, newest first) with a note that
     * they asked for it, and the agent's page says how many did today. Only a caller who was waiting
     * for a line on this agent can, a few times an hour.
     *
     * @param  array{visitor?: ?string, name?: ?string, phone: string}  $caller
     * @return array{ok: bool, error?: string}
     */
    public function callBack(Agent $agent, array $caller): array
    {
        $waiter = WaitingLine::waiter($caller['visitor'] ?? null);
        if ($waiter === null || ! Cache::has('agent-callback-ok:'.$agent->id.':'.$waiter)) {
            return ['ok' => false, 'error' => 'not_waiting'];
        }
        // A few an hour from one caller, and a ceiling for the agent, so a flood never fills its customers.
        $limits = ['agent-callback:'.$agent->id.':'.$waiter => 3, 'agent-callbacks:'.$agent->id => 30];
        foreach ($limits as $limit => $most) {
            if (RateLimiter::tooManyAttempts($limit, $most)) {
                return ['ok' => false, 'error' => 'too_many'];
            }
        }
        foreach (array_keys($limits) as $limit) {
            RateLimiter::hit($limit, 3600);
        }
        (new WaitingLine((int) $agent->user_id))->leave($waiter);

        $name = Str::limit(trim(strip_tags((string) ($caller['name'] ?? ''))), 80, '');
        $this->rememberCaller((int) $agent->user_id, $agent->id, null, array_filter([
            'name' => $name !== '' ? $name : null,
            'phone' => $caller['phone'],
            'note' => trans('agents.callback.note', [], $agent->language === 'en' ? 'en' : 'ar'),
        ]));
        $key = 'agent-missed:'.$agent->id;
        $missed = (array) Cache::get($key, []);
        if (($missed['day'] ?? null) === now()->toDateString()) {
            $missed['callbacks'] = (int) ($missed['callbacks'] ?? 0) + 1;
            Cache::put($key, $missed, now()->addDays(3));
        }

        return ['ok' => true];
    }

    /** A phone number as a caller typed it, kept if it has 6 to 15 digits: digits, an optional + first. */
    public static function phoneFrom(mixed $value): ?string
    {
        $raw = strtr(trim((string) $value), ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']);
        if ($raw === '' || preg_match('/[^\d\s\-+().]/', $raw)) {
            return null;
        }
        $digits = preg_replace('/\D/', '', $raw);
        if (strlen($digits) < 6 || strlen($digits) > 15) {
            return null;
        }

        return (str_starts_with(ltrim($raw), '+') ? '+' : '').$digits;
    }

    /** A caller who waited and got through after all: one missed call fewer today. */
    protected function unmissed(Agent $agent): void
    {
        $key = 'agent-missed:'.$agent->id;
        $missed = (array) Cache::get($key, []);
        if (($missed['day'] ?? null) !== now()->toDateString() || (int) ($missed['count'] ?? 0) < 1) {
            return;
        }
        $missed['count'] = (int) $missed['count'] - 1;
        Cache::put($key, $missed, now()->addDays(3));
    }

    /**
     * A call that could not start for a reason on the owner's side. The caller only hears that the
     * agent is not available; the owner sees the missed calls, and why, on their agents' pages.
     *
     * @return array{ok: false, error: string}
     */
    protected function refused(Agent $agent, string $channel, string $reason): array
    {
        if ($channel !== 'test') {
            $key = 'agent-missed:'.$agent->id;
            $missed = (array) Cache::get($key, []);
            $today = now()->toDateString();
            $same = ($missed['day'] ?? null) === $today;
            Cache::put($key, [
                'reason' => $reason, 'at' => now()->toIso8601String(), 'day' => $today,
                'count' => ($same ? (int) ($missed['count'] ?? 0) : 0) + 1,
                // Of them, callers who left a number to be called back (callBack()).
                'callbacks' => $same ? (int) ($missed['callbacks'] ?? 0) : 0,
            ], now()->addDays(3));
        }

        return ['ok' => false, 'error' => $reason];
    }

    /**
     * Callers who could not reach this agent today, and the last reason.
     *
     * @return array{count: int, reason: string, at: \Illuminate\Support\Carbon, callbacks: int}|null
     */
    public static function missedToday(Agent $agent): ?array
    {
        $missed = (array) Cache::get('agent-missed:'.$agent->id, []);
        if (($missed['day'] ?? null) !== now()->toDateString() || (int) ($missed['count'] ?? 0) < 1) {
            return null;
        }

        return [
            'count' => (int) $missed['count'], 'reason' => (string) ($missed['reason'] ?? 'credits'),
            'at' => \Illuminate\Support\Carbon::parse($missed['at'] ?? now()),
            'callbacks' => min((int) $missed['count'], (int) ($missed['callbacks'] ?? 0)),
        ];
    }

    /** Whether this browser, or its network, is kept off this agent for a while (blockCaller()). */
    public static function callerBlocked(int $agentId, string $visitor, string $network): bool
    {
        return Cache::has('agent-block:'.$agentId.':'.hash('sha256', $visitor))
            || Cache::has('agent-block:'.$agentId.':'.hash('sha256', $network));
    }

    /**
     * After a call ended as abusive (the agent warned the caller, then hung up), or the second call
     * in an hour that stopped reporting while it went on: the caller's browser is kept off this agent
     * for a while, and their network too once it happens again from there.
     */
    protected function blockCaller(AgentConversation $c, string $why): void
    {
        if ($c->channel === 'test' || ! $c->visitor_hash) {
            return;
        }
        $hours = $why === 'abusive' ? 24 : 1;
        $strikes = $why === 'abusive' ? 1 : 2;
        $net = Cache::get('agent-call-net:'.$c->id);
        foreach (array_filter([$c->visitor_hash, $net]) as $i => $hash) {
            $key = 'agent-strike:'.$why.':'.$c->agent_id.':'.$hash;
            RateLimiter::hit($key, $hours * 3600);
            // The network (shared by more people) needs one strike more than the browser.
            if (RateLimiter::attempts($key) >= $strikes + $i) {
                Cache::put('agent-block:'.$c->agent_id.':'.$hash, $why, now()->addHours($hours));
            }
        }
    }

    /** Seconds of call the owner's plan can still pay for, at the agents' rate. */
    public function affordableSeconds(int $userId, $quotaPlan, ?Agent $agent = null, int $longest = 600): int
    {
        $snapshot = UsageSnapshot::for($userId, $quotaPlan, CreditPeriod::counter($userId));
        $remaining = max(0, (int) ($snapshot['remaining_chars'] ?? 0));
        $rate = $agent ? AgentCost::rateFor($agent, $longest) : self::creditsPerMinute();

        return (int) floor($remaining * 60 / max(1, $rate));
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
            $clean = fn ($v, int $max) => \App\Support\Agents\CardNumbers::mask(Str::limit(trim(strip_tags((string) $v)), $max, ''));
            $lead = array_filter([
                'name' => $clean($args['name'] ?? '', 120),
                'phone' => $clean(preg_replace('/[^\d+]/', '', (string) ($args['phone'] ?? '')), 40),
                'email' => filter_var(trim((string) ($args['email'] ?? '')), FILTER_VALIDATE_EMAIL) ?: '',
                'note' => $clean($args['note'] ?? '', 500),
            ], fn ($v) => $v !== '' && $v !== null);
            if (($lead['phone'] ?? '') === '' && ($lead['email'] ?? '') === '') {
                return ['result' => 'missing', 'message' => 'Ask for a phone number or an email first.'];
            }
            // A phone number is 7 to 15 digits (with its country code): anything else was misheard.
            $digits = strlen(preg_replace('/\D/', '', (string) ($lead['phone'] ?? '')));
            if (($lead['phone'] ?? '') !== '' && ($digits < 7 || $digits > 15)) {
                return ['result' => 'invalid_phone', 'message' => 'That number does not look complete. Ask the caller kindly to say it again, then read it back.'];
            }
            // A few saves a call (a correction, an added address); more is someone playing with the tool.
            $savesKey = 'agent-saves:'.$conversation->id;
            Cache::add($savesKey, 0, now()->addHours(2));
            if ((int) Cache::increment($savesKey) > 5) {
                return ['result' => 'enough', 'message' => 'The details are already saved. Do not save again; tell the caller what happens next.'];
            }
            $conversation->lead = array_merge($conversation->lead ?? [], $lead);
            $conversation->save();
            $this->rememberContact($conversation, $lead);

            // What the agent hears back: it tells the caller what happens next, never "it was saved"
            // (a test call said "تم حفظ البيانات" and then asked a question it did not wait for).
            return ['result' => 'saved', 'say' => 'Do not say it was saved. If the order or booking still needs details, ask for them now and save again once you have them; otherwise tell the caller in one short sentence what happens next and ask if there is anything else.'];
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
        // A finished call whose end was already kept stays as it is: its call key cannot rewrite
        // its words or its cost afterwards. (A call settled while its browser was quiet still
        // takes the browser's last words and token counts, once.)
        if ($conversation->settled_at !== null && $conversation->usage !== null) {
            return $conversation;
        }
        $this->appendTurns($conversation, $turns);
        if ($usage !== null && $conversation->usage === null) {
            $conversation->usage = $usage;
            $conversation->cost_usd = self::costOf($usage);
        }
        // Kept before settling, which reads the call afresh under its lock.
        $conversation->save();
        $seconds = (int) min(
            max(0, $conversation->started_at->diffInSeconds(now())),
            (int) ($conversation->reservation['seconds'] ?? 0),
        );
        $settled = $this->settle($conversation, $conversation->end_reason ?: $reason, $seconds);
        if ($settled->end_reason === 'abusive') {
            $this->blockCaller($settled, 'abusive');
        }

        return $settled;
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
                // A call whose browser went quiet while the call could go on: twice in an hour from
                // one caller looks like someone keeping the line without paying for it.
                $this->blockCaller($c, 'stale');
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
            $credits = $c->free ? 0 : min((int) ($reservation['credits'] ?? 0), self::creditsFor($seconds, $c->agent));
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
            $text = \App\Support\Agents\CardNumbers::mask(Str::limit(trim(strip_tags(\App\Support\Agents\SpokenNumbers::withoutMarks((string) ($turn['text'] ?? '')))), 2000, ''));
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
        // A caller the website said is signed in: their email and name go with the details they gave.
        $known = is_array($conversation->caller) ? $conversation->caller : [];
        $lead += array_filter(['name' => $known['name'] ?? null, 'email' => $known['email'] ?? null],
            fn ($v) => is_string($v) && $v !== '');
        $this->rememberCaller((int) $conversation->user_id, (int) $conversation->agent_id, $conversation->id, $lead);
    }

    /**
     * A customer's details, kept once per phone number (or email) per owner: a second call from the
     * same number brings it to the top with what is new.
     *
     * @param  array<string, string>  $lead
     */
    protected function rememberCaller(int $userId, int $agentId, ?int $conversationId, array $lead): void
    {
        $match = AgentContact::query()->where('user_id', $userId);
        if (($lead['phone'] ?? '') !== '') {
            $match->where('phone', $lead['phone']);
        } else {
            $match->where('email', $lead['email']);
        }
        $contact = $match->first() ?? new AgentContact(['user_id' => $userId]);
        $contact->fill(array_filter([
            'agent_id' => $agentId,
            'agent_conversation_id' => $conversationId,
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
