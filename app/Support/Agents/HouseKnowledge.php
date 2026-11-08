<?php

namespace App\Support\Agents;

use App\Models\Plan;
use App\Models\Voice;
use App\Support\ArabicDialects;
use App\Support\AudioEffects;
use App\Support\EnglishAccents;
use App\Support\GeminiVocalTags;
use App\Support\PlanPricingLines;
use App\Support\SpeakerVoices;
use App\Support\TtsStudioConfig;
use App\Support\VoiceCatalog;

/**
 * The full product sheet Listen's own agent answers from (HouseAgent::knowledge), written from
 * the studio's own catalogs and plans so it is complete and stays true: the voice library by
 * language, gender and age (children's and elders' voices by name), every Arabic dialect by
 * region and every English accent, the emotions, performance styles, sound places and tags, and
 * each plan's whole comparison row. A caller who asks for something Listen has (a child's voice,
 * a dialect, a feeling) is told it is there, never that it is missing.
 */
final class HouseKnowledge
{
    private const AGES = [
        'child' => ['ar' => 'أطفال', 'en' => 'children'],
        'young' => ['ar' => 'شباب', 'en' => 'young adults'],
        'middle' => ['ar' => 'متوسطو العمر', 'en' => 'middle-aged'],
        'elder' => ['ar' => 'كبار السن', 'en' => 'elders'],
    ];

    private const LANGUAGES = [
        'ar' => ['ar' => 'العربية', 'en' => 'Arabic'], 'en' => ['ar' => 'الإنجليزية', 'en' => 'English'],
        'fr' => ['ar' => 'الفرنسية', 'en' => 'French'], 'es' => ['ar' => 'الإسبانية', 'en' => 'Spanish'],
        'de' => ['ar' => 'الألمانية', 'en' => 'German'], 'it' => ['ar' => 'الإيطالية', 'en' => 'Italian'],
        'pt' => ['ar' => 'البرتغالية', 'en' => 'Portuguese'], 'tr' => ['ar' => 'التركية', 'en' => 'Turkish'],
        'ru' => ['ar' => 'الروسية', 'en' => 'Russian'], 'nl' => ['ar' => 'الهولندية', 'en' => 'Dutch'],
        'hi' => ['ar' => 'الهندية', 'en' => 'Hindi'], 'ja' => ['ar' => 'اليابانية', 'en' => 'Japanese'],
        'ko' => ['ar' => 'الكورية', 'en' => 'Korean'], 'zh' => ['ar' => 'الصينية', 'en' => 'Chinese'],
    ];

    public static function details(bool $ar): string
    {
        $sections = array_filter([
            self::voices($ar),
            self::dialects($ar),
            self::accents($ar),
            self::delivery($ar),
            self::places($ar),
            self::plans($ar),
            self::pages($ar),
        ]);

        return implode("\n\n", $sections);
    }

    /** The voice library: how many, by language, gender and age, with the children's and elders' voices by name. */
    private static function voices(bool $ar): string
    {
        $k = $ar ? 'ar' : 'en';
        $voices = rescue(fn () => Voice::query()->where('is_active', true)->get(['internal_voice_id', 'display_name', 'language', 'gender', 'tier']), collect(), false);
        if ($voices->isEmpty()) {
            return '';
        }
        $lines = [$ar ? '# مكتبة الأصوات' : '# The voice library'];
        $byLanguage = $voices->groupBy('language');
        $langs = [];
        foreach ($byLanguage as $code => $group) {
            $langs[] = (self::LANGUAGES[$code][$k] ?? strtoupper((string) $code)).' '.$group->count();
        }
        $lines[] = ($ar ? '- عدد الأصوات حسب اللغة: ' : '- Voices by language: ').implode($ar ? '، ' : ', ', $langs).'.';
        $women = $voices->filter(fn ($v) => strtolower((string) $v->gender) === 'female')->count();
        $men = $voices->filter(fn ($v) => strtolower((string) $v->gender) === 'male')->count();
        $lines[] = $ar ? "- {$women} صوت نسائي و{$men} صوت رجالي." : "- {$women} women's voices and {$men} men's voices.";

        $ages = [];
        $named = [];
        foreach ($voices as $v) {
            $age = SpeakerVoices::ageOf((string) $v->internal_voice_id);
            if (! isset(self::AGES[$age])) {
                continue;
            }
            $ages[$age] = ($ages[$age] ?? 0) + 1;
            if (in_array($age, ['child', 'elder'], true)) {
                $name = $ar ? VoiceCatalog::arabicName((string) $v->display_name) : (string) $v->display_name;
                $female = strtolower((string) $v->gender) === 'female';
                $who = $age === 'child'
                    ? ($female ? ($ar ? 'بنت' : 'girl') : ($ar ? 'ولد' : 'boy'))
                    : ($female ? ($ar ? 'سيدة' : 'woman') : ($ar ? 'رجل' : 'man'));
                $named[$age][(string) $v->language][] = $name.' ('.$who.')';
            }
        }
        if ($ages !== []) {
            $parts = [];
            foreach (self::AGES as $age => $label) {
                if (! empty($ages[$age])) {
                    $parts[] = $label[$k].' '.$ages[$age];
                }
            }
            $lines[] = ($ar ? '- أعمار مختلفة (فلتر العمر في مكتبة الأصوات): ' : '- Every age (the library has an age filter): ').implode($ar ? '، ' : ', ', $parts).'.';
        }
        foreach ($named['child'] ?? [] as $lang => $names) {
            $lines[] = ($ar ? '- أصوات أطفال حقيقية ب'.(self::LANGUAGES[$lang]['ar'] ?? $lang).' (تبقى بصوت طفل في النصوص الطويلة): ' : '- Real children\'s voices in '.(self::LANGUAGES[$lang]['en'] ?? $lang).' (they keep a child\'s voice in long texts): ')
                .implode($ar ? '، ' : ', ', $names).'.';
        }
        $elders = array_merge(...array_values($named['elder'] ?? [[]]));
        if ($elders !== []) {
            $lines[] = ($ar ? '- أصوات كبار السن: ' : '- Elders\' voices: ').implode($ar ? '، ' : ', ', array_slice($elders, 0, 16)).'.';
        }
        $premium = $voices->filter(fn ($v) => (string) $v->tier === 'hd')->count();
        if ($premium > 0) {
            $lines[] = $ar ? "- {$premium} صوت مميز (Premium) بجودة أعلى، وباقي الأصوات قياسية." : "- {$premium} premium voices of the highest quality; the rest are standard.";
        }
        $lines[] = $ar
            ? '- لكل صوت صورة وعينة مسموعة، ويمكن البحث والتصفية حسب اللغة واللهجة والجنس والعمر، وحفظ المفضلة.'
            : '- Every voice has a picture and a sample to hear; the library filters by language, dialect, gender and age, and keeps favourites.';

        return implode("\n", $lines);
    }

    /** Every Arabic dialect, grouped by region. */
    private static function dialects(bool $ar): string
    {
        $k = $ar ? 'ar' : 'en';
        $groups = [];
        foreach (ArabicDialects::ALL as $row) {
            $groups[$row['region']][] = $row[$k];
        }
        $lines = [$ar ? '# اللهجات العربية (كلها في الاستوديو)' : '# Arabic dialects (all of them in the studio)'];
        foreach (ArabicDialects::regions() as $region => $label) {
            if (! empty($groups[$region])) {
                $lines[] = '- '.$label[$k].': '.implode($ar ? '، ' : ', ', $groups[$region]).'.';
            }
        }

        return implode("\n", $lines);
    }

    private static function accents(bool $ar): string
    {
        $k = $ar ? 'ar' : 'en';
        $names = array_map(fn (array $row): string => $row[$k], EnglishAccents::ALL);

        return ($ar ? '# اللكنات الإنجليزية' : '# English accents')."\n- ".implode($ar ? '، ' : ', ', $names).'.';
    }

    /** Emotions, performance styles and the tags written in the text. */
    private static function delivery(bool $ar): string
    {
        $label = fn (array $row): string => (string) ($ar ? ($row['label_ar'] ?? $row['label']) : $row['label']);
        $emotions = array_values(array_unique(rescue(fn () => array_map($label, TtsStudioConfig::emotionCatalog()), [], false)));
        $styles = array_values(array_unique(rescue(fn () => array_map($label, TtsStudioConfig::performanceStyleCatalog()), [], false)));
        $sounds = array_map(fn (string $tag): string => '['.$tag.']', array_slice(GeminiVocalTags::all(), 0, 14));
        $lines = [$ar ? '# المشاعر والأداء' : '# Emotions and delivery'];
        if ($emotions !== []) {
            $lines[] = ($ar ? '- مشاعر للنص كله: ' : '- Emotions for a whole script: ').implode($ar ? '، ' : ', ', $emotions).'.';
        }
        if ($styles !== []) {
            $lines[] = ($ar ? '- أساليب أداء: ' : '- Performance styles: ').implode($ar ? '، ' : ', ', $styles).'.';
        }
        if ($sounds !== []) {
            $lines[] = ($ar ? '- أصوات تُكتب داخل النص في مكانها، مثل: ' : '- Sounds written in the text where they happen, such as: ').implode(' ', $sounds).'.';
        }
        $lines[] = $ar
            ? '- وسوم تغيّر الأداء من مكانها في النص (مثل الهمس والحماس)، وحوار بين أكثر من صوت في الباقات التي تشمله.'
            : '- Tags that change the delivery from where they are written (whispering, excitement...), and dialogue between several voices on the plans that include it.';

        return implode("\n", $lines);
    }

    /** The sound places a voice can be put in (radio, phone, cave...). */
    private static function places(bool $ar): string
    {
        $names = [];
        foreach (AudioEffects::ALL as $row) {
            if (($row[1] ?? '') !== 'clean') {
                $names[] = $ar ? $row[3] : $row[2];
            }
        }

        return $names === [] ? '' : ($ar ? '# الأماكن الصوتية (تضع الصوت في مكان)' : '# Sound places (the voice placed in a scene)')."\n- ".implode($ar ? '، ' : ', ', $names).'.';
    }

    /** Each plan's whole row of the pricing page's comparison table. */
    private static function plans(bool $ar): string
    {
        $locale = $ar ? 'ar' : 'en';
        $plans = rescue(fn () => Plan::query()->subscriptionPlans()->where('is_active', true)->orderBy('price_cents')->get(), collect(), false);
        $lines = [$ar ? '# تفاصيل كل باقة (كما في جدول المقارنة)' : '# Every plan in detail (as in the comparison table)'];
        foreach ($plans as $plan) {
            $rows = rescue(fn () => PlanPricingLines::forComparison($plan, $locale), [], false);
            $cells = [];
            foreach ($rows as $row) {
                $l = trim(strip_tags((string) ($row['label'] ?? '')));
                $v = trim(strip_tags((string) ($row['value'] ?? '')));
                if ($v === '✓') {
                    $v = $ar ? 'مشمول' : 'included';
                }
                if ($l !== '' || $v !== '') {
                    $cells[] = $v !== '' && $v !== $l ? "{$l}: {$v}" : $l;
                }
            }
            if ($cells === []) {
                continue;
            }
            $key = 'ui.pricing.plan_name_'.$plan->slug;
            $name = $ar && trans($key, [], 'ar') !== $key ? trans($key, [], 'ar') : $plan->name;
            $lines[] = '- '.$name.': '.implode($ar ? '؛ ' : '; ', $cells).'.';
        }

        return count($lines) > 1 ? implode("\n", $lines) : '';
    }

    private static function pages(bool $ar): string
    {
        return $ar
            ? "# صفحات الموقع\n- الأسعار، ونماذج الأصوات، والتجربة المجانية (Demo)، واللهجات العربية، واللكنات الإنجليزية، والوكلاء الصوتيون، والتواصل، والتسجيل. يمكن كتابة رابط أي صفحة في المحادثة."
            : "# The website's pages\n- Pricing, voice samples, the free demo, Arabic dialects, English accents, voice agents, contact and sign up. Any page's link can be written in the chat.";
    }
}
