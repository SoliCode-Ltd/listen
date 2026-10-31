<?php

use App\Models\SiteSetting;
use App\Support\PlanPricingLines;
use App\Support\VoiceDesign;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Voice Design opens on the live site, as the owner chose: Creator members
     * design 10 voices in all, Pro 50 and Studio Max with no limit. Every voice
     * kept counts while the subscription lasts, deleted ones too; there is no
     * monthly number. Voice Design opens to everyone (Voice Design → Settings),
     * and each of these plans' own pricing-card lines (Admin → Plans → Features
     * List) gets its line in English and Arabic ("Voice Design: 10 voices",
     * "تصميم الأصوات: 10 أصوات"), under the voice cloning line, in place of an earlier Voice
     * Design line. Every other plan has it switched off. A database with none of
     * these plans (a new install) is left alone.
     *
     * @var array<string, array{voices:int, unlimited:bool}>
     */
    private const PLANS = [
        'creator' => ['voices' => 10, 'unlimited' => false],
        'pro' => ['voices' => 50, 'unlimited' => false],
        'studio_max' => ['voices' => 0, 'unlimited' => true],
    ];

    /** Labels a plan's Voice Design line has had, in either language, besides today's. */
    private const EARLIER_LABELS = ['Design your own voices', 'تصميم الصوت', 'صمّم أصواتك بالوصف'];

    public function up(): void
    {
        if (! Schema::hasTable('plans')) {
            return;
        }
        if (! Schema::hasColumn('plans', 'voice_design_unlimited')) {
            Schema::table('plans', function (Blueprint $table): void {
                // No count at all, of new voices or of voices kept (Studio Max).
                $table->boolean('voice_design_unlimited')->default(false);
            });
        }

        $opened = 0;
        foreach (self::PLANS as $slug => $numbers) {
            $plan = DB::table('plans')->where('slug', $slug)->first();
            if (! $plan) {
                continue;
            }
            $updates = [
                'voice_design_enabled' => true,
                'voice_design_unlimited' => $numbers['unlimited'],
                'voice_design_max_voices' => $numbers['voices'],
                'voice_design_monthly_voices' => 0,
            ];
            foreach (PlanPricingLines::LOCALES as $locale) {
                $column = 'features_'.$locale;
                $lines = Schema::hasColumn('plans', $column) ? json_decode((string) ($plan->{$column} ?? ''), true) : null;
                // No own lines: the card writes its Voice Design line from the plan's settings.
                if (is_array($lines) && $lines !== []) {
                    $updates[$column] = json_encode($this->withDesignLine($lines, $locale, $numbers), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
            }
            DB::table('plans')->where('id', $plan->id)->update($updates);
            $opened++;
        }

        if ($opened === 0) {
            return;
        }
        // No other plan has it: its members try the tools and the demo's one take, and the
        // design button leads to the cheapest of these plans.
        DB::table('plans')->whereNotIn('slug', array_keys(self::PLANS))->update(['voice_design_enabled' => false, 'voice_design_unlimited' => false]);
        if (Schema::hasTable('site_settings')) {
            SiteSetting::set('voice_design_audience', VoiceDesign::AUDIENCE_EVERYONE, VoiceDesign::GROUP);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('plans')) {
            return;
        }
        foreach (array_keys(self::PLANS) as $slug) {
            $plan = DB::table('plans')->where('slug', $slug)->first();
            if (! $plan) {
                continue;
            }
            $updates = ['voice_design_enabled' => false];
            foreach (PlanPricingLines::LOCALES as $locale) {
                $column = 'features_'.$locale;
                $lines = Schema::hasColumn('plans', $column) ? json_decode((string) ($plan->{$column} ?? ''), true) : null;
                if (is_array($lines) && $lines !== []) {
                    $kept = array_values(array_filter($lines, fn ($line): bool => ! $this->isDesignLine($line, $locale)));
                    $updates[$column] = json_encode($kept, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
            }
            DB::table('plans')->where('id', $plan->id)->update($updates);
        }
        if (Schema::hasTable('site_settings')) {
            SiteSetting::set('voice_design_audience', VoiceDesign::AUDIENCE_PREVIEW, VoiceDesign::GROUP);
        }
        if (Schema::hasColumn('plans', 'voice_design_unlimited')) {
            Schema::table('plans', function (Blueprint $table): void {
                $table->dropColumn('voice_design_unlimited');
            });
        }
    }

    /**
     * The plan's own lines with its Voice Design line: in place of the one it has
     * (an earlier wording too), or else under the voice cloning line (or at the end).
     *
     * @param  array<int, mixed>  $lines
     * @param  array{voices:int, unlimited:bool}  $numbers
     * @return array<int, mixed>
     */
    private function withDesignLine(array $lines, string $locale, array $numbers): array
    {
        $line = [
            'label' => (string) __('ui.pricing.lines.label_voice_design', [], $locale),
            'value' => PlanPricingLines::designValue($numbers['unlimited'], 0, $numbers['voices'], $locale),
            'icon' => 'sliders',
        ];
        $cloning = PlanPricingLines::labelKey((string) __('ui.pricing.lines.label_voice_cloning', [], $locale));
        $at = count($lines);
        foreach ($lines as $i => $existing) {
            if ($this->isDesignLine($existing, $locale)) {
                $lines[$i] = $line;

                return array_values(array_filter($lines, fn ($other, $j): bool => $j === $i || ! $this->isDesignLine($other, $locale), ARRAY_FILTER_USE_BOTH));
            }
            if (PlanPricingLines::labelKey((string) (is_array($existing) ? ($existing['label'] ?? '') : '')) === $cloning) {
                $at = $i + 1;
            }
        }
        array_splice($lines, $at, 0, [$line]);

        return $lines;
    }

    private function isDesignLine(mixed $line, string $locale): bool
    {
        $labels = array_map(
            fn (string $label): string => PlanPricingLines::labelKey($label),
            [...self::EARLIER_LABELS, (string) __('ui.pricing.lines.label_voice_design', [], $locale)],
        );

        return is_array($line) && in_array(PlanPricingLines::labelKey((string) ($line['label'] ?? '')), $labels, true);
    }
};
