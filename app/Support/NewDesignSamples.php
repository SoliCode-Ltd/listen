<?php

namespace App\Support;

use App\Models\Plan;
use App\Models\VoiceSample;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Small data helpers for pages rendered in the new site design that are not
 * served by HomeController (sign in, register).
 */
final class NewDesignSamples
{
    private const DIALECT_ORDER = ['ar-XA', 'ar-SA', 'ar-EG', 'ar-LB', 'ar-MA', 'ar-AE', 'ar-KW', 'ar-JO', 'ar-SY', 'ar-IQ'];

    /**
     * One real Arabic sample per dialect, for the dialect rows.
     *
     * @return array<int, array{dialect: string, text: string, audio_url: string}>
     */
    public static function dialects(int $limit = 4): array
    {
        try {
            return Cache::remember('nt_dialect_rows:'.$limit, 300, function () use ($limit): array {
                $samples = VoiceSample::query()
                    ->active()
                    ->where('category', 'hero')
                    ->where('language', 'ar')
                    ->orderByDesc('is_featured')
                    ->ordered()
                    ->get();

                $rows = [];
                foreach (self::DIALECT_ORDER as $code) {
                    $sample = $samples->first(fn (VoiceSample $s): bool => strcasecmp((string) $s->dialect, $code) === 0
                        && trim((string) $s->audio_path) !== '');
                    if (! $sample) {
                        continue;
                    }
                    $rows[] = [
                        'dialect' => (string) $sample->dialect,
                        'text' => trim((string) ($sample->sample_text_ar ?: $sample->sample_text)),
                        'audio_url' => AudioUrl::fromPath(ltrim((string) $sample->audio_path, '/')),
                    ];
                    if (count($rows) >= $limit) {
                        break;
                    }
                }

                return $rows;
            });
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Monthly characters on the free plan, or null when there is no free plan.
     */
    public static function freePlanChars(): ?int
    {
        try {
            $plan = Plan::query()->where('is_active', true)->where('price_cents', 0)->orderBy('id')->first();
        } catch (Throwable) {
            return null;
        }

        return $plan ? (int) $plan->monthly_chars : null;
    }

    /**
     * Translated label for a dialect code such as "ar-SA" (Fusha for MSA).
     */
    public static function dialectLabel(string $code): string
    {
        $keys = ['XA' => 'msa', 'SA' => 'saudi', 'EG' => 'egyptian', 'LB' => 'lebanese', 'MA' => 'moroccan', 'AE' => 'emirati',
            'KW' => 'kuwaiti', 'JO' => 'jordanian', 'SY' => 'syrian', 'IQ' => 'iraqi', 'TN' => 'tunisian', 'OM' => 'omani'];
        $region = strtoupper((string) substr((string) strrchr('-'.$code, '-'), 1));
        $key = $keys[$region] ?? null;
        if ($key === 'msa') {
            return __('design.home.fusha');
        }

        return $key ? __('ui.dialects.'.$key) : $code;
    }
}
