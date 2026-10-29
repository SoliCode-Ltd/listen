<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An AI app a member connected with "Sign in with Listen" (OAuth, for the MCP
 * server) gets a key of its own, tied to the app's OAuth client: its usage,
 * credit limit and request log work like any key's, and disconnecting the
 * app revokes it. Such a key has no plain value anyone can send.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->uuid('oauth_client_id')->nullable()->after('user_id');
            $table->index(['user_id', 'oauth_client_id']);
        });
    }

    public function down(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'oauth_client_id']);
            $table->dropColumn('oauth_client_id');
        });
    }
};
