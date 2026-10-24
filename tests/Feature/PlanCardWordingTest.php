<?php

namespace Tests\Feature;

use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The plans' own card lines, as written on the live site, are worded like the
 * comparison table once the tidy-up has run: same labels on every card, the
 * admin's order and promises kept.
 */
class PlanCardWordingTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_live_cards_are_worded_alike(): void
    {
        $line = fn (string $label, string $value, string $icon = 'check') => compact('label', 'value', 'icon');
        $basic = $this->plan('card-basic',
            [$line('Generations per day', '15'), $line('Max text per generation', '1,000 characters'), $line('Voice quality', 'Premium'), $line('Emotion, style & accent', 'Full control'), $line('Processing priority', 'Normal'), $line('Audio history', '15 days'), $line('Download formats', 'MP3')],
            [$line('التوليدات يومياً', '15'), $line('أقصى نص في المرة الواحدة', '1,000 حرف'), $line('جودة الصوت', 'متميزة'), $line('المشاعر والأسلوب واللهجة', 'تحكم كامل'), $line('أولوية المعالجة', 'عادية'), $line('سجل الأصوات', '15 يوم'), $line('صيغ التحميل', 'MP3')]);
        $max = $this->plan('card-max',
            [$line('Requests per day', '400'), $line('Max Input per generation', '8,000 chars'), $line('Voice Quality', 'Premium'), $line('Voice Cloning', '10 Voices'), $line('Emotion, style & accent', 'Full Controls'), $line('Audio Environments', 'Allowed'), $line('Priority Queue', 'Priority'), $line('Commercial Use', 'Allowed', 'lock'), $line('Voices History', 'Unlimited'), $line('Dialogue Mode', 'Included'), $line('Download formats', 'MP3, WAV, AAC, OGG, FLAC'), $line('Developer API', 'Available'), $line('Video Dubbing', '20')],
            [$line('التوليدات يوميا', '400'), $line('إستنساخ الأصوات', '10 أصوات'), $line('البيئة الصوتية المحيطة', 'متاح'), $line('أولوية المعالجة', 'أعلى أولوية'), $line('سجل الأصوات', '90 يوم'), $line('وضع الحوار', 'متاح'), $line('صيغ التحميل', 'MP3, WAV, AAC'), $line('دبلجة الفيديوهات', '10')]);

        (require database_path('migrations/2026_10_29_120000_tidy_plan_card_own_lines.php'))->up();

        $show = fn (Plan $plan, string $locale) => array_map(fn ($l) => $l['label'].': '.$l['value'], $plan->fresh()->{'features_'.$locale});
        $this->assertSame([
            'Generations per day: 15', 'Max text per generation: 1,000 characters', 'Voice quality: Premium', 'Emotion, style & accent: Full control',
            'Processing priority: Normal', 'Commercial use: Personal only', 'Audio history: 15 days', 'Download formats: MP3',
        ], $show($basic, 'en'));
        $this->assertSame([
            'Generations per day: 400', 'Max text per generation: 8,000 characters', 'Voice quality: Premium', 'Voice cloning: 10 voices',
            'Emotion, style & accent: Full control', 'Sound environments: Included', 'Processing priority: Very high', 'Commercial use: Allowed',
            'Audio history: No time limit', 'Multi-speaker dialogue: Included', 'Download formats: MP3, WAV, M4A, OGG, FLAC',
            'Developer API: Included', 'Video dubbing: 20 videos a month',
        ], $show($max, 'en'));
        $this->assertSame([
            'التوليدات يوميًا: 15', 'أقصى نص في المرة الواحدة: 1,000 حرف', 'جودة الصوت: متميزة', 'المشاعر والأسلوب واللهجة: تحكم كامل',
            'أولوية المعالجة: عادية', 'الاستخدام التجاري: شخصي فقط', 'سجل الأصوات: 15 يومًا', 'صيغ التحميل: MP3',
        ], $show($basic, 'ar'));
        $this->assertSame([
            'التوليدات يوميًا: 400', 'استنساخ الأصوات: 10 أصوات', 'البيئات الصوتية: متاح', 'أولوية المعالجة: الأعلى', 'الاستخدام التجاري: شخصي فقط',
            'سجل الأصوات: 90 يومًا', 'حوار بأكثر من متحدث: متاح', 'صيغ التحميل: MP3, WAV, M4A', 'دبلجة الفيديو: 10 فيديوهات شهريًا',
        ], $show($max, 'ar'));
        $this->assertTrue((bool) $max->fresh()->pricing_use_custom_text, '"Use my own text" stays on');

        // Running it again changes nothing.
        $before = [$basic->fresh()->features_en, $max->fresh()->features_ar];
        (require database_path('migrations/2026_10_29_120000_tidy_plan_card_own_lines.php'))->up();
        $this->assertSame($before, [$basic->fresh()->features_en, $max->fresh()->features_ar]);
    }

    /**
     * @param  array<int, array<string, string>>  $english
     * @param  array<int, array<string, string>>  $arabic
     */
    private function plan(string $slug, array $english, array $arabic): Plan
    {
        return Plan::factory()->create([
            'slug' => $slug, 'price_cents' => 900, 'pricing_use_custom_text' => true,
            'features_en' => $english, 'features_ar' => $arabic,
        ]);
    }
}
