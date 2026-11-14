<?php

namespace App\Support\Agents;

use App\Models\Agent;

/**
 * A short mark of an agent as its owner set it: its name, voice, words, knowledge, channels,
 * links, files and the look of its button. Two pages open on the same agent (the website on a
 * computer, the app on a phone) compare it to know that the other one changed something, and
 * show what is there now instead of what they loaded (agents.js, "changed elsewhere").
 *
 * Only what the owner edits is in it: what changes by itself (when a website last loaded the
 * button, a file being read, a review, the calls) never makes a page think it is out of date.
 */
final class AgentStamp
{
    public static function of(Agent $agent): string
    {
        $settings = (array) ($agent->settings ?? []);

        return substr(sha1((string) json_encode([
            'agent' => $agent->only(['name', 'business_name', 'business_type', 'website_url', 'language', 'dialect', 'speaker_id', 'live_voice',
                'first_message', 'knowledge', 'max_call_seconds', 'status', 'jobs', 'choices', 'script']),
            'settings' => array_intersect_key($settings, array_flip(['updates', 'channels', 'links', 'links_edited'])),
            // Not the websites allowed: the first one to load the button is added by itself.
            'widgets' => $agent->widgets()->orderBy('id')->get(['id', 'slug', 'appearance', 'link_enabled', 'enabled'])
                ->map(fn ($w): array => [$w->id, $w->slug, $w->appearance, (bool) $w->link_enabled, (bool) $w->enabled])->all(),
            'files' => $agent->sources()->orderBy('id')->pluck('id')->all(),
        ])), 0, 20);
    }
}
