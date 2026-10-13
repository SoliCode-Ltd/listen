<?php

namespace App\Support;

use App\Models\AudioGeneration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * For the admin, while setting a plan's characters: about how many minutes of
 * audio that is in Arabic and English, and about what it costs us if all of
 * it is used. Not shown to members.
 */
final class PlanCostEstimate
{
    /** Gemini 3.8 Flash TTS bills 25 audio tokens per second of speech (Google's published figure). */
    public const AUDIO_TOKENS_PER_SECOND = 25;

    /** Some developers report 32 tokens per second in practice, so the high estimate uses that. */
    public const AUDIO_TOKENS_PER_SECOND_HIGH = 32;

    /** About how many characters make one text token. */
    public const CHARS_PER_TEXT_TOKEN = 3;

    /** Characters spoken per second (spaces included) when there is no history yet. */
    public const DEFAULT_CHARS_PER_SECOND = 15.0;

    /**
     * Gemini 3.8 Flash TTS list prices in USD per million tokens (standard tier).
     *
     * @return array{audio:float, text:float, until:?string}
     */
    public static function geminiPrices(?Carbon $on = null): array
    {
        $on ??= now();

        return $on->lt(Carbon::create(2027, 1, 1))
            ? ['audio' => 9.0, 'text' => 0.5, 'until' => '2026-12-31']
            : ['audio' => 18.0, 'text' => 1.0, 'until' => null];
    }

    /**
     * Characters spoken per second, measured from finished premium generations
     * in the last 90 days whose audio length was read from the file, by
     * language. Falls back to the estimator's speed.
     *
     * @return array{ar:float, en:float, measured:bool}
     */
    public static function charsPerSecond(): array
    {
        return Cache::remember('plan_cost_estimate:chars_per_second:v2', now()->addHours(6), function (): array {
            $rows = AudioGeneration::query()
                ->where('status', 'done')
                ->where('voice_tier', VoiceTier::HD)
                ->where('billable_chars', '>', 0)
                ->where('duration_seconds', '>', 0)
                // Only lengths read from the audio file: older rows hold a guess from the text.
                ->where('duration_measured', true)
                ->where('created_at', '>=', now()->subDays(90))
                ->selectRaw('target_language as lang, sum(billable_chars) as chars, sum(duration_seconds) as seconds, count(*) as n')
                ->groupBy('target_language')
                ->get()
                ->keyBy('lang');

            $speed = function (string $lang) use ($rows): ?float {
                $row = $rows->get($lang);
                if (! $row || (int) $row->n < 20 || (int) $row->seconds <= 0) {
                    return null; // too little history to trust
                }

                return round((int) $row->chars / (int) $row->seconds, 2);
            };

            $ar = $speed('ar');
            $en = $speed('en');

            return [
                'ar' => $ar ?? self::DEFAULT_CHARS_PER_SECOND,
                'en' => $en ?? self::DEFAULT_CHARS_PER_SECOND,
                'measured' => $ar !== null || $en !== null,
            ];
        });
    }

    /**
     * @return array{minutes_ar:int, minutes_en:int, cost:float, cost_high:float, cost_2027:float, measured:bool}
     */
    public static function forChars(int $chars): array
    {
        $chars = max(0, $chars);
        $speed = self::charsPerSecond();
        $secondsAr = $speed['ar'] > 0 ? $chars / $speed['ar'] : 0;
        $secondsEn = $speed['en'] > 0 ? $chars / $speed['en'] : 0;
        // Cost on the slower of the two languages: the longer audio costs more.
        $seconds = max($secondsAr, $secondsEn);

        $cost = fn (array $price, int $tokensPerSecond): float => round(
            ($seconds * $tokensPerSecond / 1_000_000) * $price['audio']
            + (($chars / self::CHARS_PER_TEXT_TOKEN) / 1_000_000) * $price['text'],
            2,
        );
        $now = self::geminiPrices();
        $later = self::geminiPrices(Carbon::create(2027, 1, 1));

        return [
            'minutes_ar' => (int) round($secondsAr / 60),
            'minutes_en' => (int) round($secondsEn / 60),
            'cost' => $cost($now, self::AUDIO_TOKENS_PER_SECOND),
            'cost_high' => $cost($now, self::AUDIO_TOKENS_PER_SECOND_HIGH),
            'cost_2027' => $cost($later, self::AUDIO_TOKENS_PER_SECOND),
            'measured' => $speed['measured'],
        ];
    }

    /** Card fees taken from each payment (Stripe, standard cards). */
    public const CARD_FEE_PERCENT = 2.9;

    public const CARD_FEE_FIXED = 0.30;

    /** The profit the plan editor asks for when every credit is used. */
    public const TARGET_PROFIT_PERCENT = 30;

    /**
     * What one minute of premium audio costs at the high estimate (32 audio
     * tokens a second) plus the text it was read from.
     */
    public static function costPerMinute(?Carbon $on = null): float
    {
        $price = self::geminiPrices($on);

        return (60 * self::AUDIO_TOKENS_PER_SECOND_HIGH / 1_000_000) * $price['audio']
            + ((Credits::PER_MINUTE / self::CHARS_PER_TEXT_TOKEN) / 1_000_000) * $price['text'];
    }

    /**
     * A plan's worst month: every credit used, so every voice minute made.
     * Costed at the Jan 2027 price so a plan stays profitable after it rises.
     *
     * @return array{minutes:int, cost_now:float, cost:float, net:float, profit:float, percent:float}
     */
    public static function fullUse(int $credits, float $priceDollars): array
    {
        $minutes = Credits::minutesFor($credits);
        $net = $priceDollars > 0 ? $priceDollars * (1 - self::CARD_FEE_PERCENT / 100) - self::CARD_FEE_FIXED : 0.0;
        $cost = $minutes * self::costPerMinute(Carbon::create(2027, 1, 1));
        $profit = $net - $cost;

        return [
            'minutes' => $minutes,
            'cost_now' => round($minutes * self::costPerMinute(), 2),
            'cost' => round($cost, 2),
            'net' => round($net, 2),
            'profit' => round($profit, 2),
            'percent' => $priceDollars > 0 ? round($profit / $priceDollars * 100, 1) : 0.0,
        ];
    }

    /**
     * The line under "Monthly credits" in the plan editor.
     */
    public static function adminLine(int $credits, ?float $priceDollars = null, bool $premium = true): string
    {
        if ($credits <= 0) {
            return 'Set the credits to see the voice minutes and cost.';
        }

        $e = self::fullUse($credits, (float) $priceDollars);
        $line = '= '.number_format($e['minutes']).' voice minutes a month ('.number_format(Credits::PER_MINUTE).' credits a minute).';

        if (! $premium) {
            return $line.' Standard voice: speech cost is small and not estimated here.';
        }

        $line .= ' Most it can cost us if every credit is used: $'.number_format($e['cost_now'], 2).' now, $'.number_format($e['cost'], 2).' from Jan 2027.';

        if ($priceDollars !== null && $priceDollars > 0) {
            $line .= ' Price $'.number_format($priceDollars, 2).' → $'.number_format($e['net'], 2).' after card fees → profit at full use $'
                .number_format($e['profit'], 2).' ('.number_format($e['percent'], 1).'%, Jan 2027 prices).';
            if ($e['percent'] < self::TARGET_PROFIT_PERCENT) {
                $line .= ' ⚠ Below '.self::TARGET_PROFIT_PERCENT.'%: lower the credits or raise the price.';
            }
        }

        return $line;
    }
}
