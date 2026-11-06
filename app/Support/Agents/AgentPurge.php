<?php

namespace App\Support\Agents;

use App\Filament\Resources\AgentResource;
use App\Models\Agent;
use App\Models\AgentConversation;
use App\Services\Agents\AgentCalls;
use Illuminate\Support\Facades\DB;

/**
 * An agent deleted for good by the admin (Agents → Delete): its calls still on are ended first
 * (so the credits they held go back to the owner's plan), then its knowledge files, its calls'
 * recordings, its calls, its call buttons (they stop working on every website) and the agent
 * itself go. The customers it saved stay in the owner's list (no longer linked to an agent), and
 * the credits its finished calls used stay used. Listen's own agents are never deleted here.
 */
final class AgentPurge
{
    public static function canPurge(Agent $agent): bool
    {
        return ! AgentResource::isHouse($agent);
    }

    public static function purge(Agent $agent): bool
    {
        if (! self::canPurge($agent)) {
            return false;
        }
        $calls = app(AgentCalls::class);
        AgentConversation::query()->where('agent_id', $agent->getKey())->where('status', AgentConversation::LIVE)
            ->each(function (AgentConversation $call) use ($calls): void {
                try {
                    $calls->end($call, [], null, 'removed');
                } catch (\Throwable) {
                    // Settled by agents:settle-calls otherwise; the agent still goes.
                }
            });
        // Files on the disk (their rows delete them as they go).
        $agent->sources()->get()->each->delete();
        AgentConversation::query()->where('agent_id', $agent->getKey())->whereNotNull('recording_path')
            ->select(['id', 'recording_path'])->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    CallRecordings::forget($row);
                }
            });
        DB::transaction(function () use ($agent): void {
            // Its calls, call buttons and websites go with it (the database's own links).
            $agent->forceDelete();
        });

        return true;
    }
}
