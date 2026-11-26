<?php

namespace App\Support\Transcription;

use App\Models\Transcription;
use App\Models\User;
use App\Services\Transcription\TranscriptExport;
use App\Services\Transcription\TranscriptionRefused;
use App\Support\Api\ApiError;

/**
 * Speech to text as the developer API (/v1/transcriptions) and the MCP tools
 * give it: field names in English and in the API's own words ("completed",
 * not the page's "ready"), times in seconds, and a refusal turned into the
 * API's error with the same message the page shows.
 */
final class TranscriptionApi
{
    /** The statuses the API names, in the order a transcript goes through them. */
    public const STATUSES = ['queued', 'processing', 'completed', 'failed'];

    /** The refusal codes of TranscriptionFlow, as the API names them, with the request field at fault. */
    private const CODES = [
        'not_open' => ['speech_to_text_not_available', null],
        'not_in_plan' => ['speech_to_text_not_in_plan', null],
        'not_enough' => ['minutes_exceeded', null],
        'too_long' => ['audio_too_long', 'file'],
        'too_big' => ['file_too_large', 'file'],
        'too_short' => ['audio_too_short', 'file'],
        'no_audio' => ['no_audio_found', 'file'],
        'bad_link' => ['url_not_fetchable', 'url'],
        'busy' => ['too_many_transcriptions', null],
        'site_busy' => ['service_busy', null],
    ];

    /** Refusals that pass by themselves (the member's or the site's transcriptions are all busy), and how long to wait. */
    private const WAIT = ['busy', 'site_busy'];

    public const WAIT_SECONDS = 30;

    public static function status(string $status): string
    {
        return $status === Transcription::READY ? 'completed' : $status;
    }

    public static function modelStatus(string $status): string
    {
        return $status === 'completed' ? Transcription::READY : $status;
    }

    /**
     * One transcript. $full adds its words: the speakers, the whole text, the
     * paragraphs and sentences with their times, and where to download it.
     *
     * @return array<string, mixed>
     */
    public static function item(Transcription $t, bool $full = false): array
    {
        $language = (string) ($t->language ?: ($t->language_requested !== 'auto' ? $t->language_requested : ''));
        $summary = TranscriptionPresenter::summary($t, false, false);
        $ready = $t->status === Transcription::READY;

        $item = [
            'transcription_id' => $t->code,
            'title' => $t->title,
            'status' => self::status($t->status),
            'source' => $t->source,
            'language_code' => $language !== '' ? $language : null,
            'language_requested' => $t->language_requested,
            'duration_seconds' => round((float) $t->seconds, 2),
            // What it took from the month's minutes: every second of the audio, nothing for one that failed.
            'seconds_used' => $t->usedSeconds(),
            'free_try' => (bool) $t->is_free,
            'progress' => (int) $summary['progress'],
            'speaker_count' => (int) $summary['speakers'],
            'created_at' => $t->created_at?->toIso8601String(),
            'completed_at' => $ready ? $t->finished_at?->toIso8601String() : null,
            'error' => $t->status === Transcription::FAILED
                ? ['code' => (string) ($t->error_code ?: 'transcription_failed'), 'message' => (string) $summary['error']]
                : null,
        ];
        if (! $full) {
            return $item;
        }

        $paragraphs = array_values((array) $t->paragraphs);
        $letters = array_values(array_unique(array_column($paragraphs, 'speaker')));
        $time = static fn ($value): ?float => is_numeric($value) ? round((float) $value, 2) : null;

        return $item + [
            'speakers' => array_map(fn (string $l): array => ['speaker_id' => $l, 'name' => $t->speakerName($l)], $letters),
            'text' => $ready ? rtrim(TranscriptExport::text($paragraphs, count($letters) > 1 ? fn ($l) => $t->speakerName($l) : null)) : null,
            'paragraphs' => array_map(fn (array $p): array => [
                'speaker_id' => (string) $p['speaker'],
                'text' => (string) $p['text'],
                'start' => $time($p['start'] ?? null),
                'end' => $time($p['end'] ?? null),
            ], $paragraphs),
            'sentences' => array_map(fn (array $s): array => [
                'speaker_id' => (string) ($s['speaker'] ?? ''),
                'text' => (string) $s['text'],
                'start' => $time($s['start'] ?? null),
                'end' => $time($s['end'] ?? null),
            ], array_values((array) $t->segments)),
            'translations' => array_keys((array) ($t->translations ?? [])),
            'download_urls' => $ready ? self::downloadUrls($t) : null,
        ];
    }

    /** @return array<string, string> */
    public static function downloadUrls(Transcription $t): array
    {
        $urls = [];
        foreach (TranscriptExport::FORMATS as $format) {
            $urls[$format] = url('/v1/transcriptions/'.$t->code.'/export?format='.$format);
        }

        return $urls;
    }

    /**
     * The member's speech to text minutes this month, in seconds.
     *
     * @return array<string, mixed>
     */
    public static function usage(User $user): array
    {
        $b = TranscriptionAccess::balance($user);

        return [
            'included_in_plan' => (bool) $b['included'],
            'seconds_included' => (int) $b['allowance'],
            'seconds_used' => (int) $b['used'],
            'seconds_left' => (int) $b['left'],
            'max_file_seconds' => $b['included'] ? TranscriptionAccess::maxFileMinutes($b['plan']) * 60 : 0,
            'files_at_once' => $b['included'] ? TranscriptionAccess::concurrentJobs($b['plan']) : 0,
            'resets_at' => $b['resets_at']->toIso8601String(),
        ];
    }

    /** A refusal of TranscriptionFlow as the API's error, with the plan that would take it. */
    public static function error(TranscriptionRefused $e): ApiError
    {
        [$code, $param] = self::CODES[$e->reason] ?? [$e->reason, null];
        $type = match (true) {
            $e->status === 402 => ApiError::TYPE_QUOTA,
            $e->status === 403 => ApiError::TYPE_PERMISSION,
            $e->status === 429 => ApiError::TYPE_RATE_LIMIT,
            $e->status >= 500 => ApiError::TYPE_API,
            default => ApiError::TYPE_INVALID_REQUEST,
        };
        $upgrade = ($e->upgrade['plan'] ?? null) !== null
            ? ['upgrade_plan' => (string) $e->upgrade['plan'], 'upgrade_url' => (string) ($e->upgrade['url'] ?? '')]
            : [];

        // The two refusals that waiting solves say how long to wait.
        $headers = in_array($e->reason, self::WAIT, true) ? ['Retry-After' => (string) self::WAIT_SECONDS] : [];

        return new ApiError($e->status, $type, $code, $e->getMessage(), $param, $headers, $e->extra + $upgrade);
    }
}
