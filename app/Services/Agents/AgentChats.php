<?php

namespace App\Services\Agents;

use App\Jobs\SummarizeAgentCall;
use App\Models\Agent;
use App\Models\AgentConversation;
use App\Models\AgentWidget;
use App\Services\Billing\MemberUsageMeter;
use App\Services\PlanAccessService;
use App\Support\Agents\AgentAccess;
use App\Support\Agents\AgentChannels;
use App\Support\Agents\AgentCost;
use App\Support\Agents\AgentInstructions;
use App\Support\Agents\CardNumbers;
use App\Support\Agents\HouseAgent;
use App\Support\CreditPeriod;
use App\Support\UsageSnapshot;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A written chat with an agent, from its first message to settled
 * (App\Http\Controllers\AgentChatController). It is an AgentConversation of channel "chat",
 * open (never "live": the calls' lines, clocks and clean-up do not see it) until it is closed.
 *
 * - start(): the owner's plan holds the credits of the replies one conversation may have
 *   (MemberUsageMeter::reserveCredits, as a call holds its longest length), fewer when their
 *   credits cover fewer; the visitor's browser gets a chat key of its own.
 * - reply(): the visitor's message is answered by the text model with the agent's chat
 *   instructions, the conversation so far and its tools (save_contact, show_links, look_up).
 *   One reply uses a fixed number of credits (AgentCost::chatRateFor); a message that got no
 *   answer uses none, and a message sent twice is answered, and charged, once.
 * - close(): the replies given are charged and the rest of the hold goes back, once. Chats
 *   nobody wrote in for a while are closed by closeQuiet().
 *
 * Everything is kept on the conversation: every message, each reply's credits, tokens and cost,
 * the details the customer left, and the totals.
 */
class AgentChats
{
    /** A chat's status while it can still be written in. */
    public const OPEN = 'open';

    /** Rounds of tool calls one reply may take before it must answer. */
    private const MAX_ROUNDS = 3;

    /** The conversation the model reads again with each message: its last turns, within this many characters. */
    private const HISTORY_TURNS = 30;

    private const HISTORY_CHARS = 12000;

    public function __construct(
        private GeminiClient $gemini,
        private MemberUsageMeter $meter,
        private PlanAccessService $plans,
        private AgentCalls $calls,
    ) {}

    /** Replies one conversation may have (and the most its hold covers). */
    public static function maxReplies(): int
    {
        return max(4, min(200, (int) config('listen.agents.chat_replies_per_conversation', 40)));
    }

    /** Characters a visitor's message may have. */
    public static function maxChars(): int
    {
        return max(100, min(4000, (int) config('listen.agents.chat_message_chars', 1000)));
    }

    public static function idleMinutes(): int
    {
        return max(2, min(240, (int) config('listen.agents.chat_idle_minutes', 15)));
    }

    /** Credits one reply of this agent uses. */
    public static function creditsPerReply(Agent $agent): int
    {
        return AgentCost::chatRateFor($agent);
    }

    /** Replies the owner's credits can still pay for, at this agent's rate. */
    public function affordableReplies(Agent $agent): int
    {
        $userId = (int) $agent->user_id;
        $quotaPlan = $this->plans->resolveQuotaPlanForUserId($userId);
        $snapshot = UsageSnapshot::for($userId, $quotaPlan, CreditPeriod::counter($userId));

        return (int) floor(max(0, (int) ($snapshot['remaining_chars'] ?? 0)) / max(1, self::creditsPerReply($agent)));
    }

    /**
     * Why this agent cannot chat right now, as its button is drawn (null: it can). The start
     * checks again, under the meter's locks.
     */
    public function unavailable(Agent $agent): ?string
    {
        if (! AgentChannels::chat($agent)) {
            return 'off';
        }
        if (AgentAccess::callsStopped()) {
            return 'stopped';
        }
        if ($agent->setting('house')) {
            return null;
        }
        if ($agent->setting('suspended')) {
            return 'suspended';
        }
        if (! AgentAccess::limitsFor((int) $agent->user_id)['included']) {
            return 'plan';
        }

        return $this->affordableReplies($agent) < 2 ? 'credits' : null;
    }

    /**
     * @param  array{origin?: ?string, page_url?: ?string, visitor?: ?string, country?: ?string, caller?: ?array{email: string, name: ?string}}  $context
     * @return array{ok: bool, error?: string, conversation?: AgentConversation, chat_key?: string, replies?: int}
     */
    public function start(Agent $agent, ?AgentWidget $widget = null, array $context = [], string $channel = 'chat'): array
    {
        $userId = (int) $agent->user_id;
        $house = (bool) $agent->setting('house');
        if (! AgentChannels::chat($agent)) {
            return ['ok' => false, 'error' => 'off'];
        }
        // "Stop all calls now" in Agents → Settings stops the chats too, and an agent the team suspended.
        if (AgentAccess::callsStopped()) {
            return ['ok' => false, 'error' => 'stopped'];
        }
        if (! $house && $agent->setting('suspended')) {
            return ['ok' => false, 'error' => 'suspended'];
        }
        if (! $house && ! AgentAccess::limitsFor($userId)['included']) {
            return ['ok' => false, 'error' => 'plan'];
        }

        $rate = self::creditsPerReply($agent);
        $replies = self::maxReplies();
        $free = false;
        if ($house) {
            // Listen's own agents always answer: paid by the account that owns them when it can
            // pay for the whole conversation, free otherwise, within their own replies a day.
            if ($this->houseRepliesToday() >= self::houseDailyReplies()) {
                return ['ok' => false, 'error' => 'busy'];
            }
            $free = ! AgentAccess::houseCharged() || ! AgentAccess::limitsFor($userId)['included'] || $this->affordableReplies($agent) < $replies;
        } else {
            $affordable = $this->affordableReplies($agent);
            if ($affordable < 2) {
                return ['ok' => false, 'error' => 'credits'];
            }
            $replies = min($replies, $affordable);
        }

        $chatKey = Str::random(40);
        $base = [
            'agent_id' => $agent->id,
            'user_id' => $userId,
            'agent_widget_id' => $widget?->id,
            'call_key_hash' => hash('sha256', $chatKey),
            'channel' => $channel,
            'visitor_hash' => isset($context['visitor']) ? hash('sha256', (string) $context['visitor']) : null,
            'origin' => Str::limit((string) ($context['origin'] ?? ''), 250, '') ?: null,
            'page_url' => Str::limit((string) ($context['page_url'] ?? ''), 490, '') ?: null,
            'country' => $context['country'] ?? null,
            'status' => self::OPEN,
            'started_at' => now(),
            'last_seen_at' => now(),
            'free' => $free,
            'transcript' => [],
            'usage' => ['chat' => true, 'replies' => 0, 'in' => 0, 'out' => 0],
        ];
        if (! empty($context['caller']) && AgentConversation::keepsCaller()) {
            $base['caller'] = $context['caller'];
        }
        $freeChat = fn (): AgentConversation => AgentConversation::create(['free' => true] + $base + [
            'reservation' => ['seconds' => 0, 'credits' => 0, 'free' => true, 'replies' => $replies, 'rate' => 0],
        ]);
        try {
            if ($free) {
                $conversation = $freeChat();
            } else {
                $credits = $replies * $rate;
                $estimate = ['chars' => $credits, 'words' => 0, 'seconds' => 0, 'minutes' => 0, 'credits' => $credits];
                $conversation = $this->meter->reserveCredits($userId, $this->plans->resolveQuotaPlanForUserId($userId), $estimate,
                    fn (array $reservation) => AgentConversation::create($base + [
                        'reservation' => $reservation + ['replies' => $replies, 'rate' => $rate],
                        'period_ym' => $reservation['period'],
                    ]));
            }
        } catch (ValidationException) {
            if (! $house) {
                return ['ok' => false, 'error' => 'credits'];
            }
            $conversation = $freeChat();
        }

        return ['ok' => true, 'conversation' => $conversation, 'chat_key' => $chatKey, 'replies' => $replies];
    }

    public function findByKey(string $publicId, string $chatKey): ?AgentConversation
    {
        $conversation = AgentConversation::where('public_id', $publicId)->first();
        if (! $conversation || ! self::isChat($conversation) || ! hash_equals((string) $conversation->call_key_hash, hash('sha256', $chatKey))) {
            return null;
        }

        return $conversation;
    }

    public static function isChat(AgentConversation $conversation): bool
    {
        return in_array($conversation->channel, ['chat', 'chat_link'], true);
    }

    /** Replies a conversation has had, each one charged once. */
    public static function repliesIn(AgentConversation $conversation): int
    {
        return count(array_filter((array) ($conversation->transcript ?? []), fn ($t): bool => is_array($t) && ($t['role'] ?? '') === 'agent' && isset($t['re'])));
    }

    /**
     * The visitor's message, answered. $messageId is the browser's own id for the message: sent
     * again (a lost connection, a second tap), the same answer comes back and nothing more is
     * charged.
     *
     * @return array{ok: bool, error?: string, reply?: string, links?: array<int, array<string, mixed>>, left?: int, again?: bool}
     */
    public function reply(AgentConversation $conversation, string $messageId, string $text): array
    {
        $text = self::clean($text, self::maxChars());
        $messageId = Str::limit(preg_replace('/[^A-Za-z0-9_-]/', '', $messageId) ?? '', 40, '');
        if ($text === '' || $messageId === '') {
            return ['ok' => false, 'error' => 'empty'];
        }
        // One message at a time in one conversation, so two never share a reply or its charge.
        $lock = Cache::lock('agent-chat:'.$conversation->id, 60);
        try {
            if (! $lock->block(25)) {
                return ['ok' => false, 'error' => 'busy'];
            }
        } catch (\Throwable) {
            return ['ok' => false, 'error' => 'busy'];
        }
        try {
            $conversation->refresh();
            $transcript = array_values(array_filter((array) ($conversation->transcript ?? []), 'is_array'));
            foreach ($transcript as $i => $turn) {
                if (($turn['role'] ?? '') === 'agent' && ($turn['re'] ?? null) === $messageId) {
                    $links = isset($transcript[$i - 1]) && ($transcript[$i - 1]['kind'] ?? '') === 'links' ? (array) ($transcript[$i - 1]['links'] ?? []) : [];

                    return ['ok' => true, 'reply' => (string) $turn['text'], 'links' => $links, 'left' => $this->left($conversation), 'again' => true];
                }
            }
            if ($conversation->status !== self::OPEN) {
                return ['ok' => false, 'error' => 'closed'];
            }
            $agent = $conversation->agent;
            if (! $agent || $agent->trashed() || ! AgentChannels::chat($agent)) {
                return ['ok' => false, 'error' => 'off'];
            }
            if ($this->left($conversation) < 1) {
                return ['ok' => false, 'error' => 'limit'];
            }

            $afterCall = isset($conversation->reservation['after_call']) ? AgentConversation::find((int) $conversation->reservation['after_call']) : null;
            $answer = $this->answer($agent, $conversation, $transcript, $text, $afterCall);
            if ($answer === null) {
                // Nothing was said, so nothing is kept or charged: the same message can be sent again.
                return ['ok' => false, 'error' => 'failed'];
            }

            $conversation->refresh();
            $transcript = array_values(array_filter((array) ($conversation->transcript ?? []), 'is_array'));
            $at = (int) $conversation->started_at->diffInSeconds(now());
            $rate = $conversation->free ? 0 : (int) ($conversation->reservation['rate'] ?? 0);
            $cost = AgentCost::replyCost($answer['in'], $answer['out']);
            $transcript[] = ['role' => 'caller', 'text' => $text, 'at' => $at, 'id' => $messageId];
            if ($answer['links'] !== []) {
                $transcript[] = ['role' => 'agent', 'kind' => 'links', 'text' => implode("\n", array_column($answer['links'], 'url')), 'links' => $answer['links'], 'at' => $at];
            }
            $transcript[] = ['role' => 'agent', 'text' => $answer['text'], 'at' => $at, 're' => $messageId,
                'credits' => $rate, 'tokens' => ['in' => $answer['in'], 'out' => $answer['out']], 'cost' => $cost, 'model' => $answer['model']];
            $usage = (array) ($conversation->usage ?? []);
            $replies = (int) ($usage['replies'] ?? 0) + 1;
            $conversation->forceFill([
                'transcript' => array_slice($transcript, -400),
                'usage' => ['chat' => true, 'replies' => $replies, 'in' => (int) ($usage['in'] ?? 0) + $answer['in'], 'out' => (int) ($usage['out'] ?? 0) + $answer['out']],
                'cost_usd' => round((float) ($conversation->cost_usd ?? 0) + $cost, 5),
                // What the chat has used so far; close() writes the final number.
                'credits' => $replies * $rate,
                'last_seen_at' => now(),
            ])->save();

            return ['ok' => true, 'reply' => $answer['text'], 'links' => $answer['links'], 'left' => $this->left($conversation)];
        } finally {
            rescue(fn () => $lock->release(), null, false);
        }
    }

    /** Replies this conversation may still have (its hold, less what it has had). */
    public function left(AgentConversation $conversation): int
    {
        return max(0, (int) ($conversation->reservation['replies'] ?? 0) - self::repliesIn($conversation));
    }

    /**
     * What the model answers: its words, the links it asked to show, and the tokens every round
     * took. Null when it gave no words in time.
     *
     * @param  array<int, array<string, mixed>>  $transcript
     * @return array{text: string, links: array<int, array<string, mixed>>, in: int, out: int, model: string}|null
     */
    private function answer(Agent $agent, AgentConversation $conversation, array $transcript, string $text, ?AgentConversation $afterCall): ?array
    {
        $system = AgentInstructions::build($agent, true);
        $contents = $this->history($transcript, $afterCall);
        $contents[] = ['role' => 'user', 'parts' => [['text' => $text]]];
        // The call's tools without the hang-up: a chat is never ended by the agent.
        $tools = array_values(array_filter(LiveSession::tools($agent), fn (array $tool): bool => $tool['name'] !== 'end_call'));

        $in = 0;
        $out = 0;
        $links = [];
        $model = '';
        $deadline = microtime(true) + 40;
        for ($round = 0; $round < self::MAX_ROUNDS; $round++) {
            $left = (int) floor($deadline - microtime(true));
            if ($left < 5) {
                break;
            }
            // The last round has no tools: it must answer in words.
            $result = $this->gemini->converse($system, $contents, $round === self::MAX_ROUNDS - 1 ? [] : $tools, min(25, $left));
            if ($result === null) {
                break;
            }
            $in += $result['usage']['in'];
            $out += $result['usage']['out'] + $result['usage']['thoughts'];
            $model = $result['model'];
            if ($result['calls'] === []) {
                $said = self::tidy($result['text']);

                return $said === '' ? null : ['text' => $said, 'links' => $links, 'in' => $in, 'out' => $out, 'model' => $model];
            }
            $contents[] = ['role' => 'model', 'parts' => $result['parts']];
            $answers = [];
            foreach (array_slice($result['calls'], 0, 4) as $call) {
                $heard = rescue(fn () => $this->calls->tool($conversation, $call['name'], $call['args']), ['result' => 'error'], true);
                if ($call['name'] === 'show_links' && ! empty($heard['links'])) {
                    $links = array_values((array) $heard['links']);
                    // The model hears that they were written, never the addresses.
                    unset($heard['links']);
                }
                $answers[] = ['functionResponse' => ['name' => $call['name'], 'response' => $heard]];
            }
            $contents[] = ['role' => 'user', 'parts' => $answers];
        }
        if ($in + $out > 0) {
            Log::warning('Agent chat gave no words.', ['conversation' => $conversation->id, 'tokens_in' => $in, 'tokens_out' => $out]);
        }

        return null;
    }

    /**
     * The conversation so far, as the model reads it: the last turns, the oldest dropped first.
     * A voice call the visitor had just before (the same visit) comes first, as what was said.
     *
     * @param  array<int, array<string, mixed>>  $transcript
     * @return array<int, array{role: string, parts: array<int, array<string, mixed>>}>
     */
    private function history(array $transcript, ?AgentConversation $afterCall): array
    {
        $turns = [];
        if ($afterCall !== null) {
            foreach (array_slice(array_values(array_filter((array) ($afterCall->transcript ?? []), 'is_array')), -12) as $turn) {
                $turns[] = $turn + ['spoken' => true];
            }
        }
        foreach ($transcript as $turn) {
            $turns[] = $turn;
        }
        $turns = array_values(array_filter($turns, fn (array $t): bool => ($t['kind'] ?? '') !== 'links' && trim((string) ($t['text'] ?? '')) !== ''));
        $turns = array_slice($turns, -self::HISTORY_TURNS);
        $size = 0;
        $kept = [];
        foreach (array_reverse($turns) as $turn) {
            $size += mb_strlen((string) $turn['text']);
            if ($size > self::HISTORY_CHARS && $kept !== []) {
                break;
            }
            array_unshift($kept, $turn);
        }
        $contents = [];
        foreach ($kept as $turn) {
            $role = ($turn['role'] ?? '') === 'agent' ? 'model' : 'user';
            $words = (string) $turn['text'];
            if (! empty($turn['spoken']) && $role === 'user') {
                $words = '(Said on the voice call just before this chat) '.$words;
            }
            // Two turns of the same side in a row are one turn to the model.
            if ($contents !== [] && $contents[array_key_last($contents)]['role'] === $role) {
                $contents[array_key_last($contents)]['parts'][0]['text'] .= "\n".$words;
            } else {
                $contents[] = ['role' => $role, 'parts' => [['text' => $words]]];
            }
        }
        // The model's turn never comes first.
        while ($contents !== [] && $contents[0]['role'] === 'model') {
            array_shift($contents);
        }

        return $contents;
    }

    /**
     * The chat's last lines as a call that follows it is told them (LiveSession::setupFor): who
     * wrote what, within a couple of thousand characters.
     */
    public static function spokenBefore(AgentConversation $conversation): string
    {
        $lines = [];
        foreach (array_slice(array_values(array_filter((array) ($conversation->transcript ?? []), 'is_array')), -14) as $turn) {
            if (($turn['kind'] ?? '') === 'links' || trim((string) ($turn['text'] ?? '')) === '') {
                continue;
            }
            $lines[] = (($turn['role'] ?? '') === 'agent' ? 'You: ' : 'Caller: ').Str::limit(trim((string) $turn['text']), 400, '…');
        }

        return Str::limit(implode("\n", $lines), 2400, '…');
    }

    /** A voice call of the same visit, kept with the chat that follows it: its words are context. */
    public function comesAfter(AgentConversation $chat, AgentConversation $call): void
    {
        if (! self::isChat($chat) || self::isChat($call) || (int) $call->agent_id !== (int) $chat->agent_id || isset($chat->reservation['after_call'])) {
            return;
        }
        $chat->reservation = ((array) $chat->reservation) + ['after_call' => $call->id];
        $chat->save();
    }

    /**
     * The chat is over: the replies given are charged, the rest of the hold goes back, once.
     */
    public function close(AgentConversation $conversation, string $reason = 'idle'): AgentConversation
    {
        $closed = DB::transaction(function () use ($conversation, $reason): ?AgentConversation {
            $c = AgentConversation::query()->lockForUpdate()->find($conversation->id);
            if (! $c || $c->settled_at !== null || ! self::isChat($c)) {
                return null;
            }
            $reservation = (array) ($c->reservation ?? []);
            $replies = self::repliesIn($c);
            $held = (int) ($reservation['credits'] ?? 0);
            $credits = $c->free ? 0 : min($held, $replies * (int) ($reservation['rate'] ?? 0));
            if (! $c->free && isset($reservation['period']) && $held > $credits) {
                try {
                    $this->meter->giveBackPart((int) $c->user_id, $reservation, $held - $credits, 0);
                } catch (\Throwable $e) {
                    Log::error('Agent chat hold was not given back.', ['conversation' => $c->id, 'error' => $e->getMessage()]);
                }
            }
            $c->fill([
                'status' => AgentConversation::ENDED,
                'end_reason' => $c->end_reason ?: $reason,
                'ended_at' => now(),
                'duration_seconds' => (int) max(0, $c->started_at->diffInSeconds($c->last_seen_at ?? now())),
                'billed_seconds' => 0,
                'credits' => $credits,
                'settled_at' => now(),
            ])->save();

            return $c;
        }, 3);
        if ($closed && self::repliesIn($closed) >= 1) {
            rescue(fn () => SummarizeAgentCall::dispatch($closed->id), null, false);
        }

        return $closed ?? $conversation->refresh();
    }

    /** Chats nobody wrote in for a while are closed. Returns how many. */
    public function closeQuiet(): int
    {
        $n = 0;
        AgentConversation::query()->where('status', self::OPEN)
            ->where('last_seen_at', '<', now()->subMinutes(self::idleMinutes()))
            ->orderBy('id')->limit(200)->get()
            ->each(function (AgentConversation $c) use (&$n): void {
                $this->close($c, 'idle');
                $n++;
            });

        return $n;
    }

    /** Replies Listen's own agents gave today, and how many they may give a day. */
    private function houseRepliesToday(): int
    {
        $keys = HouseAgent::agentKeys();
        if ($keys === []) {
            return 0;
        }

        return (int) AgentConversation::query()->whereIn('agent_id', $keys)->whereIn('channel', ['chat', 'chat_link'])
            ->where('started_at', '>=', now()->startOfDay())->get(['usage'])
            ->sum(fn (AgentConversation $c): int => (int) ($c->usage['replies'] ?? 0));
    }

    public static function houseDailyReplies(): int
    {
        return max(50, (int) AgentAccess::setting('agents_house_chat_replies_day', 2000));
    }

    /** A visitor's message as it is kept: plain text, card numbers masked, no longer than allowed. */
    private static function clean(string $text, int $limit): string
    {
        $text = trim(strip_tags(str_replace(["\u{2066}", "\u{2069}", "\0"], '', $text)));
        $text = preg_replace('/[ \t]+/u', ' ', preg_replace('/\R{3,}/u', "\n\n", $text) ?? $text) ?? $text;

        return CardNumbers::mask(Str::limit($text, $limit, ''));
    }

    /** The model's words as the customer reads them: plain text, whatever markup slipped in. */
    private static function tidy(string $text): string
    {
        $text = strip_tags($text);
        $text = preg_replace('/\*\*(.+?)\*\*/us', '$1', $text) ?? $text;
        $text = preg_replace('/^\s{0,3}#{1,6}\s+/um', '', $text) ?? $text;
        $text = preg_replace('/^\s*[\*\-]\s+/um', '- ', $text) ?? $text;
        $text = preg_replace('/\R{3,}/u', "\n\n", $text) ?? $text;

        return CardNumbers::mask(Str::limit(trim($text), 2000, ''));
    }
}
