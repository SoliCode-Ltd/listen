<?php

namespace App\Support\Api;

use App\Models\ClonedVoice;
use App\Models\Plan;
use App\Models\User;
use App\Models\Voice;
use App\Support\VoiceCatalog;
use App\Support\VoiceCloningAccess;
use App\Support\VoiceFlag;
use App\Support\VoiceTier;

/**
 * The voices the developer API offers: the same speakers the dashboard shows
 * (for each tier, the voices of the engine the site currently prefers), plus
 * the member's own ready cloned voices when their plan includes cloning.
 * Voice ids are the dashboard's public keys (voice_{id}, cloned_{id}); the
 * engine's own voice names are never exposed.
 */
final class ApiVoiceCatalog
{
    /**
     * Every studio voice, keyed by voice id, in the dashboard's order.
     * $withMedia adds preview and avatar links (it looks for each preview file,
     * so generating leaves it out).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function premade(?string $planTier, bool $withMedia = true): array
    {
        $library = $withMedia ? VoiceCatalog::libraryEntries(false) : [];
        $ids = [];
        $arabicNames = [];
        foreach (VoiceCatalog::publicCatalogByTier() as $voicesByLanguage) {
            foreach ($voicesByLanguage as $voices) {
                foreach ($voices as $voice) {
                    $id = (int) substr((string) $voice['key'], strlen('voice_'));
                    $ids[] = $id;
                    $arabicNames[$id] = (string) ($voice['label_ar'] ?? '');
                }
            }
        }
        if ($ids === []) {
            return [];
        }

        $rows = Voice::query()->whereIn('id', $ids)->where('is_active', true)->get()->keyBy('id');
        $voices = [];
        foreach ($ids as $id) {
            $voice = $rows->get($id);
            if (! $voice instanceof Voice) {
                continue;
            }
            $key = VoiceCatalog::keyFromId($id);
            $voices[$key] = self::presentPremade($voice, $library[$key] ?? [], $planTier, $arabicNames[$id] ?? '');
        }

        return $voices;
    }

    /**
     * The member's ready cloned voices, keyed by voice id (none when the plan lacks cloning).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function cloned(User $user, ?Plan $plan): array
    {
        if (! VoiceCloningAccess::allows($user, $plan)) {
            return [];
        }

        $voices = [];
        foreach (ClonedVoice::query()->where('user_id', $user->id)->ready()->orderBy('name')->get() as $voice) {
            $voices['cloned_'.$voice->id] = self::presentCloned($voice);
        }

        return $voices;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function forUser(User $user, ?Plan $plan, string $planTier): array
    {
        return self::premade($planTier) + self::cloned($user, $plan);
    }

    /**
     * One voice by id, as the API shows it, or null when there is none the member can see.
     *
     * @return array<string, mixed>|null
     */
    public static function find(User $user, ?Plan $plan, string $planTier, string $voiceId, bool $withMedia = true): ?array
    {
        $voiceId = trim($voiceId);
        if (preg_match('/^cloned_(\d+)$/', $voiceId, $m) === 1) {
            return self::cloned($user, $plan)['cloned_'.(int) $m[1]] ?? null;
        }
        if (preg_match('/^voice_(\d+)$/', $voiceId) !== 1) {
            return null;
        }

        return self::premade($planTier, $withMedia)[$voiceId] ?? null;
    }

    /**
     * @param  array<string, mixed>  $library  VoiceCatalog::libraryEntries() row
     * @return array<string, mixed>
     */
    private static function presentPremade(Voice $voice, array $library, ?string $planTier, string $arabicName): array
    {
        $tier = VoiceTier::normalize((string) $voice->tier);
        $displayName = (string) $voice->display_name;
        $dialect = trim((string) $voice->dialect);

        return [
            'voice_id' => VoiceCatalog::keyFromId((int) $voice->id),
            'name' => $displayName,
            'name_ar' => $arabicName !== '' ? $arabicName : $displayName,
            'category' => 'premade',
            'language' => (string) $voice->language,
            'gender' => strtolower((string) $voice->gender) ?: null,
            'accent' => $dialect !== '' ? (VoiceFlag::label($dialect, false) ?: null) : null,
            'model_id' => ApiModels::forTier($tier),
            'featured' => (bool) $voice->is_featured,
            'available_on_plan' => $planTier !== null && ! VoiceTier::isHigherThan($tier, $planTier),
            'preview_url' => self::absolute($library['p'] ?? null),
            'avatar_url' => self::absolute($library['a'] ?? null),
            // Internal: used when generating, removed before the voice is shown.
            '_internal_name' => (string) $voice->internal_voice_id,
            '_tier' => $tier,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function presentCloned(ClonedVoice $voice): array
    {
        $language = strtolower(substr((string) $voice->language_code, 0, 2));

        return [
            'voice_id' => 'cloned_'.$voice->id,
            'name' => (string) $voice->name,
            'name_ar' => (string) $voice->name,
            'category' => 'cloned',
            'language' => in_array($language, ['ar', 'en'], true) ? $language : null,
            'gender' => null,
            'accent' => null,
            'model_id' => ApiModels::PREMIUM,
            'featured' => false,
            'available_on_plan' => true,
            'preview_url' => null,
            'avatar_url' => null,
            '_internal_name' => 'cloned_'.$voice->id,
            '_tier' => VoiceTier::HD,
        ];
    }

    /**
     * A voice without its internal fields, ready for a response.
     *
     * @param  array<string, mixed>  $voice
     * @return array<string, mixed>
     */
    public static function public(array $voice): array
    {
        return array_filter($voice, static fn (string $key): bool => ! str_starts_with($key, '_'), ARRAY_FILTER_USE_KEY);
    }

    private static function absolute(mixed $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }

        return str_starts_with($url, 'http://') || str_starts_with($url, 'https://') ? $url : url($url);
    }
}
