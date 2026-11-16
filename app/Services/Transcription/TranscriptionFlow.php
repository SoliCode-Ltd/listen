<?php

namespace App\Services\Transcription;

use App\Jobs\TranscribeJob;
use App\Models\Plan;
use App\Models\Transcription;
use App\Models\User;
use App\Support\Transcription\Minutes;
use App\Support\Transcription\TranscriptionAccess;
use App\Support\Transcription\TranscriptionSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * A transcript from start to end, the same on the page, the API and MCP:
 *  - start(): every rule checked before anything is used (the file has sound,
 *    the plan's longest file, files at once, the site's day, the month's
 *    minutes, or the free try), then the seconds it uses are HELD from the
 *    month's minutes, the file kept, and the work queued (TranscribeJob);
 *  - finish(): the transcript saved; the held seconds are what it used;
 *  - fail(): stopped with a reason, and the held seconds GIVEN BACK, once.
 * A file uses every second of its audio (TranscriptionAccess::chargeFor()):
 * what the page shows before it starts is what it uses.
 */
final class TranscriptionFlow
{
    public const DISK = 'local';

    /** A file up to this long is worked through at once, without waiting for a queue worker. */
    public const RIGHT_AWAY_SECONDS = 180.0;

    /**
     * $meta: title, source (upload, record, url, api, mcp, demo), source_url,
     * language ('auto' or a code), dialect, api_key_id. The file at $path is
     * moved into the transcript's own folder.
     *
     * @param  array<string, mixed>  $meta
     *
     * @throws TranscriptionRefused
     */
    public function start(User $user, string $path, array $meta): Transcription
    {
        $arabic = app()->getLocale() === 'ar';
        if (! TranscriptionAccess::openTo($user)) {
            throw new TranscriptionRefused('not_open', __('transcribe.refused.not_open'), 403);
        }
        if (! is_file($path)) {
            throw new TranscriptionRefused('no_audio', __('transcribe.refused.no_audio'));
        }
        $mb = TranscriptionSettings::maxUploadMb();
        if (filesize($path) > $mb * 1024 * 1024) {
            throw new TranscriptionRefused('too_big', __('transcribe.refused.too_big', ['mb' => $mb]), 413);
        }
        if (! Transcriber::hasAudio($path)) {
            throw new TranscriptionRefused('no_audio', __('transcribe.refused.no_audio'));
        }
        $seconds = Transcriber::lengthOf($path);
        if ($seconds < 0.5) {
            throw new TranscriptionRefused('too_short', __('transcribe.refused.too_short'));
        }
        $charge = TranscriptionAccess::chargeFor($seconds);
        $plan = TranscriptionAccess::planOf($user);
        $free = $this->checks($user, $plan, $charge, (string) ($meta['source'] ?? 'upload'), $arabic);

        $language = strtolower(trim((string) ($meta['language'] ?? 'auto'))) ?: 'auto';
        if ($language !== 'auto' && ! array_key_exists($language, TranscriptionSettings::LANGUAGES)) {
            $language = 'auto';
        }

        // Held under a lock on the member, so two files at once cannot both take the last minutes.
        $transcription = DB::transaction(function () use ($user, $plan, $charge, $free, $seconds, $language, $meta, $arabic): Transcription {
            User::query()->whereKey($user->id)->lockForUpdate()->first();
            $balance = TranscriptionAccess::balance($user);
            if (! $free) {
                self::assertFits($balance, $plan, $charge, $arabic);
            }

            return Transcription::create([
                'user_id' => $user->id,
                'title' => mb_substr(trim((string) ($meta['title'] ?? '')) ?: 'Recording', 0, 200),
                'source' => in_array($meta['source'] ?? '', Transcription::SOURCES, true) ? $meta['source'] : 'upload',
                'source_url' => isset($meta['source_url']) ? mb_substr((string) $meta['source_url'], 0, 2048) : null,
                'seconds' => round($seconds, 2),
                'language_requested' => $language,
                'dialect' => ($meta['dialect'] ?? '') !== '' ? mb_substr((string) $meta['dialect'], 0, 16) : null,
                'status' => Transcription::QUEUED,
                'charged_seconds' => $charge,
                'is_free' => $free,
                'period_ym' => $balance['period'],
                'priority' => ! $free && (bool) ($plan?->transcription_priority ?? false),
                'api_key_id' => $meta['api_key_id'] ?? null,
            ]);
        });

        $folder = self::folder($transcription);
        $source = $folder.'/source.'.(strtolower(pathinfo((string) ($meta['file_name'] ?? $path), PATHINFO_EXTENSION)) ?: 'audio');
        Storage::disk(self::DISK)->makeDirectory($folder);
        if (! @rename($path, Storage::disk(self::DISK)->path($source)) && ! @copy($path, Storage::disk(self::DISK)->path($source))) {
            $this->fail($transcription, 'unreadable', __('transcribe.failed.unreadable'));
            throw new TranscriptionRefused('no_audio', __('transcribe.refused.no_audio'));
        }
        @unlink($path);
        $transcription->forceFill(['audio_path' => $source])->save();

        TranscribeJob::queueFor($transcription);
        // A short file is worked through as soon as the answer has gone back, in this process,
        // so it is not kept waiting for a queue worker (the queued job is its safety net).
        if ((float) $transcription->seconds <= self::RIGHT_AWAY_SECONDS && config('queue.default') !== 'sync') {
            $id = $transcription->id;
            app()->terminating(fn () => TranscribeJob::runNow($id));
        }

        return $transcription;
    }

    /**
     * What a file of $seconds would use, checked against every rule before it
     * is sent (the page asks as soon as a file is chosen): the seconds it
     * uses, the member's seconds left and what would remain, and whether it
     * is the free try. Nothing is held.
     *
     * @return array{seconds: int, left: int, remain: int, free: bool, resets_at: string}
     *
     * @throws TranscriptionRefused
     */
    public function check(User $user, float $seconds, string $source = 'upload'): array
    {
        $arabic = app()->getLocale() === 'ar';
        if (! TranscriptionAccess::openTo($user)) {
            throw new TranscriptionRefused('not_open', __('transcribe.refused.not_open'), 403);
        }
        $charge = TranscriptionAccess::chargeFor($seconds);
        $plan = TranscriptionAccess::planOf($user);
        $free = $this->checks($user, $plan, $charge, $source, $arabic);
        $balance = TranscriptionAccess::balance($user);
        if (! $free) {
            self::assertFits($balance, $plan, $charge, $arabic);
        }

        return ['seconds' => $charge, 'left' => $balance['left'], 'remain' => $free ? $balance['left'] : $balance['left'] - $charge,
            'free' => $free, 'resets_at' => $balance['resets_at']->toIso8601String()];
    }

    /**
     * The rules a file meets before anything is used: the plan (or the free
     * try), files at once, the site's day. Returns true for the free try.
     *
     * @throws TranscriptionRefused
     */
    private function checks(User $user, ?Plan $plan, int $charge, string $source, bool $arabic): bool
    {
        $free = $this->checkPlan($user, $plan, $charge, $source, $arabic);
        $working = TranscriptionAccess::working($user);
        if ($working >= TranscriptionAccess::concurrentJobs($plan)) {
            throw new TranscriptionRefused('busy', trans_choice('transcribe.refused.busy', $working, ['n' => $working]), 429);
        }
        $siteLeft = TranscriptionAccess::siteSecondsLeftToday();
        if ($siteLeft !== null && $siteLeft < $charge) {
            throw new TranscriptionRefused('site_busy', __('transcribe.refused.site_busy'), 503);
        }

        return $free;
    }

    /**
     * Enough of the month's minutes left for the file, or the refusal naming the plan with more.
     *
     * @param  array<string, mixed>  $balance  TranscriptionAccess::balance()
     *
     * @throws TranscriptionRefused
     */
    private static function assertFits(array $balance, ?Plan $plan, int $charge, bool $arabic): void
    {
        if ($balance['left'] >= $charge) {
            return;
        }
        $next = TranscriptionAccess::nextPlanThatFits($plan, $balance['used'] + $charge, $charge);
        $words = ['need' => Minutes::format($charge), 'left' => Minutes::format($balance['left']),
            'date' => $balance['resets_at']->locale($arabic ? 'ar' : 'en')->isoFormat('D MMMM'), 'plan' => $next ? self::planName($next, $arabic) : ''];
        throw new TranscriptionRefused('not_enough', __($next ? 'transcribe.refused.not_enough' : 'transcribe.refused.not_enough_any', $words), 402,
            TranscriptionAccess::upgrade($next, $arabic), ['seconds_needed' => $charge, 'seconds_left' => $balance['left']]);
    }

    /**
     * Whether the plan takes this file, or the free try does. Returns true for the free try.
     *
     * @throws TranscriptionRefused
     */
    private function checkPlan(User $user, ?Plan $plan, int $charge, string $source, bool $arabic): bool
    {
        if (TranscriptionAccess::planIncludes($plan)) {
            $max = TranscriptionAccess::maxFileMinutes($plan) * 60;
            if ($charge > $max) {
                $next = TranscriptionAccess::nextPlanThatFits($plan, 0, $charge);
                throw new TranscriptionRefused('too_long', __($next ? 'transcribe.refused.too_long_plan' : 'transcribe.refused.too_long_any',
                    ['length' => Minutes::format($charge), 'max' => Minutes::format($max), 'plan' => $next ? self::planName($next, $arabic) : '']), 422,
                    TranscriptionAccess::upgrade($next, $arabic), ['seconds' => $charge, 'max_seconds' => $max]);
            }

            return false;
        }

        // No speech to text on the plan: the free try, on the page only (the API and MCP need a plan with it).
        $next = TranscriptionAccess::nextPlanThatFits($plan, $charge, $charge);
        $upgrade = TranscriptionAccess::upgrade($next, $arabic);
        $name = $next ? self::planName($next, $arabic) : '';
        if (in_array($source, ['api', 'mcp'], true) || TranscriptionSettings::freeTrySeconds() <= 0) {
            throw new TranscriptionRefused('not_in_plan', __($next ? 'transcribe.refused.not_in_plan' : 'transcribe.refused.not_in_any_plan', ['plan' => $name]), 403, $upgrade);
        }
        if (! TranscriptionAccess::freeTryLeft($user)) {
            if (TranscriptionAccess::freeDayFull()) {
                throw new TranscriptionRefused('free_day_full', __('transcribe.refused.free_day_full'), 429, $upgrade);
            }
            throw new TranscriptionRefused('free_used', __($next ? 'transcribe.refused.free_used' : 'transcribe.refused.not_in_any_plan', ['plan' => $name]), 403, $upgrade);
        }
        $max = TranscriptionSettings::freeTrySeconds();
        if ($charge > $max) {
            throw new TranscriptionRefused('free_too_long', __('transcribe.refused.free_too_long',
                ['max' => Minutes::format($max), 'length' => Minutes::format($charge), 'plan' => $name ?: '—']), 422, $upgrade, ['seconds' => $charge, 'max_seconds' => $max]);
        }

        return true;
    }

    /**
     * The transcript done: its words, sentences and speakers saved. The
     * seconds held when it started are what it used.
     *
     * @param  array<string, mixed>  $result  Transcriber::result()
     */
    public function finish(Transcription $transcription, array $result): void
    {
        DB::transaction(function () use ($transcription, $result): void {
            $t = Transcription::withTrashed()->whereKey($transcription->id)->lockForUpdate()->first();
            if (! $t || ! $t->isWorking()) {
                return;
            }
            $t->forceFill([
                'status' => Transcription::READY,
                'language' => (string) ($result['language'] ?? '') ?: null,
                'paragraphs' => array_map(fn ($p) => ['speaker' => (string) $p['speaker'], 'text' => (string) $p['text'],
                    'start' => round((float) $p['start'], 2), 'end' => round((float) $p['end'], 2)], (array) $result['paragraphs']),
                'segments' => array_map(fn ($s) => ['start' => round((float) $s['start'], 2), 'end' => round((float) $s['end'], 2),
                    'speaker' => (string) $s['speaker'], 'text' => (string) $s['text'], 'paragraph' => (int) $s['paragraph']], (array) $result['segments']),
                'requests' => (int) ($result['requests'] ?? 0),
                'usage' => $result['usage'] ?? null,
                'cost_usd' => round(Transcriber::cost((array) ($result['usage'] ?? [])), 6),
                'model' => (string) ($result['model'] ?? '') ?: null,
                'notes' => array_slice(array_merge((array) ($result['notes'] ?? []),
                    ['times: '.(int) ($result['aligned'] ?? 0).' of '.(int) ($result['parts'] ?? $t->parts).' parts placed where heard']), -40),
                'work' => null,
                'parts_done' => (int) ($result['parts'] ?? $t->parts),
                'finished_at' => now(),
            ])->save();
        });
    }

    /**
     * Stopped with a reason, and the seconds held for it given back: once,
     * whatever calls it (the job, its retries, the stuck check, the admin).
     */
    public function fail(Transcription $transcription, string $code, string $message): bool
    {
        return DB::transaction(function () use ($transcription, $code, $message): bool {
            $t = Transcription::withTrashed()->whereKey($transcription->id)->lockForUpdate()->first();
            if (! $t || ! $t->isWorking()) {
                return false;
            }
            $notes = (array) ($t->notes ?? []);
            $notes[] = sprintf('failed (%s): %d s held, given back', $code, $t->charged_seconds);
            $t->forceFill([
                'status' => Transcription::FAILED,
                'error_code' => mb_substr($code, 0, 64),
                'error' => mb_substr($message, 0, 2000),
                'charged_seconds' => 0,
                'work' => null,
                'notes' => $notes,
                'finished_at' => now(),
            ])->save();

            return true;
        });
    }

    /** Where a transcript's audio is kept, on the private disk. */
    public static function folder(Transcription $transcription): string
    {
        return 'transcriptions/'.$transcription->created_at->format('Ymd').'/'.$transcription->code;
    }

    private static function planName(Plan $plan, bool $arabic): string
    {
        return $arabic ? ((string) ($plan->name_ar ?? '') ?: (string) $plan->name) : (string) $plan->name;
    }
}
