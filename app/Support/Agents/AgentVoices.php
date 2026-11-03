<?php

namespace App\Support\Agents;

use App\Models\Voice;
use App\Services\Tts\GeminiTtsProvider;
use App\Support\ArabicDialects;
use App\Support\SpeakerAvatar;
use App\Support\SpeakerVoices;
use App\Support\VoiceCatalog;
use App\Support\VoiceFlag;
use App\Support\VoicePreviews;

/**
 * The voice library speakers an agent can speak as: the premium speakers
 * whose voice is one of the studio voices the live calls use (a speaker on a
 * library voice cannot speak live), with their face, dialect and preview clip.
 * Children never answer a business's calls.
 */
final class AgentVoices
{
    /**
     * @return array<int, array{id: string, name: string, gender: string, age: ?string, language: string,
     *               dialect: string, dialect_label: string, flag: ?string, avatar: ?string, preview: ?string, voice: string}>
     */
    public static function all(string $language, bool $arabicUi): array
    {
        $out = [];
        $voices = Voice::query()->where('is_active', true)->where('tier', 'hd')
            ->where('language', $language)->orderByDesc('is_featured')->orderBy('id')->get();
        foreach ($voices as $voice) {
            $live = SpeakerVoices::find((string) $voice->internal_voice_id);
            if ($live === null || ! in_array($live, GeminiTtsProvider::VOICES, true)) {
                continue;
            }
            $age = SpeakerVoices::ageOf((string) $voice->internal_voice_id);
            if ($age === 'child') {
                continue;
            }
            $clip = VoicePreviews::find((string) $voice->display_name, (string) $voice->language);
            $dialect = trim((string) $voice->dialect) ?: (string) ($clip['dialect'] ?? '');
            $female = strtolower((string) $voice->gender) === 'female';
            $out[] = [
                'id' => (string) $voice->internal_voice_id,
                'name' => $arabicUi && $language === 'ar' ? VoiceCatalog::arabicName((string) $voice->display_name) : (string) $voice->display_name,
                'gender' => $female ? 'female' : 'male',
                'age' => $age,
                'language' => (string) $voice->language,
                'dialect' => $dialect,
                'dialect_label' => $dialect !== '' ? VoicePreviews::label($dialect, $arabicUi, $female) : '',
                'flag' => $dialect !== '' ? VoiceFlag::url($dialect, $voice->country) : null,
                'avatar' => SpeakerAvatar::url((string) $voice->display_name, (string) $voice->language),
                'preview' => $clip['url'] ?? null,
                'voice' => $live,
            ];
        }

        return $out;
    }

    /** Speakers of the same dialect first, then the same region, then the rest. */
    public static function suggested(array $voices, ?string $dialect, int $limit = 6): array
    {
        // An Arabic dialect's region, or an English accent's country.
        $groupOf = fn (?string $d) => ArabicDialects::ALL[$d ?? '']['region'] ?? (\App\Support\EnglishAccents::ALL[$d ?? '']['country'] ?? null);
        $region = $groupOf($dialect);
        $score = function (array $v) use ($dialect, $region, $groupOf): int {
            if ($dialect !== null && $v['dialect'] === $dialect) {
                return 0;
            }
            if ($region !== null && $groupOf($v['dialect']) === $region) {
                return 1;
            }

            return 2;
        };
        usort($voices, fn ($a, $b) => $score($a) <=> $score($b));

        return array_slice($voices, 0, $limit);
    }

    /** The speaker with this id, if an agent may speak as them. */
    public static function find(string $id, string $language, bool $arabicUi): ?array
    {
        foreach (self::all($language, $arabicUi) as $voice) {
            if ($voice['id'] === $id) {
                return $voice;
            }
        }

        return null;
    }
}
