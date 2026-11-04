<?php

namespace App\Support\Agents;

use App\Models\Agent;
use App\Models\Voice;
use App\Services\Agents\AgentWriter;

/**
 * An agent's name and words follow its voice. When the owner picks a speaker of the other gender
 * than its words were written for (a woman's name and words on a man's voice), the agent takes the
 * speaker's name and its words are rewritten for that voice (the greeting, who it is, its rules,
 * steps and hand-off). An agent called by its old voice's name takes the new speaker's name. A name
 * of the owner's own that still fits the voice stays. Listen's own agent, while its words are still
 * Listen's, gets its own words for the new voice (HouseAgent::texts).
 */
final class AgentPersona
{
    /** The gender of the agent's voice (female or male), or null without one. */
    public static function voiceGender(Agent $agent): ?string
    {
        if (! $agent->speaker_id) {
            return null;
        }
        $gender = strtolower(trim((string) Voice::query()->where('internal_voice_id', $agent->speaker_id)->value('gender')));

        return $gender === '' ? null : ($gender === 'female' ? 'female' : 'male');
    }

    /**
     * After the owner picked another voice ($old: the one before, null on the first pick).
     *
     * @param  array{id: string, name: string, gender: string}|null  $old
     * @param  array{id: string, name: string, gender: string}  $new
     * @return bool whether the name (and with it the words) changed
     */
    public static function followVoice(Agent $agent, ?array $old, array $new, AgentWriter $writer): bool
    {
        $gender = ($new['gender'] ?? null) === 'female' ? 'female' : 'male';
        $wroteFor = $agent->setting('texts_gender') ?: ($old['gender'] ?? null);
        $name = trim((string) $agent->name);
        $toName = trim((string) ($new['name'] ?? ''));
        $otherGender = $wroteFor !== null && $wroteFor !== $gender;
        $calledByVoice = $old !== null && $name !== '' && mb_strtolower($name) === mb_strtolower(trim((string) ($old['name'] ?? '')));

        if ($toName === '' || ($old['id'] ?? null) === ($new['id'] ?? null) || (! $otherGender && ! $calledByVoice)) {
            // Words set up before their gender was kept: they fit this voice, so remember it.
            if (! $agent->setting('texts_gender') && $wroteFor !== null && ! $otherGender) {
                self::mark($agent, $wroteFor);
            }

            return false;
        }

        if ($agent->setting('house') && HouseAgent::textsUntouched($agent)) {
            HouseAgent::applyTexts($agent, $new);

            return $agent->isDirty('name');
        }

        if ($otherGender && ($words = $writer->regender($agent, $name, $toName, $gender))) {
            $agent->first_message = $words['first_message'];
            $agent->script = array_merge((array) ($agent->script ?? []), [
                'identity' => $words['identity'], 'never' => $words['never'], 'steps' => $words['steps'], 'handoff' => $words['handoff'],
            ]);
        } elseif ($name !== '') {
            // Without the text model (or the same gender): the new name where the old one stood.
            $agent->first_message = self::swap($agent->first_message, $name, $toName);
            $script = (array) ($agent->script ?? []);
            foreach (['identity', 'handoff'] as $key) {
                if (isset($script[$key])) {
                    $script[$key] = self::swap((string) $script[$key], $name, $toName);
                }
            }
            $agent->script = $script;
        }
        $agent->name = $toName;
        self::mark($agent, $gender);

        return true;
    }

    /** Which gender the agent's words are written for (null: not known yet). */
    private static function mark(Agent $agent, ?string $gender): void
    {
        if ($gender !== null) {
            $agent->settings = array_merge((array) ($agent->settings ?? []), ['texts_gender' => $gender]);
        }
    }

    /** The old name replaced by the new one where it stands as a whole word. */
    private static function swap(?string $text, string $from, string $to): ?string
    {
        if ($text === null || $text === '') {
            return $text;
        }

        return preg_replace('/(?<![\p{L}\p{N}])'.preg_quote($from, '/').'(?![\p{L}\p{N}])/u', $to, $text) ?? $text;
    }
}
