<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_blocked')
                ->default(false)
                ->after('is_admin');

            $table->timestamp('blocked_at')
                ->nullable()
                ->after('is_blocked');

            $table->text('blocked_reason')
                ->nullable()
                ->after('blocked_at');

            $table->foreignId('blocked_by')
                ->nullable()
                ->after('blocked_reason')
                ->constrained('users')
                ->nullOnDelete();

            $table->index(['is_blocked', 'blocked_at'], 'users_blocked_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_blocked_status_index');
            $table->dropConstrainedForeignId('blocked_by');
            $table->dropColumn([
                'is_blocked',
                'blocked_at',
                'blocked_reason',
            ]);
        });
    }
};
