<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

/**
 * Dialogue mode, shared by the studio queue and the demo: the form rules for the
 * speakers, the checks that a dialogue can really be voiced, and the speaker list
 * the speech request takes.
 */
class DialogueSpeakers
{
    public const DIALECT_PATTERN = '/^[a-z]{2}-[A-Za-z]{2}(-[A-Za-z]{2,3})?$/';

    /**
     * Longest dialogue the studio, the demo and the extension take. A dialogue is
     * made in one request, and very long ones come back without audio. (The
     * developer API keeps its own documented limit, ApiSpeechService::DIALOGUE_MAX_TEXT.
     * Not 2,500 here: older studio pages hid any message with "500" in it.)
     */
    public const MAX_SCRIPT_CHARS = 2400;

    /**
     * @return array<string, array<int, string>>
     */
    public static function rules(): array
    {
        return [
            'is_dialogue' => ['nullable', 'boolean'],
            'dialogue_speakers' => ['nullable', 'array', 'max:6'],
            'dialogue_speakers.*.name' => ['required_with:dialogue_speakers', 'string', 'max:50'],
            'dialogue_speakers.*.voice' => ['required_with:dialogue_speakers', 'string', 'max:100'],
            'dialogue_speakers.*.dialect' => ['nullable', 'string', 'max:12', 'regex:'.self::DIALECT_PATTERN],
        ];
    }

    /**
     * A dialogue needs premium voices, two different speakers, and a script whose
     * lines start with the speakers' names ("Name: line"); otherwise it would come
     * out as one voice reading the names aloud.
     *
     * @param  array<int, mixed>  $speakers
     */
    public static function ensureUsable(string $tier, array $speakers, string $script, string $scriptField = 'content'): void
    {
        if ($tier === VoiceTier::STANDARD) {
            throw ValidationException::withMessages(['is_dialogue' => __('ui.backend.dialogue_needs_hd')]);
        }

        $names = [];
        foreach ($speakers as $speaker) {
            $name = trim((string) data_get($speaker, 'name'));
            $voice = trim((string) data_get($speaker, 'voice'));
            if ($name !== '' && $voice !== '') {
                $names[] = $name;
            }
        }
        if (count($names) < 2 || count(array_unique(array_map('mb_strtolower', $names))) < count($names)) {
            throw ValidationException::withMessages(['dialogue_speakers' => __('ui.backend.dialogue_needs_two_speakers')]);
        }

        foreach (preg_split("/\r\n|\n|\r/", $script) ?: [] as $line) {
            foreach ($names as $name) {
                if (str_starts_with(trim($line), $name.':')) {
                    return;
                }
            }
        }

        throw ValidationException::withMessages([$scriptField => __('ui.backend.dialogue_needs_names', ['name' => $names[0]])]);
    }

    /**
     * Refuses a dialogue longer than MAX_SCRIPT_CHARS, counted as it is charged,
     * before anything is reserved or spoken.
     */
    public static function ensureShortEnough(string $script, string $scriptField = 'content'): void
    {
        if (UsageEstimator::billableChars($script) <= self::MAX_SCRIPT_CHARS) {
            return;
        }

        throw ValidationException::withMessages([
            $scriptField => __('ui.backend.dialogue_too_long', ['max' => number_format(self::MAX_SCRIPT_CHARS)]),
        ]);
    }

    /**
     * The speakers as the speech request takes them: name, real voice name, gender
     * and, when set, their own dialect or accent (ar-SA, ar-EG-AL, en-GB).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function forSpeech(mixed $rawSpeakers, string $targetLanguage, string $tier, ?string $provider): array
    {
        if (! is_array($rawSpeakers)) {
            return [];
        }

        $normalized = [];

        foreach ($rawSpeakers as $speaker) {
            if (! is_array($speaker)) {
                continue;
            }

            $name = trim((string) ($speaker['name'] ?? $speaker['id'] ?? 'Speaker'));
            $requestedVoice = trim((string) ($speaker['voice_name'] ?? $speaker['voice'] ?? ''));
            $voiceName = $requestedVoice;

            if (preg_match('/^voice_\d+$/', $requestedVoice) === 1) {
                $voiceName = VoiceCatalog::nameFromKeyForLanguageAndProvider(
                    $targetLanguage,
                    $requestedVoice,
                    $tier,
                    $provider,
                ) ?: (VoiceCatalog::nameFromKeyForLanguage($targetLanguage, $requestedVoice, $tier) ?? '');
            }

            if ($voiceName === '') {
                continue;
            }

            $meta = VoiceCatalog::voiceMeta($voiceName);
            $entry = [
                'name' => $name !== '' ? $name : 'Speaker',
                'voice_name' => $voiceName,
                'gender' => is_array($meta) ? ($meta['gender'] ?? null) : null,
            ];
            $dialect = trim((string) ($speaker['dialect'] ?? ''));
            if (preg_match(self::DIALECT_PATTERN, $dialect) === 1) {
                $entry['dialect'] = $dialect;
            }
            $normalized[] = $entry;
        }

        return $normalized;
    }
}
