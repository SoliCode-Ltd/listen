<?php

namespace App\Support\Agents;

use App\Models\AgentConversation;
use App\Models\Plan;

/**
 * What a call minute costs Listen on the live voice, and what each plan earns
 * for one (Admin → Agents → Settings, and the plan form).
 *
 * The live voice bills every turn again for everything it keeps in mind: the
 * agent's instructions and the whole conversation so far (each second of
 * speech, the caller's and the agent's, is about 26 tokens). So a minute costs
 * more on a longer call and for an agent with more to know. The model below
 * replays a call turn by turn, as the recorded test calls were billed; the
 * conversation it keeps is bounded by listen.agents.history_tokens, as calls
 * are (LiveSession).
 */
final class AgentCost
{
    /** Audio tokens a second of speech (measured on the test calls). */
    public const TOKENS_PER_SECOND = 25.8;

    /** Thinking tokens a turn, and transcript text a turn adds (measured). */
    private const THOUGHTS = 110;

    private const TEXT_PER_TURN = 64;

    /** A typical call: 3 minutes, an agent with notes (about 4,000 tokens of instructions). */
    public const TYPICAL = ['minutes' => 3, 'instructions' => 4000];

    /** A heavy call: 10 minutes, an agent with every note and file slot full (about 19,000 tokens). */
    public const HEAVY = ['minutes' => 10, 'instructions' => 19000];

    /** Dollars a call minute costs, for a call this long with instructions this big. */
    public static function perMinute(int $minutes, int $instructionTokens, float $turnsPerMinute = 4.0): float
    {
        $price = (array) config('listen.agents.price_per_million', []);
        $textIn = (float) ($price['text_in'] ?? 0.75);
        $audioIn = (float) ($price['audio_in'] ?? 3.0);
        $textOut = (float) ($price['text_out'] ?? 4.5);
        $audioOut = (float) ($price['audio_out'] ?? 12.0);
        $keep = max(2000, (int) config('listen.agents.history_tokens', 8000));

        $minutes = max(1, $minutes);
        $turns = max(1, (int) round($minutes * $turnsPerMinute));
        $turnSeconds = $minutes * 60 / $turns;
        $audio = 0.0;
        $text = 0.0;
        $cost = 0.0;
        for ($i = 0; $i < $turns; $i++) {
            $audio += self::TOKENS_PER_SECOND * $turnSeconds * 0.35; // the caller speaks
            if ($audio + $text > $keep) {
                // The oldest turns drop out, down to half of what is kept.
                $share = ($keep / 2) / ($audio + $text);
                $audio *= $share;
                $text *= $share;
            }
            $reply = self::TOKENS_PER_SECOND * $turnSeconds * 0.5; // the agent answers
            $cost += (($instructionTokens + $text) * $textIn + $audio * $audioIn + $reply * $audioOut + self::THOUGHTS * $textOut) / 1e6;
            $audio += $reply;
            $text += self::TEXT_PER_TURN;
        }

        return $cost / $minutes;
    }

    /** The least a call may earn over what it costs: 40% of what the member pays for it. */
    public const MARGIN = 0.40;

    /**
     * Credits a dollar of call cost must take, so that a call keeps MARGIN on the plan whose
     * credits cost the least (a yearly price counts with its discount). Null: no paid plan.
     */
    public static function creditsPerDollar(): ?int
    {
        return once(function (): ?int {
            $plans = rescue(fn () => Plan::query()->where('is_active', true)->where('price_cents', '>', 0)->where('monthly_chars', '>', 0)->get(), collect(), false);
            $yearly = max(0, min(90, (int) AgentAccess::setting('billing_yearly_discount_percentage', 0)));
            $cheapest = $plans->map(function (Plan $plan) use ($yearly): float {
                $price = ((int) $plan->price_cents) / 100;
                if (filled($plan->stripe_price_yearly ?? null) && $yearly > 0) {
                    $price *= 1 - $yearly / 100;
                }

                return $price / max(1, (int) $plan->monthly_chars);
            })->filter(fn (float $c) => $c > 0)->min();

            return $cheapest ? (int) ceil(1 / ((1 - self::MARGIN) * $cheapest)) : null;
        });
    }

    /** Tokens of an agent's instructions, as its calls are set up (cached for the request). */
    public static function instructionTokens(\App\Models\Agent $agent): int
    {
        return once(fn (): int => self::tokensIn(AgentInstructions::build($agent)));
    }

    /**
     * Credits a call minute of this agent uses for a call this long: the site's rate, or more for
     * an agent with a great deal to know on a long call, so that no call earns less than MARGIN.
     */
    public static function rateFor(\App\Models\Agent $agent, int $seconds): int
    {
        $base = AgentAccess::creditsPerMinute();
        $perDollar = self::creditsPerDollar();
        if (! $perDollar || ! $agent->exists) {
            return $base;
        }
        $cost = self::perMinute(max(1, (int) ceil($seconds / 60)), self::instructionTokens($agent));

        return max($base, (int) ceil($cost * $perDollar / 10) * 10);
    }

    /**
     * The fewest credits a call minute can use and still keep MARGIN on a typical call on every
     * plan on sale (the plan whose credits cost least sets it, its yearly price counted), rounded
     * up to the next 100 and never under one audio minute. It follows the server's own plans, so
     * the same code gives each server the rate its prices need. Null: no paid plan yet.
     */
    public static function recommendedRate(): ?int
    {
        $perDollar = self::creditsPerDollar();
        if (! $perDollar) {
            return null;
        }

        return max(\App\Support\Credits::PER_MINUTE, (int) (ceil(self::typical() * $perDollar / 100) * 100));
    }

    public static function typical(): float
    {
        return self::perMinute(self::TYPICAL['minutes'], self::TYPICAL['instructions']);
    }

    public static function heavy(): float
    {
        return self::perMinute(self::HEAVY['minutes'], self::HEAVY['instructions']);
    }

    /**
     * The written chat. Around one reply the model reads the agent's instructions, the
     * conversation so far and what a tool found (CHAT_CONTEXT tokens beside the instructions),
     * and writes a short answer after a little thinking (CHAT_OUTPUT). A reply that used a tool
     * is two rounds; the allowance counts one reply in three as such.
     */
    private const CHAT_CONTEXT = 900;

    private const CHAT_OUTPUT = 260;

    private const CHAT_ROUNDS = 1.35;

    /**
     * What the text model charges per million tokens on a day (listen.agents.chat_price_per_million,
     * and the dated changes its price list announces): what it reads, what it writes, and what
     * it reads again from its cache.
     *
     * @return array{in: float, out: float, cached: float}
     */
    public static function chatPrices(?\DateTimeInterface $on = null): array
    {
        $price = (array) config('listen.agents.chat_price_per_million', []);
        $in = (float) ($price['in'] ?? 0.75);
        $out = (float) ($price['out'] ?? 3.75);
        $cached = (float) ($price['cached'] ?? $in / 10);
        $day = \Illuminate\Support\Carbon::instance($on ?? now())->utc()->toDateString();
        $changes = array_values(array_filter((array) config('listen.agents.chat_price_changes', []), fn ($c): bool => is_array($c) && isset($c['from'])));
        usort($changes, fn (array $a, array $b): int => strcmp((string) $a['from'], (string) $b['from']));
        foreach ($changes as $change) {
            if ($day >= (string) $change['from']) {
                $in = (float) ($change['in'] ?? $in);
                $out = (float) ($change['out'] ?? $out);
                $cached = (float) ($change['cached'] ?? $in / 10);
            }
        }

        return ['in' => $in, 'out' => $out, 'cached' => min($cached, $in)];
    }

    /** The next dated change of the text model's prices, if one is announced: ['from' => '2027-01-01', 'in' => …]. */
    public static function nextChatPriceChange(?\DateTimeInterface $on = null): ?array
    {
        $day = \Illuminate\Support\Carbon::instance($on ?? now())->utc()->toDateString();
        $next = collect((array) config('listen.agents.chat_price_changes', []))
            ->filter(fn ($c): bool => is_array($c) && isset($c['from']) && (string) $c['from'] > $day)
            ->sortBy('from')->first();

        return $next ? ['from' => (string) $next['from']] + self::chatPrices(\Illuminate\Support\Carbon::parse((string) $next['from'], 'UTC')) : null;
    }

    /** Dollars one written reply is expected to cost, for instructions this big. */
    public static function perReply(int $instructionTokens, ?\DateTimeInterface $on = null): float
    {
        $price = self::chatPrices($on);

        return self::CHAT_ROUNDS * (($instructionTokens + self::CHAT_CONTEXT) * $price['in'] + self::CHAT_OUTPUT * $price['out']) / 1e6;
    }

    /**
     * Dollars a model call really cost, from the tokens the model counted: what it read (the
     * part it read from its cache at the cache's price) and what it wrote, its thinking included.
     */
    public static function replyCost(int $tokensIn, int $tokensOut, int $cachedIn = 0, ?\DateTimeInterface $on = null): float
    {
        $price = self::chatPrices($on);
        $cachedIn = max(0, min($tokensIn, $cachedIn));

        return round((($tokensIn - $cachedIn) * $price['in'] + $cachedIn * $price['cached'] + $tokensOut * $price['out']) / 1e6, 6);
    }

    /**
     * Dollars a credit earns on the plan whose credits cost the least (its yearly price counted):
     * what a chat's credits are worth at the very least. Null: no paid plan.
     */
    public static function dollarsPerCredit(): ?float
    {
        $perDollar = self::creditsPerDollar();

        return $perDollar ? 1 / ($perDollar * (1 - self::MARGIN)) : null;
    }

    /** Tokens of an agent's instructions for a written chat (cached for the request). */
    public static function chatInstructionTokens(\App\Models\Agent $agent): int
    {
        return once(fn (): int => self::tokensIn(AgentInstructions::build($agent, true)));
    }

    /**
     * The fewest credits a written reply can use and still keep MARGIN on a typical agent on
     * every plan on sale, rounded up to the next 5. Null: no paid plan yet.
     */
    public static function recommendedChatRate(): ?int
    {
        $perDollar = self::creditsPerDollar();

        return $perDollar ? max(5, (int) (ceil(self::perReply(self::TYPICAL['instructions']) * $perDollar / 5) * 5)) : null;
    }

    /**
     * Credits one written reply of this agent uses: the site's rate, or more for an agent with a
     * great deal to know (every reply reads all of it), so that no reply earns less than MARGIN.
     * One fixed number per agent, so its owner always knows what a reply costs.
     */
    public static function chatRateFor(\App\Models\Agent $agent): int
    {
        $base = AgentAccess::chatCreditsPerReply();
        $perDollar = self::creditsPerDollar();
        if (! $perDollar || ! $agent->exists) {
            return $base;
        }

        return max($base, (int) (ceil(self::perReply(self::chatInstructionTokens($agent)) * $perDollar / 5) * 5));
    }

    /**
     * What real written replies cost so far, from the tokens the text model counted.
     *
     * @return array{replies: int, dollars: float, per_reply: ?float}
     */
    public static function measuredChat(int $days = 30): array
    {
        $rows = rescue(fn () => AgentConversation::query()->whereIn('channel', ['chat', 'chat_link'])->whereNotNull('cost_usd')
            ->where('started_at', '>=', now()->subDays($days))->get(['usage', 'cost_usd']), collect(), false);
        $replies = (int) $rows->sum(fn (AgentConversation $c): int => (int) ($c->usage['replies'] ?? 0));
        $dollars = (float) $rows->sum(fn (AgentConversation $c): float => (float) $c->cost_usd);

        return ['replies' => $replies, 'dollars' => round($dollars, 4), 'per_reply' => $replies > 0 ? round($dollars / $replies, 6) : null];
    }

    /** Tokens in an agent's instructions, estimated from the text (Arabic letters take more). */
    public static function tokensIn(string $text): int
    {
        $chars = mb_strlen($text);
        $arabic = (int) preg_match_all('/\p{Arabic}/u', $text);

        return (int) ceil(($chars - $arabic) / 4 + $arabic / 2.6) + 400;
    }

    /**
     * What real calls cost so far, from the token counts they reported.
     *
     * @return array{calls: int, minutes: float, dollars: float, per_minute: ?float}
     */
    public static function measured(int $days = 30): array
    {
        $rows = rescue(fn () => AgentConversation::query()
            ->whereNotNull('cost_usd')->where('billed_seconds', '>', 0)
            ->where('started_at', '>=', now()->subDays($days))
            ->selectRaw('count(*) as n, sum(billed_seconds) as s, sum(cost_usd) as c')->first(), null, false);
        $minutes = $rows ? ((int) $rows->s) / 60 : 0.0;
        $dollars = $rows ? (float) $rows->c : 0.0;

        return [
            'calls' => $rows ? (int) $rows->n : 0,
            'minutes' => round($minutes, 1),
            'dollars' => round($dollars, 4),
            'per_minute' => $minutes >= 1 ? round($dollars / $minutes, 4) : null,
        ];
    }

    /**
     * Each plan on sale: what a call minute earns, against what it costs.
     *
     * @return list<array{plan: string, price: float, credits: int, minutes: int, earns: float, earns_offer: ?float, typical_margin: ?int, heavy_margin: ?int, included: bool}>
     */
    public static function plans(?int $rate = null): array
    {
        $rate ??= AgentAccess::creditsPerMinute();
        $typical = self::typical();
        $heavy = self::heavy();
        $plans = rescue(fn () => Plan::query()->where('is_active', true)->where('price_cents', '>', 0)->orderBy('price_cents')->get(), collect(), false);
        $yearlyOff = max(0, min(90, (int) AgentAccess::setting('billing_yearly_discount_percentage', 0)));

        return $plans->map(function (Plan $plan) use ($rate, $typical, $heavy, $yearlyOff): array {
            $credits = max(0, (int) $plan->monthly_chars);
            $price = ((int) $plan->price_cents) / 100;
            $minutes = intdiv($credits, $rate);
            $earns = $minutes > 0 ? $price / ($credits / $rate) : 0.0;
            // The same minute on the yearly price (its discount), where the plan has one.
            $yearly = filled($plan->stripe_price_yearly ?? null) && $yearlyOff > 0 && $earns > 0 ? $earns * (1 - $yearlyOff / 100) : null;
            $offer = $plan->hasActiveDiscount() ? ($plan->getDiscountedPriceCents() / 100) / max(1, $credits / $rate) : null;
            $margin = fn (float $cost, ?float $on = null): ?int => ($on ??= $earns) > 0 ? (int) round(($on - $cost) / $on * 100) : null;

            return [
                'plan' => (string) $plan->name,
                'price' => $price,
                'credits' => $credits,
                'minutes' => $minutes,
                'earns' => round($earns, 3),
                'earns_yearly' => $yearly !== null ? round($yearly, 3) : null,
                'earns_offer' => $offer !== null ? round($offer, 3) : null,
                'typical_margin' => $margin($typical),
                'typical_margin_yearly' => $yearly !== null ? $margin($typical, $yearly) : null,
                'heavy_margin' => $margin($heavy),
                'included' => AgentAccess::planIncludes($plan),
            ];
        })->values()->all();
    }
}
