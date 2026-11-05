<?php

namespace App\Support\Agents;

use App\Models\AgentWidget;

/**
 * What a caller hears behind the agent's voice (Design → Call sound): by default a quiet office,
 * colleagues talking far off with no keys or ticks, as on a real office line; or nothing.
 *
 * The sound is resources/audio/agents/office.mp3 (scripts/audio/build_environments.py, office(),
 * the same colleagues as the studio's Office environment): mono at the call's 24 kHz, a seamless
 * loop between LOOP[0] and LOOP[1] seconds of the file, at RMS RMS there. The call client
 * (public/js/agent-call.js) plays it UNDER_DB below the agent's voice as it arrives, steady (never
 * dipping while the agent speaks), from the moment the call connects until it ends. The websites a
 * call button is on fetch it from /agent-api/sounds/office (AgentCallController::sound), open to
 * any website.
 */
final class CallBackground
{
    public const SOUNDS = ['office'];

    /** The loop's start and end in the file (seconds): the file has a little more on each side. */
    public const LOOP = [0.5, 87.5];

    /** The loop's RMS as a browser decodes it. */
    public const RMS = 0.0476;

    /** How far below the agent's voice it sits (dB), measured in the pauses of a real office line. */
    public const UNDER_DB = 48.9;

    public static function path(string $name): ?string
    {
        return in_array($name, self::SOUNDS, true) ? resource_path('audio/agents/'.$name.'.mp3') : null;
    }

    /**
     * What the call client needs to play the call's background, or null for none. A test call or
     * a demo has no call button of its own: the agent's first one decides.
     *
     * @return array{url: string, loop: array{0: float, 1: float}, rms: float, under: float}|null
     */
    public static function for(?AgentWidget $widget): ?array
    {
        $name = (string) ($widget?->look('background') ?? AgentWidget::DEFAULT_APPEARANCE['background']);
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
