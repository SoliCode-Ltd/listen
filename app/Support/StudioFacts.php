<?php

namespace App\Support;

use App\Models\Plan;

/**
 * What the studio can do, as the public pages, the structured data and llms.txt
 * describe it: every number is counted from the studio's own catalogs, and what
 * depends on a plan (two-voice dialogue, voice cloning, download formats) is
 * read from the plans on sale, so the claims stay true when either changes.
 */
final class StudioFacts
{
    /** Spoken Arabic dialects (Fusha and its styles are counted apart). */
    public static function arabicDialects(): int
    {
        return DialectCounts::spokenArabic();
    }

    /** The styles of Modern Standard Arabic (news, classical, simple…). */
    public static function fushaStyles(): int
    {
        return count(ArabicDialects::ALL) - DialectCounts::spokenArabic();
    }

    public static function arabicCountries(): int
    {
        return count(ArabicDialects::COUNTRIES) - 1;
    }

    public static function englishAccents(): int
    {
        return count(EnglishAccents::ALL);
    }

    public static function englishCountries(): int
    {
        return count(EnglishAccents::COUNTRIES) - 1;
    }

    /** Spoken Arabic dialects and English accents together: "200+ dialects and accents". */
    public static function dialectsAndAccents(): int
    {
        return self::arabicDialects() + self::englishAccents();
    }

    /** Feelings for a whole script, as the studio's picker lists them. */
    public static function emotions(): int
    {
        return count(TtsStudioConfig::emotionCatalog());
    }

    public static function styles(): int
    {
        return count(TtsStudioConfig::performanceStyleCatalog());
    }

    /** Sounds written in the text where they happen: <laugh>, <sigh>, <short pause>… */
    public static function soundTags(): int
    {
        return count(GeminiVocalTags::all());
    }

    /** Feeling and voice tags that change the delivery from where they are written: [excited], [whispering]… */
    public static function deliveryTags(): int
    {
        return count(array_merge(...array_values(GeminiVocalTags::panelDeliveryGroups())));
    }

    /** Places, devices and creative spaces (the voice polish settings are counted apart). */
    public static function environments(): int
    {
        return count(array_filter(AudioEffects::ALL, fn (array $row): bool => $row[1] !== 'clean'));
    }

    /** Voices in the library, standard and premium, Arabic and English: "270 voices". */
    public static function voices(): int
    {
        return self::arabicVoices() + self::englishVoices();
    }

    public static function arabicVoices(): int
    {
        return self::voiceCounts()['ar'] ?? 0;
    }

    public static function englishVoices(): int
    {
        return self::voiceCounts()['en'] ?? 0;
    }

    /** "140+", "50+": a count rounded down to the ten, so the claim stays true. */
    public static function roundedDown(int $count): int
    {
        return intdiv($count, 10) * 10;
    }

    /**
     * A number with its Arabic noun in the form the number takes, decided by its
     * last two digits: «107 أصوات», «270 صوتاً», «200 صوت».
     */
    public static function arabicCount(int $count, string $singular, string $plural, string $accusative): string
    {
        $lastTwo = $count % 100;
        $noun = match (true) {
            $lastTwo >= 3 && $lastTwo <= 10 => $plural,
            $lastTwo >= 11 => $accusative,
            default => $singular,
        };

        return $count.' '.$noun;
    }

    /**
     * Active voices by language, counted once a request.
     *
     * @return array<string, int>
     */
    private static function voiceCounts(): array
    {
        return once(function (): array {
            try {
                return array_map('count', VoiceCatalog::byLanguage());
            } catch (\Throwable) {
                return [];
            }
        });
    }

    /**
     * The plans with two-voice dialogue, by name in the given language.
     *
     * @return array<int, string>
     */
    public static function dialoguePlans(string $locale): array
    {
        return self::onSale()
            ->filter(fn (Plan $plan): bool => (bool) $plan->dialogue_enabled)
            ->map(fn (Plan $plan): string => self::planName($plan, $locale))
            ->values()->all();
    }

    /**
     * Plans by name, as a sentence says them: "the Pro plan", "the Pro and Studio Max
     * plans"; in Arabic «باقة احترافي», «باقتي احترافي وستوديو ماكس», «باقات …».
     *
     * @param  array<int, string>  $names
     */
    public static function planList(array $names, string $locale): string
    {
        $count = count($names);
        if ($locale === 'ar') {
            return ($count === 1 ? 'باقة ' : ($count === 2 ? 'باقتي ' : 'باقات ')).implode(' و', $names);
        }
        $last = array_pop($names);
        $joined = $names === [] ? $last : implode(', ', $names).' and '.$last;

        return 'the '.$joined.($count === 1 ? ' plan' : ' plans');
    }

    /**
     * How many cloned voices the plans with cloning hold, fewest to most; null when no plan on sale has it.
     *
     * @return array{0: int, 1: int}|null
     */
    public static function cloningRange(): ?array
    {
        $limits = self::onSale()
            ->filter(fn (Plan $plan): bool => (bool) $plan->voice_cloning_enabled && (int) $plan->voice_cloning_max_voices > 0)
            ->map(fn (Plan $plan): int => (int) $plan->voice_cloning_max_voices);

        return $limits->isEmpty() ? null : [$limits->min(), $limits->max()];
    }

    /** Whether a plan on sale has voice cloning. */
    public static function cloning(): bool
    {
        return self::cloningRange() !== null;
    }

    /** Whether "Dub my video" is open to everyone (until then only the admin sees it). */
    public static function dubbing(): bool
    {
        try {
            return VideoDubbing::audience() === VideoDubbing::AUDIENCE_EVERYONE;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Download formats: those every plan on sale has, and those only some have.
     *
     * @return array{every: array<int, string>, some: array<int, string>}
     */
    public static function downloadFormats(): array
    {
        $sets = self::onSale()
            ->map(fn (Plan $plan): array => array_values(array_unique(array_map(
                fn ($format): string => strtoupper(trim((string) $format)),
                (array) ($plan->download_formats ?? [])
            ))))
            ->filter(fn (array $formats): bool => $formats !== [])
            ->values();
        if ($sets->isEmpty()) {
            return ['every' => [], 'some' => []];
        }
        $every = array_values(array_intersect(...$sets->all()));
        $all = array_values(array_unique(array_merge(...$sets->all())));

        return ['every' => $every, 'some' => array_values(array_diff($all, $every))];
    }

    /**
     * The monthly plans people can buy.
     *
     * @return \Illuminate\Support\Collection<int, Plan>
     */
    private static function onSale(): \Illuminate\Support\Collection
    {
        try {
            return Plan::query()->where('is_active', true)->where('price_cents', '>', 0)
                ->where(fn ($query) => $query->whereNull('kind')->orWhere('kind', 'subscription'))
                ->orderBy('price_cents')->get();
        } catch (\Throwable) {
            return collect();
        }
    }

    private static function planName(Plan $plan, string $locale): string
    {
        $name = $locale === 'ar' ? ($plan->name_ar ?: $plan->name) : ($plan->name_en ?: $plan->name);

        return trim((string) $name);
    }
}
