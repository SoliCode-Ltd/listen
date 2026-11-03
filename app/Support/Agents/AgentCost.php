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

    public static function typical(): float
    {
        return self::perMinute(self::TYPICAL['minutes'], self::TYPICAL['instructions']);
    }

    public static function heavy(): float
    {
        return self::perMinute(self::HEAVY['minutes'], self::HEAVY['instructions']);
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

        return $plans->map(function (Plan $plan) use ($rate, $typical, $heavy): array {
            $credits = max(0, (int) $plan->monthly_chars);
            $price = ((int) $plan->price_cents) / 100;
            $minutes = intdiv($credits, $rate);
            $earns = $minutes > 0 ? $price / ($credits / $rate) : 0.0;
            $offer = $plan->hasActiveDiscount() ? ($plan->getDiscountedPriceCents() / 100) / max(1, $credits / $rate) : null;
            $margin = fn (float $cost): ?int => $earns > 0 ? (int) round(($earns - $cost) / $earns * 100) : null;

            return [
                'plan' => (string) $plan->name,
                'price' => $price,
                'credits' => $credits,
                'minutes' => $minutes,
                'earns' => round($earns, 3),
                'earns_offer' => $offer !== null ? round($offer, 3) : null,
                'typical_margin' => $margin($typical),
                'heavy_margin' => $margin($heavy),
                'included' => AgentAccess::planIncludes($plan),
            ];
        })->values()->all();
    }
}
