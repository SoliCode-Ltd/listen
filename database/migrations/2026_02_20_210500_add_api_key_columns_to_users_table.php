<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('api_key_hash', 64)->nullable()->unique()->after('google_avatar');
            $table->string('api_key_last4', 8)->nullable()->after('api_key_hash');
            $table->timestamp('api_key_created_at')->nullable()->after('api_key_last4');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['api_key_hash']);
            $table->dropColumn(['api_key_hash', 'api_key_last4', 'api_key_created_at']);
        });
    }
};
