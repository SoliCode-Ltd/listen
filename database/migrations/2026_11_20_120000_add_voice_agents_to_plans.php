<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which plans include Listen Agents, set on each plan (Admin → Plans → Voice
 * agents): whether members on the plan can put agents live, how many agents
 * they keep, the call minutes a month (0: only the plan's credits limit them),
 * calls at the same time, and the longest call (0: the site's own numbers).
 *
 * The plans start as recommended for the cost of a call minute (App\Support\
 * Agents\AgentCost): Starter 1 agent, Creator 3, Pro 5, Studio Max 10 with at
 * most 600 call minutes a month. Free and the one-time pack have none. A
 * database without these plans (a new install) gets the columns only. Every
 * number can be changed on the plan afterwards.
 */
return new class extends Migration
{
    /** @var array<string, array{agents: int, minutes: int, concurrent: int, call_minutes: int}> */
    private const PLANS = [
        'starter' => ['agents' => 1, 'minutes' => 0, 'concurrent' => 2, 'call_minutes' => 5],
        'creator' => ['agents' => 3, 'minutes' => 0, 'concurrent' => 3, 'call_minutes' => 8],
        'pro' => ['agents' => 5, 'minutes' => 0, 'concurrent' => 5, 'call_minutes' => 10],
        'studio_max' => ['agents' => 10, 'minutes' => 600, 'concurrent' => 10, 'call_minutes' => 10],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('plans')) {
            return;
        }
        Schema::table('plans', function (Blueprint $table): void {
            if (! Schema::hasColumn('plans', 'agents_enabled')) {
                $table->boolean('agents_enabled')->default(false);
            }
            if (! Schema::hasColumn('plans', 'agents_max')) {
                $table->unsignedSmallInteger('agents_max')->default(0);
            }
            if (! Schema::hasColumn('plans', 'agent_minutes_per_month')) {
                $table->unsignedInteger('agent_minutes_per_month')->default(0);
            }
            if (! Schema::hasColumn('plans', 'agent_concurrent_calls')) {
                $table->unsignedSmallInteger('agent_concurrent_calls')->default(0);
            }
            if (! Schema::hasColumn('plans', 'agent_max_call_minutes')) {
                $table->unsignedSmallInteger('agent_max_call_minutes')->default(0);
            }
        });

        foreach (self::PLANS as $slug => $numbers) {
            DB::table('plans')->where('slug', $slug)->update([
                'agents_enabled' => true,
                'agents_max' => $numbers['agents'],
                'agent_minutes_per_month' => $numbers['minutes'],
                'agent_concurrent_calls' => $numbers['concurrent'],
                'agent_max_call_minutes' => $numbers['call_minutes'],
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('plans')) {
            return;
        }
        Schema::table('plans', function (Blueprint $table): void {
            foreach (['agents_enabled', 'agents_max', 'agent_minutes_per_month', 'agent_concurrent_calls', 'agent_max_call_minutes'] as $column) {
                if (Schema::hasColumn('plans', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
