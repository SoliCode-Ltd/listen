<?php

namespace App\Support;

use App\Models\Plan;
use App\Models\VoiceSample;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Voice samples and plan data for the sign in and register pages.
 */
final class AuthSamples
{
    private const DIALECT_ORDER = ['ar-XA', 'ar-SA', 'ar-EG', 'ar-LB', 'ar-MA', 'ar-AE', 'ar-KW', 'ar-JO', 'ar-SY', 'ar-IQ'];

    private const ACCENT_ORDER = ['en-US', 'en-GB', 'en-AU', 'en-IN', 'en-ZA'];

    /**
     * One real Arabic sample per dialect, for the dialect rows.
     *
     * @return array<int, array{dialect: string, text: string, audio_url: string}>
     */
    public static function dialects(int $limit = 4): array
    {
        return self::samples('ar', $limit);
    }

    /**
     * Samples in the visitor's language: Arabic dialects on Arabic pages,
     * English accents on English pages.
     *
     * @return array<int, array{dialect: string, text: string, audio_url: string}>
     */
    public static function forLocale(?string $locale = null, int $limit = 5): array
    {
        $locale = strtolower((string) ($locale ?? app()->getLocale()));

        return self::samples(str_starts_with($locale, 'ar') ? 'ar' : 'en', $limit);
    }

    /**
     * One real sample per dialect or accent of the given language.
     *
     * @return array<int, array{dialect: string, text: string, audio_url: string}>
     */
    private static function samples(string $language, int $limit): array
    {
        $order = $language === 'ar' ? self::DIALECT_ORDER : self::ACCENT_ORDER;
        $cacheKey = 'auth_sample_rows:'.$language.':'.$limit;

        try {
            return Cache::remember($cacheKey, 300, function () use ($language, $order, $limit): array {
                $samples = VoiceSample::query()
                    ->active()
                    ->where('category', 'hero')
                    ->where('language', $language)
                    ->orderByDesc('is_featured')
                    ->ordered()
                    ->get();

                $rows = [];
                foreach ($order as $code) {
                    $sample = $samples->first(fn (VoiceSample $s): bool => strcasecmp((string) $s->dialect, $code) === 0
                        && trim((string) $s->audio_path) !== '');
                    if (! $sample) {
                        continue;
                    }
                    $text = $language === 'ar'
                        ? ($sample->sample_text_ar ?: $sample->sample_text)
                        : $sample->sample_text;
                    $rows[] = [
                        'dialect' => (string) $sample->dialect,
                        'text' => trim((string) $text),
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
            'KW' => 'kuwaiti', 'JO' => 'jordanian', 'SY' => 'syrian', 'IQ' => 'iraqi', 'TN' => 'tunisian', 'OM' => 'omani',
            'US' => 'american', 'GB' => 'british', 'AU' => 'australian', 'IN' => 'indian', 'ZA' => 'south_african'];
        $region = strtoupper((string) substr((string) strrchr('-'.$code, '-'), 1));
        $key = $keys[$region] ?? null;
        if ($key === 'msa') {
            return __('design.auth.fusha');
        }

        return $key ? __('ui.dialects.'.$key) : $code;
    }
}
