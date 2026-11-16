<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The plans' own pricing-card lines (Admin → Plans → "Use my own text instead")
 * were written at different times, so the cards worded the same thing in
 * different ways ("Requests per day" on one, "Generations per day" on the
 * next). This words every card's lines like the comparison table under them.
 * The lines, their order and what they promise stay as the admin wrote them;
 * a card that left out commercial use gets that line, as on the other cards.
 */
return new class extends Migration {
    /** Old label => the table's label. */
    private const LABELS = [
        'en' => [
            'Requests per day' => 'Generations per day',
            'Max Input per generation' => 'Max text per generation',
            'Voice Quality' => 'Voice quality',
            'Voice Cloning' => 'Voice cloning',
            'Audio Environments' => 'Sound environments',
            'Priority Queue' => 'Processing priority',
            'Commercial Use' => 'Commercial use',
            'Voices History' => 'Audio history',
            'Dialogue Mode' => 'Multi-speaker dialogue',
            'Video Dubbing' => 'Video dubbing',
        ],
        'ar' => [
            'التوليدات يومياً' => 'التوليدات يوميًا',
            'التوليدات يوميا' => 'التوليدات يوميًا',
            'إستنساخ الأصوات' => 'استنساخ الأصوات',
            'البيئة الصوتية المحيطة' => 'البيئات الصوتية',
            'وضع الحوار' => 'حوار بأكثر من متحدث',
            'دبلجة الفيديوهات' => 'دبلجة الفيديو',
        ],
    ];

    public function up(): void
    {
        foreach (DB::table('plans')->get(['id', 'features_en', 'features_ar']) as $plan) {
            $changes = [];
            foreach (['en', 'ar'] as $locale) {
                $stored = $plan->{'features_'.$locale};
                $lines = is_string($stored) ? json_decode($stored, true) : null;
                if (! is_array($lines) || $lines === []) {
                    continue;
                }

                $tidy = $this->tidy(array_values(array_filter($lines, 'is_array')), $locale);
                if ($tidy !== $lines) {
                    $changes['features_'.$locale] = json_encode($tidy, JSON_UNESCAPED_UNICODE);
                }
            }

            if ($changes !== []) {
                DB::table('plans')->where('id', $plan->id)->update($changes);
            }
        }
    }

    public function down(): void
    {
        // Only words changed; the old wording is not kept.
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @return array<int, array<string, mixed>>
     */
    private function tidy(array $lines, string $locale): array
    {
        $commercial = $locale === 'en' ? 'Commercial use' : 'الاستخدام التجاري';
        $history = $locale === 'en' ? 'Audio history' : 'سجل الأصوات';

        foreach ($lines as $i => $line) {
            $label = trim((string) ($line['label'] ?? ''));
            $label = self::LABELS[$locale][$label] ?? $label;
            $lines[$i]['label'] = $label;
            $lines[$i]['value'] = $this->value($label, trim((string) ($line['value'] ?? '')), $locale);
        }

        // Every card says whether its audio may be used commercially, the table too.
        $labels = array_column($lines, 'label');
        if (! in_array($commercial, $labels, true)) {
            $at = array_search($history, $labels, true);
            $line = ['label' => $commercial, 'value' => $locale === 'en' ? 'Personal only' : 'شخصي فقط', 'icon' => 'lock'];
            array_splice($lines, $at === false ? count($lines) : (int) $at, 0, [$line]);
        }

        return $lines;
    }

    private function value(string $label, string $value, string $locale): string
    {
        // Downloads are M4A files (AAC sound), as the table says.
        $value = preg_replace('/\bAAC\b/', 'M4A', $value);

        if ($locale === 'en') {
            return match ($label) {
                'Max text per generation' => (string) preg_replace('/^([\d,]+)\s*chars?$/i', '$1 characters', $value),
                'Voice cloning' => (string) preg_replace('/^(\d+)\s*Voices?$/', '$1 voices', $value),
                'Emotion, style & accent' => $value === 'Full Controls' ? 'Full control' : $value,
                'Sound environments', 'Multi-speaker dialogue', 'Developer API' => in_array($value, ['Allowed', 'Available'], true) ? 'Included' : $value,
                'Processing priority' => $value === 'Priority' ? 'Very high' : $value,
                'Audio history' => $value === 'Unlimited' ? 'No time limit' : $value,
                'Video dubbing' => ctype_digit($value) ? $value.' videos a month' : $value,
                default => $value,
            };
        }

        return match ($label) {
            'أولوية المعالجة' => match ($value) {
                'أولوية' => 'عالية جدًا',
                'أعلى أولوية' => 'الأعلى',
                default => $value,
            },
            'سجل الأصوات' => match (true) {
                $value === 'غير محدود' => 'بدون حد زمني',
                (bool) preg_match('/^(\d+)\s*(يوم|يومًا|يوماً|أيام)$/u', $value, $m) => $m[1].' '.((int) $m[1] >= 3 && (int) $m[1] <= 10 ? 'أيام' : 'يومًا'),
                default => $value,
            },
            'دبلجة الفيديو' => ctype_digit($value)
                ? $value.' '.((int) $value >= 3 && (int) $value <= 10 ? 'فيديوهات' : 'فيديو').' شهريًا'
                : $value,
            default => $value,
        };
    }
};
