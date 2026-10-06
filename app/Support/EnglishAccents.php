<?php

namespace App\Support;

/**
 * Every English accent members can choose, grouped by country.
 *
 * Gemini 3.8 takes no language code on a single-speaker request, so the
 * accent travels as text: "prompt" names it for the stock voices, "clone"
 * directs a cloned voice away from the accent of its recording.
 * Codes stay within 10 characters (the dialect columns).
 */
final class EnglishAccents
{
    /**
     * @var array<string, array{en: string, ar: string, country: string, prompt: string, clone: string}>
     */
    public const ALL = [
        'en-US' => ['en' => 'American (General)', 'ar' => 'أمريكي (عام)', 'country' => 'US',
            'prompt' => 'General American English',
            'clone' => 'General American English accent.'],
        'en-US-SO' => ['en' => 'American (Southern)', 'ar' => 'أمريكي (جنوبي)', 'country' => 'US',
            'prompt' => 'Southern American English, a warm Texas and Georgia drawl',
            'clone' => 'Southern American English accent with a warm drawl, the way someone from Texas or Georgia talks.'],
        'en-US-NY' => ['en' => 'American (New York)', 'ar' => 'أمريكي (نيويورك)', 'country' => 'US',
            'prompt' => 'New York City English',
            'clone' => 'New York City English accent, the way a native New Yorker talks.'],
        'en-CA' => ['en' => 'Canadian', 'ar' => 'كندي', 'country' => 'CA',
            'prompt' => 'Canadian English',
            'clone' => 'Canadian English accent.'],
        'en-GB' => ['en' => 'British (Standard)', 'ar' => 'بريطاني (قياسي)', 'country' => 'GB',
            'prompt' => 'British English (standard Southern British, Received Pronunciation)',
            'clone' => 'British English accent.'],
        'en-GB-LD' => ['en' => 'British (London)', 'ar' => 'بريطاني (لندني)', 'country' => 'GB',
            'prompt' => 'London English (Estuary English)',
            'clone' => 'London English accent (Estuary), the way someone from London talks.'],
        'en-GB-NO' => ['en' => 'British (Northern)', 'ar' => 'بريطاني (شمالي)', 'country' => 'GB',
            'prompt' => 'Northern English (Manchester and Yorkshire)',
            'clone' => 'Northern English accent, the way someone from Manchester or Yorkshire talks.'],
        'en-GB-SC' => ['en' => 'British (Scottish)', 'ar' => 'بريطاني (اسكتلندي)', 'country' => 'GB',
            'prompt' => 'Scottish English',
            'clone' => 'Scottish English accent.'],
        'en-GB-WL' => ['en' => 'British (Welsh)', 'ar' => 'بريطاني (ويلزي)', 'country' => 'GB',
            'prompt' => 'Welsh English',
            'clone' => 'Welsh English accent.'],
        'en-IE' => ['en' => 'Irish', 'ar' => 'أيرلندي', 'country' => 'IE',
            'prompt' => 'Irish English',
            'clone' => 'Irish English accent, the way someone from Dublin talks.'],
        'en-AU' => ['en' => 'Australian', 'ar' => 'أسترالي', 'country' => 'AU',
            'prompt' => 'Australian English',
            'clone' => 'Australian English accent.'],
        'en-NZ' => ['en' => 'New Zealand', 'ar' => 'نيوزيلندي', 'country' => 'NZ',
            'prompt' => 'New Zealand English',
            'clone' => 'New Zealand English accent.'],
        'en-ZA' => ['en' => 'South African', 'ar' => 'جنوب أفريقي', 'country' => 'ZA',
            'prompt' => 'South African English',
            'clone' => 'South African English accent.'],
        'en-IN' => ['en' => 'Indian', 'ar' => 'هندي', 'country' => 'IN',
            'prompt' => 'Indian English',
            'clone' => 'Indian English accent.'],
        'en-NG' => ['en' => 'Nigerian', 'ar' => 'نيجيري', 'country' => 'NG',
            'prompt' => 'Nigerian English',
            'clone' => 'Nigerian English accent.'],
        'en-JM' => ['en' => 'Jamaican', 'ar' => 'جامايكي', 'country' => 'JM',
            'prompt' => 'Jamaican English (light Jamaican accent, standard English words)',
            'clone' => 'Jamaican English accent, keeping standard English words.'],
        'en-SG' => ['en' => 'Singaporean', 'ar' => 'سنغافوري', 'country' => 'SG',
            'prompt' => 'Singaporean English (standard English words, Singapore accent)',
            'clone' => 'Singaporean English accent, keeping standard English words.'],
        'en-AR' => ['en' => 'Arabic accent', 'ar' => 'بلكنة عربية', 'country' => 'AR',
            'prompt' => 'English spoken by a native Arabic speaker, with a natural Arabic accent',
            'clone' => 'English with a natural Arabic accent, the way a native Arabic speaker talks.'],
    ];

    /**
     * Countries in the order members see them.
     *
     * @var array<string, array{en: string, ar: string}>
     */
    public const COUNTRIES = [
        'US' => ['en' => 'United States', 'ar' => 'الولايات المتحدة'],
        'GB' => ['en' => 'United Kingdom', 'ar' => 'المملكة المتحدة'],
        'CA' => ['en' => 'Canada', 'ar' => 'كندا'],
        'IE' => ['en' => 'Ireland', 'ar' => 'أيرلندا'],
        'AU' => ['en' => 'Australia', 'ar' => 'أستراليا'],
        'NZ' => ['en' => 'New Zealand', 'ar' => 'نيوزيلندا'],
        'ZA' => ['en' => 'South Africa', 'ar' => 'جنوب أفريقيا'],
        'IN' => ['en' => 'India', 'ar' => 'الهند'],
        'NG' => ['en' => 'Nigeria', 'ar' => 'نيجيريا'],
        'JM' => ['en' => 'Jamaica', 'ar' => 'جامايكا'],
        'SG' => ['en' => 'Singapore', 'ar' => 'سنغافورة'],
        'AR' => ['en' => 'Arabic accent', 'ar' => 'لكنة عربية'],
    ];

    /**
     * @return array<int, array{value: string, label: string, label_ar: string}>
     */
    public static function catalogRows(): array
    {
        $rows = [];
        foreach (self::ALL as $code => $row) {
            $rows[] = ['value' => $code, 'label' => $row['en'], 'label_ar' => $row['ar']];
        }

        return $rows;
    }

    public static function prompt(?string $code): ?string
    {
        return self::ALL[self::normalize($code)]['prompt'] ?? null;
    }

    public static function cloneInstruction(?string $code): ?string
    {
        return self::ALL[self::normalize($code)]['clone'] ?? null;
    }

    public static function countryOf(?string $code): ?string
    {
        return self::ALL[self::normalize($code)]['country'] ?? null;
    }

    private static function normalize(?string $code): string
    {
        $code = trim(str_replace('_', '-', (string) $code));
        foreach (array_keys(self::ALL) as $known) {
            if (strcasecmp($known, $code) === 0) {
                return $known;
            }
        }

        return $code;
    }
}
