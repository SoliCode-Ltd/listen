<?php

namespace App\Support\Agents;

use App\Models\AgentWidget;

/**
 * What a caller hears behind the agent's voice on every call: a quiet office, people at other desks
 * having their own conversations far off, with pauses and quiet spells, and no keys or ticks, as on a
 * real office line.
 *
 * The sound is resources/audio/agents/office.mp3 (scripts/audio/build_environments.py, office(),
 * the same room as the studio's Office environment): mono at the call's 24 kHz, 64 kbps, a
 * seamless two-minute loop between LOOP[0] and LOOP[1] seconds of the file, at RMS RMS there. The call client
 * (public/js/agent-call.js) plays it UNDER_DB below the agent's voice as it arrives, steady (never
 * dipping while the agent speaks), from the moment the call connects until it ends. The websites a
 * call button is on fetch it from /agent-api/sounds/office (AgentCallController::sound), open to
 * any website.
 */
final class CallBackground
{
    public const SOUNDS = ['office'];

    /** The loop's start and end in the file (seconds): the file has a little more on each side. */
    public const LOOP = [0.5, 120.5];

    /** The loop's RMS as a browser decodes it (over the whole loop, its quiet spells included). */
    public const RMS = 0.0475;

    /** How far below the agent's voice it sits on average (dB), measured in the pauses of a real office line. */
    public const UNDER_DB = 48.9;

    public static function path(string $name): ?string
    {
        return in_array($name, self::SOUNDS, true) ? resource_path('audio/agents/'.$name.'.mp3') : null;
    }

    /**
     * What the call client needs to play the call's background. Every call has the quiet office
     * behind it: owners no longer choose (a call button saved with "none" before gets it too).
     *
     * @return array{url: string, loop: array{0: float, 1: float}, rms: float, under: float}|null
     */
    public static function for(?AgentWidget $widget): ?array
    {
        return self::sound('office');
    }

    /**
     * A background by its name, whatever a call button has chosen: what the call client plays, and
     * what the Design tab plays under the agent's voice when the owner listens to the difference.
     *
     * @return array{url: string, loop: array{0: float, 1: float}, rms: float, under: float}|null
     */
    public static function sound(string $name): ?array
    {
        $path = self::path($name);
        if ($path === null || ! is_file($path)) {
            return null;
        }
        // The file's own version in its address: browsers and caches keep it for a year.
        static $versions = [];
        $versions[$name] ??= substr((string) md5_file($path), 0, 10);

        return [
            'url' => route('agent-api.sound', ['name' => $name]).'?v='.$versions[$name],
            'loop' => self::LOOP,
            'rms' => self::RMS,
            'under' => self::UNDER_DB,
        ];
    }
}
