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
     * linked to it, or this browser or device. A failed one does not count.
     */
    public static function freeUsed(User $user, Request $request): bool
    {
        $ids = rescue(fn () => app(DemoLinkedAccounts::class)->linkedUserIds($user, $request), [$user->id], false);
        $browser = DemoLinkedAccounts::browserFrom($request);
        $device = DemoLinkedAccounts::deviceFrom($request)['signal'] ?? null;

        return VideoDub::query()
            ->where('is_free', true)
            ->where('status', '!=', VideoDub::FAILED)
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

        return $limit > 0 && VideoDub::query()->where('is_free', true)->where('status', '!=', VideoDub::FAILED)
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

    /** The longest video this dub may be: the plan's, or the site's for a free one. */
    public static function maxSeconds(?Plan $plan, bool $free): int
    {
        $site = max(5, (int) config('listen.dubbing.max_seconds', 60));

        return $free ? $site : max(5, min(180, (int) ($plan?->video_dub_max_seconds ?: $site)));
    }

    /** Videos a month the plan allows; 0 when only its credits count. */
    public static function monthlyLimit(?Plan $plan): int
    {
        return max(0, (int) ($plan?->video_dubs_per_month ?? 0));
    }

    /** Paid dubs in the member's current credit month (failed ones gave their credits back and do not count). */
    public static function dubsThisMonth(int $userId): int
    {
        return VideoDub::query()->where('user_id', $userId)->where('is_free', false)->where('status', '!=', VideoDub::FAILED)
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
            'max_mb' => (int) config('listen.dubbing.max_upload_mb', 100),
            'keep_hours' => (int) config('listen.dubbing.keep_hours', 48),
            'credits_per_second' => Credits::PER_SECOND,
            'languages' => $languages,
            'language' => 'ar',
            'styles' => TtsStudioConfig::performanceStyleCatalog(),
            'emotions' => TtsStudioConfig::emotionCatalog(),
            'dubs' => $user ? self::recent($user) : [],
            'urls' => [
                'store' => route('dub.store'),
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
        $rows = $dubs->map(function (VideoDub $dub) use ($arabic): array {
            $charged = is_array($dub->reservation) && $dub->status !== VideoDub::FAILED ? (int) $dub->reservation['credits'] : 0;

            return self::present($dub) + [
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
            'videos_used' => $dubs->where('is_free', false)->where('status', '!=', VideoDub::FAILED)->count(),
            'videos_limit' => (int) ($studio['usage']['dubs_limit'] ?? 0),
            'credits_left' => (int) ($studio['usage']['credits_left'] ?? 0),
            'seconds_left' => (int) ($studio['usage']['seconds_left'] ?? 0),
            'max_seconds' => (int) $studio['max_seconds'],
            'included' => (bool) $studio['included'],
            'period_ends' => $period['ends_at']->copy()->locale($arabic ? 'ar' : 'en')->isoFormat('D MMMM'),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public static function recent(User $user): array
    {
        return VideoDub::query()->where('user_id', $user->id)->whereNull('files_deleted_at')
            ->where('created_at', '>=', now()->subHours((int) config('listen.dubbing.keep_hours', 48)))
            ->latest('id')->limit(10)->get()->map(fn (VideoDub $dub): array => self::present($dub))->all();
    }

    /**
     * A dub as the studio shows it: where it is, what it says, and its links.
     *
     * @return array<string, mixed>
     */
    public static function present(VideoDub $dub): array
    {
        $ready = $dub->status === VideoDub::READY && $dub->files_deleted_at === null;
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
            'status_url' => route('dub.status', ['id' => $dub->id]),
            'watch_url' => $ready ? route('dub.watch', ['id' => $dub->id]) : null,
            'download_url' => $ready && $dub->has_clean ? route('dub.download', ['id' => $dub->id]) : null,
            'expires_at' => $dub->created_at?->copy()->addHours((int) config('listen.dubbing.keep_hours', 48))->toIso8601String(),
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
