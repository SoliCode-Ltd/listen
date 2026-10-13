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

    /** Characters spoken per second when there is no history yet (UsageEstimator's 5 chars x 2.6 words). */
    public const DEFAULT_CHARS_PER_SECOND = 13.0;

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
     * in the last 90 days, by language. Falls back to the estimator's speed.
     *
     * @return array{ar:float, en:float, measured:bool}
     */
    public static function charsPerSecond(): array
    {
        return Cache::remember('plan_cost_estimate:chars_per_second', now()->addHours(6), function (): array {
            $rows = AudioGeneration::query()
                ->where('status', 'done')
                ->where('voice_tier', VoiceTier::HD)
                ->where('billable_chars', '>', 0)
                ->where('duration_seconds', '>', 0)
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

    /**
     * The line under "Monthly Characters" in the plan editor.
     */
    public static function adminLine(int $chars, ?float $priceDollars = null, bool $premium = true): string
    {
        if ($chars <= 0) {
            return 'Set the characters to see minutes and cost.';
        }

        $e = self::forChars($chars);
        $line = '≈ '.number_format($e['minutes_ar']).' min of Arabic · ≈ '.number_format($e['minutes_en']).' min of English'
            .($e['measured'] ? ' (speed measured from your last 90 days)' : ' (estimated speed)').'.';

        if (! $premium) {
            return $line.' Standard voice: speech cost is small and not estimated here.';
        }

        $line .= ' Premium voice cost to us if fully used: ≈ $'.number_format($e['cost'], 2)
            .' (up to $'.number_format($e['cost_high'], 2).'), ≈ $'.number_format($e['cost_2027'], 2).' from Jan 2027 prices.';

        if ($priceDollars !== null && $priceDollars > 0) {
            $line .= ' Price $'.number_format($priceDollars, 2).' → margin before card fees ≈ $'.number_format($priceDollars - $e['cost_high'], 2).'.';
        }

        return $line;
    }
}
