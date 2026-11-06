<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every website an agent's call button has loaded on (agent_widget_sites: allowed or refused,
 * first and last seen), for the admin's Agents page; and each call's recording (made in the
 * caller's browser, kept on Listen's own disk), with its size and parts.
 * New table, new columns and two indexes only (calls by agent and website; API requests by key and time). The websites already known (each button's last website and
 * the websites calls came from) are written in at once.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('agent_widget_sites')) {
            Schema::create('agent_widget_sites', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('agent_id')->constrained()->cascadeOnDelete();
                $t->foreignId('agent_widget_id')->constrained()->cascadeOnDelete();
                // scheme://host[:port], lower case (AgentWidget::normalizeOrigin)
                $t->string('origin', 190);
                // Whether the button was allowed to show there, the last time it was asked.
                $t->boolean('allowed')->default(true);
                $t->string('installed_via', 20)->nullable();
                $t->timestamp('first_seen_at');
                $t->timestamp('last_seen_at');
                $t->unique(['agent_widget_id', 'origin']);
                $t->index(['agent_id', 'last_seen_at']);
            });
        }

        Schema::table('agent_conversations', function (Blueprint $t): void {
            if (! Schema::hasColumn('agent_conversations', 'recording_path')) {
                $t->string('recording_path', 190)->nullable();
                $t->string('recording_type', 40)->nullable();
                $t->unsignedInteger('recording_bytes')->default(0);
                $t->unsignedSmallInteger('recording_parts')->default(0);
                // The calls that came from one website of an agent (the admin's Websites tab).
                $t->index(['agent_id', 'origin'], 'agent_conversations_agent_origin_index');
            }
        });

        // One key's requests, newest first, and its requests today (the admin's API key page).
        if (Schema::hasTable('api_request_logs') && ! Schema::hasIndex('api_request_logs', 'api_request_logs_key_created_index')) {
            Schema::table('api_request_logs', function (Blueprint $t): void {
                $t->index(['api_key_id', 'created_at'], 'api_request_logs_key_created_index');
            });
        }

        $this->backfill();
    }

    /**
     * The websites known before: each button's last one, and every website a call from its button
     * came from (link page calls are on Listen's own address, so they are left out).
     */
    private function backfill(): void
    {
        $now = now();
        foreach (DB::table('agent_widgets')->whereNotNull('last_seen_origin')->get(['id', 'agent_id', 'last_seen_origin', 'last_seen_at', 'installed_via']) as $w) {
            $origin = $this->origin($w->last_seen_origin);
            if ($origin === null) {
                continue;
            }
            DB::table('agent_widget_sites')->insertOrIgnore([
                'agent_id' => $w->agent_id, 'agent_widget_id' => $w->id, 'origin' => $origin, 'allowed' => true,
                'installed_via' => $w->installed_via, 'first_seen_at' => $w->last_seen_at ?? $now, 'last_seen_at' => $w->last_seen_at ?? $now,
            ]);
        }
        DB::table('agent_conversations')->where('channel', 'widget')->whereNotNull('agent_widget_id')->whereNotNull('origin')
            ->selectRaw('agent_id, agent_widget_id, origin, min(started_at) as first_at, max(started_at) as last_at')
            ->groupBy('agent_id', 'agent_widget_id', 'origin')
            ->orderBy('agent_widget_id')
            ->chunk(500, function ($rows): void {
                foreach ($rows as $r) {
                    $origin = $this->origin($r->origin);
                    if ($origin === null) {
                        continue;
                    }
                    $found = DB::table('agent_widget_sites')->where('agent_widget_id', $r->agent_widget_id)->where('origin', $origin)->first(['id', 'first_seen_at', 'last_seen_at']);
                    if ($found) {
                        DB::table('agent_widget_sites')->where('id', $found->id)->update([
                            'first_seen_at' => min((string) $found->first_seen_at, (string) $r->first_at),
                            'last_seen_at' => max((string) $found->last_seen_at, (string) $r->last_at),
                        ]);
                    } else {
                        DB::table('agent_widget_sites')->insert([
                            'agent_id' => $r->agent_id, 'agent_widget_id' => $r->agent_widget_id, 'origin' => $origin, 'allowed' => true,
                            'installed_via' => null, 'first_seen_at' => $r->first_at, 'last_seen_at' => $r->last_at,
                        ]);
                    }
                }
            });
    }

    private function origin(?string $value): ?string
    {
        $value = strtolower(trim((string) $value));
        if (! preg_match('#^(https?://[a-z0-9.\-]+(?::\d+)?)#', $value, $m)) {
            return null;
        }

        return substr($m[1], 0, 190);
    }

    public function down(): void
    {
        if (Schema::hasTable('api_request_logs') && Schema::hasIndex('api_request_logs', 'api_request_logs_key_created_index')) {
            Schema::table('api_request_logs', fn (Blueprint $t) => $t->dropIndex('api_request_logs_key_created_index'));
        }
        Schema::dropIfExists('agent_widget_sites');
        Schema::table('agent_conversations', function (Blueprint $t): void {
            if (Schema::hasColumn('agent_conversations', 'recording_path')) {
                $t->dropIndex('agent_conversations_agent_origin_index');
                $t->dropColumn(['recording_path', 'recording_type', 'recording_bytes', 'recording_parts']);
            }
        });
    }
};
