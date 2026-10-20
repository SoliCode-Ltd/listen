<?php

namespace App\Support;

use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\VideoDub;
use App\Services\DemoLinkedAccounts;
use Illuminate\Http\Request;

/**
 * Who may use "Dub my video", what it costs a plan, and the choices it offers.
 *
 * Until an admin opens it to everyone (Video Dubbing settings), only the admin
 * email sees the page, so the files can sit on the live site unseen. Anyone
 * signed in may then dub one video free, with the Listen mark (one per person:
 * accounts on the same browser or device share it, as the demo's do). A plan
 * with dubbing switched on pays in credits, one second of voice for each
 * second of video, and gets the copy without the mark.
 */
final class VideoDubbing
{
    public const AUDIENCE_PREVIEW = 'preview';

    public const AUDIENCE_EVERYONE = 'everyone';

    /** The voices offered: the studio's own names for the speech engine's voices. */
    public const VOICES = [
        'Puck' => ['en' => 'Othman', 'ar' => 'عثمان', 'gender' => 'male'],
        'Charon' => ['en' => 'Waleed', 'ar' => 'وليد', 'gender' => 'male'],
        'Orus' => ['en' => 'Abdulaziz', 'ar' => 'عبدالعزيز', 'gender' => 'male'],
        'Fenrir' => ['en' => 'Saud', 'ar' => 'سعود', 'gender' => 'male'],
        'Iapetus' => ['en' => 'Fahad', 'ar' => 'فهد', 'gender' => 'male'],
        'Leda' => ['en' => 'Noura', 'ar' => 'نورة', 'gender' => 'female'],
        'Kore' => ['en' => 'Lamya', 'ar' => 'لمياء', 'gender' => 'female'],
        'Aoede' => ['en' => 'Khadija', 'ar' => 'خديجة', 'gender' => 'female'],
        'Zephyr' => ['en' => 'Sawsan', 'ar' => 'سوسن', 'gender' => 'female'],
        'Despina' => ['en' => 'Maha', 'ar' => 'مها', 'gender' => 'female'],
    ];

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

    /**
     * Dialects grouped by region, for the picker.
     *
     * @return array<string, array{label: string, options: array<string, string>}>
     */
    public static function dialectGroups(bool $arabic): array
    {
        $groups = [];
        foreach (ArabicDialects::regions() as $region => $names) {
            $groups[$region] = ['label' => $arabic ? $names['ar'] : $names['en'], 'options' => []];
        }
        foreach (ArabicDialects::ALL as $code => $row) {
            $groups[$row['region']]['options'][$code] = $arabic ? $row['ar'] : $row['en'];
        }

        return array_filter($groups, fn (array $group): bool => $group['options'] !== []);
    }

    /** @return array<string, string> */
    public static function styles(bool $arabic): array
    {
        return self::labels(TtsStudioConfig::performanceStyleCatalog(), $arabic);
    }

    /** @return array<string, string> */
    public static function emotions(bool $arabic): array
    {
        return self::labels(TtsStudioConfig::emotionCatalog(), $arabic);
    }

    /**
     * @param  array<int, array{value: string, label: string, label_ar?: string}>  $rows
     * @return array<string, string>
     */
    private static function labels(array $rows, bool $arabic): array
    {
        $labels = [];
        foreach ($rows as $row) {
            $labels[$row['value']] = $arabic && filled($row['label_ar'] ?? null) ? (string) $row['label_ar'] : (string) $row['label'];
        }

        return $labels;
    }
}
