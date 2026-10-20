<?php

namespace App\Support;

use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\VideoDub;
use App\Models\Voice;
use App\Services\DemoLinkedAccounts;
use App\Services\PlanAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Who may use "Dub my video", what it costs a plan, and the choices it offers.
 *
 * Until an admin opens it to everyone (Video Dubbing settings), only the admin
 * email sees the page, so the files can sit on the live site unseen. Anyone
 * signed in may then dub one video free, with the Listen mark (one per person:
 * accounts on the same browser or device share it, as the demo's do). A plan
 * with dubbing switched on pays in credits, one second of voice for each
 * second of video, up to its number of videos a month and its longest video,
 * and gets the copy without the mark.
 *
 * studio() is everything the dubbing studio shows a visitor (on its own page,
 * in the demo and on the dashboard): what they may do now and why not,
 * what they have left, and the studio's choices.
 */
final class VideoDubbing
{
    public const AUDIENCE_PREVIEW = 'preview';

    public const AUDIENCE_EVERYONE = 'everyone';

    public static function audience(): string
    {
        return SiteSetting::get('video_dubbing_audience', self::AUDIENCE_PREVIEW) === self::AUDIENCE_EVERYONE
            ? self::AUDIENCE_EVERYONE
            : self::AUDIENCE_PREVIEW;
    }

    /** Whether this visitor can see the page at all. */
    public static function openTo(?User $user): bool
    {
        return self::audience() === self::AUDIENCE_EVERYONE || VoiceCloningAccess::isPreviewAccount($user);
    }

    public static function planIncludes(?Plan $plan): bool
    {
        return (bool) ($plan?->video_dubbing_enabled ?? false);
    }

    /** The cheapest plan on sale with dubbing, for the upgrade button. */
    public static function cheapestPlan(): ?Plan
    {
        return rescue(fn () => Plan::query()->subscriptionPlans()->where('is_active', true)->where('price_cents', '>', 0)
            ->where('video_dubbing_enabled', true)->orderBy('price_cents')->first(), null, false);
    }

    /**
     * What a dub reserves from a plan: a second of voice for each second of
     * video, as the studio charges a second of audio.
     *
     * @return array{chars:int,words:int,seconds:int,minutes:int,credits:int}
     */
    public static function estimate(float $videoSeconds): array
    {
        $seconds = max(1, (int) ceil($videoSeconds));

        return ['chars' => $seconds * Credits::CHARS_PER_SECOND, 'words' => 0, 'seconds' => $seconds,
            'minutes' => (int) ceil($seconds / 60), 'credits' => Credits::forSeconds($seconds)];
    }

    /**
     * Whether this person has had their free dub: this account, any account
     * linked to it, or this browser or device. A failed one does not count,
     * nor one an admin removed (to let them dub again).
     */
    public static function freeUsed(User $user, Request $request): bool
    {
        $ids = rescue(fn () => app(DemoLinkedAccounts::class)->linkedUserIds($user, $request), [$user->id], false);
        $browser = DemoLinkedAccounts::browserFrom($request);
        $device = DemoLinkedAccounts::deviceFrom($request)['signal'] ?? null;

        return VideoDub::query()
            ->where('is_free', true)
            ->whereNotIn('status', VideoDub::GIVEN_BACK)
            ->where(function ($q) use ($ids, $user, $browser, $device): void {
                $q->whereIn('user_id', array_values(array_unique([...$ids, $user->id])));
                if ($browser !== null) {
                    $q->orWhere('browser', $browser);
                }
                if ($device !== null) {
                    $q->orWhere('device', $device);
                }
            })
            ->exists();
    }

    /** Whether today's free dubs for the whole site are used up. */
    public static function freeDayFull(): bool
    {
        $limit = (int) config('listen.dubbing.free_per_day', 50);

        return $limit > 0 && VideoDub::query()->where('is_free', true)->whereNotIn('status', VideoDub::GIVEN_BACK)
            ->where('created_at', '>=', now()->startOfDay())->count() >= $limit;
    }

    /** The languages a video can be dubbed into, as the text studio offers them. */
    public const LANGUAGES = ['ar', 'en'];

    /**
     * The studio's premium voices in a language (the speech engine dubbing
     * uses), as the studio's voice library lists them: avatar, gender, preview.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function voices(string $language = 'ar'): array
    {
        $voices = VoiceCatalog::publicCatalog(VoiceTier::HD, 'gemini')[$language] ?? [];

        return array_values(array_filter($voices, fn (array $voice): bool => ! str_starts_with((string) $voice['key'], 'cloned_')));
    }

    /** The speech engine's name for a voice of the library, or null when it is not one dubbing offers in that language. */
    public static function voiceName(?string $voiceKey, string $language = 'ar'): ?string
    {
        $voice = VoiceCatalog::findByKeyForLanguage($voiceKey, $language, VoiceTier::HD, 'gemini');

        return $voice ? VoiceCatalog::providerVoiceIdForName((string) $voice->internal_voice_id) : null;
    }

    /** The voice the library starts on: the studio's default premium voice in the language, else the first. */
    public static function defaultVoiceKey(string $language = 'ar'): ?string
    {
        $voices = self::voices($language);
        $default = VoiceCatalog::voiceMeta(VoiceCatalog::defaultForLanguageAndProvider($language, VoiceTier::HD, 'gemini'))['key'] ?? null;

        return collect($voices)->firstWhere('key', $default)['key'] ?? ($voices[0]['key'] ?? null);
    }

    /**
     * Every Arabic dialect, or every English accent, for the studio's dialect picker.
     *
     * @return array<string, string>
     */
    public static function dialects(bool $arabic, string $language = 'ar'): array
    {
        return array_map(fn (array $row): string => $arabic ? $row['ar'] : $row['en'], $language === 'en' ? EnglishAccents::ALL : ArabicDialects::ALL);
    }

    /**
     * About what a finished dub cost us, in US dollars, from what its report
     * counted (listen.dubbing.prices): the text model's tokens and the
     * seconds of voice. Null when it has no report (it failed early).
     *
     * @param  array<string, mixed>|null  $report
     * @return array{model: float, voice: float, total: float}|null
     */
    public static function cost(?array $report): ?array
    {
        if (! isset($report['transcribe'], $report['voicing'])) {
            return null;
        }
        // With defaults: a server whose settings were cached before the prices existed still works them out.
        $p = (array) config('listen.dubbing.prices', []) + ['text_in' => 0.5, 'audio_in' => 1.0, 'text_out' => 3.0, 'voice_out' => 9.0, 'voice_tokens_per_second' => 25];
        $t = (array) $report['transcribe'];
        $audio = (int) ($t['audio_tokens'] ?? 0);
        $model = (max(0, (int) ($t['input_tokens'] ?? 0) - $audio) * (float) $p['text_in'] + $audio * (float) $p['audio_in']
            + ((int) ($t['output_tokens'] ?? 0) + (int) ($t['thinking_tokens'] ?? 0)) * (float) $p['text_out']) / 1e6;
        $voice = (float) ($report['voicing']['voiced_seconds'] ?? 0) * (int) $p['voice_tokens_per_second'] * (float) $p['voice_out'] / 1e6;

        return ['model' => round($model, 5), 'voice' => round($voice, 5), 'total' => round($model + $voice, 5)];
    }

    /**
     * The largest video the admin allows (Video Dubbing → Settings), in MB:
     * for members on a plan with dubbing, or for the free dub (the demo's
     * visitors), which is the plans' size until the admin sets its own.
     */
    public static function uploadSettingMb(bool $free = false): int
    {
        $plans = max(1, (int) SiteSetting::get('video_dubbing_max_upload_mb', (int) config('listen.dubbing.max_upload_mb', 300)));

        return $free ? max(1, (int) SiteSetting::get('video_dubbing_free_max_upload_mb', $plans)) : $plans;
    }

    /**
     * What this server's PHP takes in one upload, in MB: the smaller of
     * upload_max_filesize and post_max_size less 1 MB for the rest of the
     * form; null when it sets no limit.
     */
    public static function serverUploadMb(): ?int
    {
        $limits = array_filter(array_map(function (string $key): ?int {
            $value = trim((string) ini_get($key));
            if ($value === '' || $value === '0' || $value === '-1') {
                return null;
            }
            $number = (float) $value;
            $bytes = match (strtoupper(substr($value, -1))) {
                'G' => $number * 1024 ** 3,
                'M' => $number * 1024 ** 2,
                'K' => $number * 1024,
                default => $number,
            };

            $mb = (int) floor($bytes / 1024 / 1024);

            return $key === 'post_max_size' ? $mb - 1 : $mb;
        }, ['upload_max_filesize', 'post_max_size']), fn (?int $mb): bool => $mb !== null);

        return $limits === [] ? null : max(1, min($limits));
    }

    /**
     * The largest video a member can send: the admin's setting. The page
     * sends it in pieces (chunkBytes()), so no limit of the server's or of
     * anything in front of it on one request's size stands in the way.
     */
    public static function maxUploadMb(bool $free = false): int
    {
        return self::uploadSettingMb($free);
    }

    /** The size of each piece the page sends a video in: 8 MB, or less where the server takes less in one request. */
    public static function chunkBytes(): int
    {
        $server = self::serverUploadMb();

        return max(1, min(8, $server === null ? 8 : $server - 1)) * 1024 * 1024;
    }

    /** The longest free video (Video Dubbing → Settings), in seconds; also a plan's when it sets none. */
    public static function freeMaxSeconds(): int
    {
        return max(5, min(180, (int) SiteSetting::get('video_dubbing_free_max_seconds', (int) config('listen.dubbing.max_seconds', 60))));
    }

    /** The longest video this dub may be: the plan's, or the site's for a free one. */
    public static function maxSeconds(?Plan $plan, bool $free): int
    {
        $site = self::freeMaxSeconds();

        return $free ? $site : max(5, min(180, (int) ($plan?->video_dub_max_seconds ?: $site)));
    }

    /** How long a free dub stays on the member's dashboard (Video Dubbing → Settings), in hours. */
    public static function freeKeepHours(): int
    {
        return max(1, min(168, (int) SiteSetting::get('video_dubbing_free_keep_hours', (int) config('listen.dubbing.free_keep_hours', 24))));
    }

    /**
     * With Google Drive off (Video Dubbing → Settings): days a video stays on
     * the server, counted from when it was made and never before its member's
     * time is over; 0 keeps them.
     */
    public static function serverKeepDays(): int
    {
        return max(0, min(3650, (int) SiteSetting::get('video_dubbing_server_keep_days', 30)));
    }

    /** Days a plan keeps a member's dubbed videos to watch: as long as it keeps their voices; 0 is no limit. */
    public static function keepDays(?Plan $plan): int
    {
        return max(0, (int) ($plan?->recent_voices_days ?? 7));
    }

    /**
     * Until when the member can watch and download a dub: a free one for
     * freeKeepHours(), one their plan paid for as long as their plan keeps
     * voices (null: no end). Past it a paid video stays in the site's Google
     * Drive (DriveArchive); the member no longer sees it. $planDays: the
     * member's plan's keepDays(), when the caller has it already.
     */
    public static function availableUntil(VideoDub $dub, ?int $planDays = null): ?\Illuminate\Support\Carbon
    {
        $made = $dub->created_at ?? now();
        if (! is_array($dub->reservation) || $dub->status !== VideoDub::READY) {
            return $made->copy()->addHours(self::freeKeepHours());
        }
        $days = $planDays ?? self::keepDaysFor((int) $dub->user_id);

        return $days > 0 ? $made->copy()->addDays($days) : null;
    }

    /** The member's plan's keepDays(). */
    public static function keepDaysFor(int $userId): int
    {
        return self::keepDays(app(PlanAccessService::class)->resolveAccessPlanForUserId($userId));
    }

    /** Whether the member can still watch and download it. */
    public static function available(VideoDub $dub, ?int $planDays = null): bool
    {
        $until = self::availableUntil($dub, $planDays);

        return $dub->status === VideoDub::READY
            && ($dub->drive_file_id !== null || $dub->files_deleted_at === null)
            && ($until === null || $until->isFuture());
    }

    /** Videos a month the plan allows; 0 when only its credits count. */
    public static function monthlyLimit(?Plan $plan): int
    {
        return max(0, (int) ($plan?->video_dubs_per_month ?? 0));
    }

    /** Paid dubs in the member's current credit month (failed ones gave their credits back and do not count). */
    public static function dubsThisMonth(int $userId): int
    {
        return VideoDub::query()->where('user_id', $userId)->where('is_free', false)->whereNotIn('status', VideoDub::GIVEN_BACK)
            ->where('period_ym', CreditPeriod::counter($userId)->period_ym)->count();
    }

    /**
     * Voices for the other people in a video, by gender (speech engine names):
     * those of the dub's dialect first, then the featured ones.
     *
     * @return array{male: array<int, string>, female: array<int, string>}
     */
    public static function voicePool(string $dialect, string $language = 'ar'): array
    {
        $pool = ['male' => [], 'female' => []];
        $voices = Voice::query()->where('is_active', true)->where('tier', VoiceTier::HD)->where('provider', 'gemini')->where('language', SpeechLanguages::voiceLanguage($language))
            ->orderByRaw('CASE WHEN dialect = ? THEN 0 ELSE 1 END', [$dialect])->orderByDesc('is_featured')->orderBy('sort_order')
            ->get(['gender', 'internal_voice_id', 'provider_voice_id']);
        foreach ($voices as $voice) {
            $gender = strtolower((string) $voice->gender) === 'female' ? 'female' : 'male';
            $name = trim((string) ($voice->provider_voice_id ?: $voice->internal_voice_id));
            if ($name !== '' && ! in_array($name, $pool[$gender], true)) {
                $pool[$gender][] = $name;
            }
        }

        return $pool;
    }

    /**
     * What the dubbing studio shows this visitor. state says what they can do:
     * guest (sign up first), verify (confirm the email), free (their free
     * dub), used (it is used and no plan includes dubbing), day_full (today's
     * free dubs are gone), paid (dub from the plan), limit (this month's
     * videos are used), no_credits (too few credits for the shortest video).
     *
     * @return array<string, mixed>
     */
    public static function studio(?User $user, Request $request): array
    {
        $arabic = app()->getLocale() === 'ar';
        $plans = app(PlanAccessService::class);
        $plan = $user ? $plans->resolveAccessPlanForUserId((int) $user->id) : null;
        $included = self::planIncludes($plan);
        $usage = null;
        $state = 'guest';
        if ($user && $included) {
            $quotaPlan = $plans->resolveQuotaPlanForUserId((int) $user->id, $plan);
            $snapshot = UsageSnapshot::for((int) $user->id, $quotaPlan, CreditPeriod::counter((int) $user->id));
            $limit = self::monthlyLimit($plan);
            $used = self::dubsThisMonth((int) $user->id);
            $credits = max(0, (int) $snapshot['remaining_chars']);
            $usage = ['dubs_used' => $used, 'dubs_limit' => $limit, 'credits_left' => $credits, 'seconds_left' => Credits::secondsFor($credits),
                'pack' => $snapshot['mode'] === 'pack'];
            $state = match (true) {
                $limit > 0 && $used >= $limit => 'limit',
                $credits < Credits::forSeconds((int) config('listen.dubbing.min_seconds', 3)) => 'no_credits',
                default => 'paid',
            };
        } elseif ($user) {
            $state = match (true) {
                EmailVerification::requiredForFreeUse($user) => 'verify',
                self::freeUsed($user, $request) => 'used',
                self::freeDayFull() => 'day_full',
                default => 'free',
            };
        }
        $upgrade = $included ? null : self::cheapestPlan();
        $languages = [];
        foreach (self::LANGUAGES as $language) {
            $languages[$language] = [
                'voices' => self::voices($language),
                'default_voice' => self::defaultVoiceKey($language),
                'dialects' => self::dialects($arabic, $language),
                'default_dialect' => TtsStudioConfig::defaultDialect($language) ?: ($language === 'en' ? 'en-US' : 'ar-SA'),
            ];
        }

        return [
            'state' => $state,
            'included' => $included,
            'free' => ! $included,
            'email' => $user?->email,
            'usage' => $usage,
            'upgrade' => $included ? ['url' => route('billing.page'), 'plan' => null] : [
                'url' => $upgrade ? route('billing.page', ['plan' => $upgrade->slug]) : route('pricing'),
                'plan' => $upgrade ? ($arabic && filled($upgrade->name_ar) ? (string) $upgrade->name_ar : (string) $upgrade->name) : null,
            ],
            'min_seconds' => (int) config('listen.dubbing.min_seconds', 3),
            'max_seconds' => self::maxSeconds($plan, ! $included),
            'max_mb' => self::maxUploadMb(! $included),
            // How long finished videos stay to watch: a free one for hours, a plan's as long as it keeps voices.
            'keep_hours' => self::freeKeepHours(),
            'keep_days' => $included ? self::keepDays($plan) : null,
            'credits_per_second' => Credits::PER_SECOND,
            'languages' => $languages,
            'language' => 'ar',
            'styles' => TtsStudioConfig::performanceStyleCatalog(),
            'emotions' => TtsStudioConfig::emotionCatalog(),
            'dubs' => $user ? self::recent($user) : [],
            'urls' => [
                'store' => route('dub.store'),
                'upload' => route('dub.upload.start'),
                'login' => route('login'),
                'register' => route('register'),
                'verify' => route('verification.send'),
            ],
        ];
    }

    /**
     * The dashboard's account of a member's dubbing in this credit month:
     * videos dubbed against the plan's number, voice time and credits they
     * used, what is left, and a row for every dub with what it took (a free
     * one and a failed one took nothing).
     *
     * @return array<string, mixed>
     */
    public static function account(User $user, array $studio): array
    {
        $period = CreditPeriod::current((int) $user->id);
        $arabic = app()->getLocale() === 'ar';
        $dubs = VideoDub::query()->where('user_id', $user->id)
            ->where(fn ($q) => $q->where('period_ym', $period['key'])->orWhere(fn ($q) => $q->whereNull('period_ym')->where('created_at', '>=', $period['starts_at'])))
            ->latest('id')->limit(200)->get();
        $days = self::keepDaysFor((int) $user->id);
        $rows = $dubs->map(function (VideoDub $dub) use ($arabic, $days): array {
            $charged = is_array($dub->reservation) && ! in_array($dub->status, VideoDub::GIVEN_BACK, true) ? (int) $dub->reservation['credits'] : 0;

            return self::present($dub, $days) + [
                'date' => $dub->created_at?->copy()->locale($arabic ? 'ar' : 'en')->isoFormat('D MMM, HH:mm'),
                'dialect_label' => VoiceFlag::label($dub->dialect, $arabic),
                'charged' => $charged,
            ];
        })->all();
        $credits = array_sum(array_column($rows, 'charged'));

        return [
            'rows' => $rows,
            'credits_used' => $credits,
            'seconds_used' => Credits::secondsFor($credits),
            'videos_used' => $dubs->where('is_free', false)->whereNotIn('status', VideoDub::GIVEN_BACK)->count(),
            'videos_limit' => (int) ($studio['usage']['dubs_limit'] ?? 0),
            'credits_left' => (int) ($studio['usage']['credits_left'] ?? 0),
            'seconds_left' => (int) ($studio['usage']['seconds_left'] ?? 0),
            'max_seconds' => (int) $studio['max_seconds'],
            'included' => (bool) $studio['included'],
            'period_ends' => $period['ends_at']->copy()->locale($arabic ? 'ar' : 'en')->isoFormat('D MMMM'),
        ];
    }

    /**
     * The member's dubs still to watch (and the one in progress, and a recent failed one).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function recent(User $user): array
    {
        $days = self::keepDaysFor((int) $user->id);

        return VideoDub::query()->where('user_id', $user->id)->where('status', '!=', VideoDub::REMOVED)
            ->where(fn ($q) => $q->whereNull('files_deleted_at')->orWhereNotNull('drive_file_id'))
            ->latest('id')->limit(40)->get()
            ->filter(fn (VideoDub $dub): bool => $dub->isWorking() || self::available($dub, $days)
                || ($dub->status === VideoDub::FAILED && $dub->created_at?->gt(now()->subHours(self::freeKeepHours()))))
            ->take(10)->map(fn (VideoDub $dub): array => self::present($dub, $days))->values()->all();
    }

    /**
     * A dub as the studio shows it: where it is, what it says, and its links.
     *
     * @return array<string, mixed>
     */
    public static function present(VideoDub $dub, ?int $planDays = null): array
    {
        $planDays ??= is_array($dub->reservation) && $dub->status === VideoDub::READY ? self::keepDaysFor((int) $dub->user_id) : 0;
        $ready = self::available($dub, $planDays);
        $until = self::availableUntil($dub, $planDays);
        $report = (array) ($dub->report ?? []);

        return [
            'id' => $dub->id,
            'status' => $dub->status,
            'stage' => $dub->stage,
            'progress' => (int) $dub->progress,
            'free' => $dub->is_free,
            'paid_clean' => is_array($dub->reservation),
            'name' => (string) $dub->original_name,
            'dialect' => $dub->dialect,
            'seconds' => (float) $dub->video_seconds,
            'credits' => is_array($dub->reservation) ? (int) $dub->reservation['credits'] : 0,
            'lines' => (int) ($report['segments'] ?? 0),
            'speakers' => count((array) ($report['speakers'] ?? [])),
            'kept_background' => (bool) ($report['kept_background'] ?? false),
            'error' => $dub->status === VideoDub::FAILED ? $dub->errorText() : null,
            'status_url' => route('dub.status', ['code' => $dub->code]),
            'watch_url' => $ready ? route('dub.watch', ['code' => $dub->code]) : null,
            'download_url' => $ready && $dub->has_clean ? route('dub.download', ['code' => $dub->code]) : null,
            'expires_at' => $until?->toIso8601String(),
        ];
    }

    /** The browser cookie that ties accounts made in one browser to one free dub, as the demo's does. */
    public static function withBrowserCookie(Request $request, Response $response): Response
    {
        if (DemoLinkedAccounts::browserFrom($request) === null) {
            $response->headers->setCookie(cookie(DemoLinkedAccounts::BROWSER_COOKIE, Str::random(48), 60 * 24 * 730, '/', null, $request->isSecure(), true, false, 'lax'));
        }

        return $response;
    }
}
