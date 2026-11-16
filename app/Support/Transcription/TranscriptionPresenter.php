<?php

namespace App\Support\Transcription;

use App\Models\Transcription;
use App\Models\User;
use App\Services\Transcription\TranscriptExport;

/**
 * Transcripts and the member's minutes as the page shows them (and, with
 * $links off, as the API and MCP give them): the length and the minutes each
 * used ("0 min used" for one that failed), the date, how far one working has
 * got, and the month's minutes with the day they start again.
 */
final class TranscriptionPresenter
{
    /** @return array<string, mixed> */
    public static function summary(Transcription $t, bool $arabic, bool $links = true): array
    {
        $locale = $arabic ? 'ar' : 'en';
        $language = (string) ($t->language ?: ($t->language_requested !== 'auto' ? $t->language_requested : ''));
        $steps = max(1, (int) $t->parts + 1);
        $done = $t->status === Transcription::READY ? $steps : ($t->work === null ? 0 : 1 + (int) $t->parts_done);

        return [
            'id' => $t->code,
            'title' => $t->title,
            'status' => $t->status,
            'source' => $t->source,
            'seconds' => round((float) $t->seconds, 2),
            'length' => Minutes::format((int) round((float) $t->seconds), $locale),
            'used_seconds' => $t->usedSeconds(),
            'used' => Minutes::format($t->usedSeconds(), $locale),
            'free' => $t->is_free,
            'language' => $language ?: null,
            'language_name' => $language !== '' ? self::languageName($language, $arabic) : null,
            'rtl' => in_array($language, TranscriptionSettings::RTL, true),
            'created_at' => $t->created_at?->toIso8601String(),
            'date' => $t->created_at?->copy()->locale($locale)->isoFormat('D MMM, HH:mm'),
            'progress' => $t->isWorking() ? (int) min(95, round(100 * $done / $steps)) : 100,
            'speakers' => $t->status === Transcription::READY ? $t->speakerCount() : 0,
            'error' => $t->status === Transcription::FAILED ? ((string) $t->error ?: __('transcribe.failed.default', [], $locale)) : null,
            'audio' => $t->audio_deleted_at === null && $t->audio_path !== null,
        ] + ($links ? ['urls' => self::urls($t)] : []);
    }

    /**
     * Everything the transcript view needs: the paragraphs and sentences, the
     * speakers with their names, and the translations made.
     *
     * @return array<string, mixed>
     */
    public static function full(Transcription $t, bool $arabic, bool $links = true): array
    {
        $letters = array_values(array_unique(array_column((array) $t->paragraphs, 'speaker')));

        return self::summary($t, $arabic, $links) + [
            'speaker_list' => array_map(fn ($l) => ['id' => $l, 'name' => $t->speakerName($l, $arabic), 'named' => isset(($t->speaker_names ?? [])[$l])], $letters),
            'paragraphs' => array_values((array) $t->paragraphs),
            'segments' => array_values((array) $t->segments),
            'text' => TranscriptExport::text((array) $t->paragraphs, count($letters) > 1 ? fn ($l) => $t->speakerName($l, $arabic) : null),
            'translations' => array_keys((array) ($t->translations ?? [])),
        ];
    }

    /** @return array<string, string> */
    public static function urls(Transcription $t): array
    {
        $urls = [
            'show' => route('transcribe.show', $t),
            'update' => route('transcribe.update', $t),
            'delete' => route('transcribe.destroy', $t),
            'translate' => route('transcribe.translate', $t),
            'audio' => route('transcribe.audio', $t),
        ];
        foreach (TranscriptExport::FORMATS as $format) {
            $urls['export_'.$format] = route('transcribe.export', [$t, $format]);
        }

        return $urls;
    }

    /**
     * The member's minutes this month, as the page's header shows them.
     *
     * @return array<string, mixed>
     */
    public static function account(User $user, bool $arabic): array
    {
        $locale = $arabic ? 'ar' : 'en';
        $b = TranscriptionAccess::balance($user);
        $plan = $b['plan'];
        $next = TranscriptionAccess::nextPlanThatFits($plan, $b['allowance'] + 60, 0);

        return [
            'included' => $b['included'],
            'allowance_seconds' => $b['allowance'],
            'used_seconds' => $b['used'],
            'left_seconds' => $b['left'],
            'allowance' => Minutes::format($b['allowance'], $locale),
            'used' => Minutes::format($b['used'], $locale),
            'left' => Minutes::format($b['left'], $locale),
            'resets_at' => $b['resets_at']->toIso8601String(),
            'resets' => $b['resets_at']->copy()->locale($locale)->isoFormat('D MMMM'),
            'max_file_seconds' => $b['included'] ? TranscriptionAccess::maxFileMinutes($plan) * 60 : TranscriptionSettings::freeTrySeconds(),
            'free_try' => ! $b['included'] && TranscriptionAccess::freeTryLeft($user),
            'free_try_seconds' => TranscriptionSettings::freeTrySeconds(),
            'upgrade' => TranscriptionAccess::upgrade($b['included'] ? $next : TranscriptionAccess::nextPlanThatFits($plan, 60, 0), $arabic),
        ];
    }

    public static function languageName(string $code, bool $arabic): string
    {
        $row = TranscriptionSettings::LANGUAGES[$code] ?? null;
        if ($row !== null) {
            return $arabic ? $row[1] : $row[0];
        }
        $name = class_exists(\Locale::class) ? (string) \Locale::getDisplayLanguage($code, $arabic ? 'ar' : 'en') : '';

        return $name !== '' ? $name : $code;
    }
}
