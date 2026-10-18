<?php

namespace App\Support;

/**
 * The voice orbs' colours: one palette per part of the product and per plan,
 * from the deepest shade to the light that catches the top. The orb script
 * (public/js/listen-orbs.js) draws them; the CSS sphere uses them without it.
 */
class Orbs
{
    public const PALETTES = [
        // Listen itself: the brand violet, lilac and the warm light of the site.
        'listen' => ['#24138A', '#7659FA', '#C9BEFF', '#FFE3D3'],
        // Arabic dialects: dusk over sand, saffron into plum.
        'arabic' => ['#5A1F66', '#C2507F', '#F2B544', '#FFEBC9'],
        // English accents: a morning sky over the sea.
        'english' => ['#102B66', '#3D7BFF', '#86D9E6', '#EEF8FF'],
        // Emotions and styles: warm, flushed, alive.
        'emotions' => ['#7A1450', '#E23C74', '#FF7F66', '#FFC9B6'],
        // Conversations: two voices, teal and violet, meeting.
        'dialogue' => ['#15606B', '#2DB7A6', '#A294FF', '#EEF0FF'],
        // Sound environments: a deep space lit by one warm light.
        'places' => ['#14295E', '#2672B4', '#46C6CC', '#F6D38A'],
        // Plans, from the lightest to the deepest.
        'starter' => ['#124456', '#46B0A2', '#BDEBDA', '#F5FFFA'],
        'creator' => ['#24138A', '#7659FA', '#C9BEFF', '#FFE3D3'],
        'pro' => ['#46163C', '#C04E7C', '#F2B544', '#FFF1D6'],
        'studio_max' => ['#17144A', '#3A31B4', '#7E62FF', '#F2C46E'],
    ];

    /**
     * @return array{0: string, 1: string, 2: string, 3: string}
     */
    public static function colors(string $name): array
    {
        return self::PALETTES[$name] ?? self::PALETTES['listen'];
    }

    /** The orb for a plan, by its slug. */
    public static function forPlan(string $slug): string
    {
        return array_key_exists($slug, self::PALETTES) ? $slug : 'listen';
    }
}
