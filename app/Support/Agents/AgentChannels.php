<?php

namespace App\Support\Agents;

use App\Models\Agent;

/**
 * How customers reach an agent: a written chat, voice calls, or both. Each owner chooses on the
 * agent's Setup tab; both are on until they say otherwise, and one always stays on. Kept in the
 * agent's settings (channels.chat, channels.calls), so no server waits for a migration.
 */
class AgentChannels
{
    public static function chat(Agent $agent): bool
    {
        return (bool) $agent->setting('channels.chat', true);
    }

    public static function calls(Agent $agent): bool
    {
        return (bool) $agent->setting('channels.calls', true);
    }

    /**
     * The owner's choice, kept: a channel left out stays as it was, and the last one on cannot
     * be switched off (false is returned, nothing is changed).
     */
    public static function choose(Agent $agent, ?bool $chat, ?bool $calls): bool
    {
        $chat ??= self::chat($agent);
        $calls ??= self::calls($agent);
        if (! $chat && ! $calls) {
            return false;
        }
        $settings = (array) ($agent->settings ?? []);
        $settings['channels'] = ['chat' => $chat, 'calls' => $calls];
        $agent->settings = $settings;

        return true;
    }
}
