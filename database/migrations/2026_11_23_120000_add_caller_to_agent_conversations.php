<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who called, when the website the call came from says who is signed in: their email and name,
 * signed by that website (App\Support\Agents\CallerIdentity). Empty for every call before, and
 * for callers who are not signed in. Calls work without it until it runs
 * (AgentConversation::keepsCaller).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('agent_conversations') && ! Schema::hasColumn('agent_conversations', 'caller')) {
            Schema::table('agent_conversations', function (Blueprint $table): void {
                $table->json('caller')->nullable()->after('lead');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('agent_conversations') && Schema::hasColumn('agent_conversations', 'caller')) {
            Schema::table('agent_conversations', function (Blueprint $table): void {
                $table->dropColumn('caller');
            });
        }
    }
};
