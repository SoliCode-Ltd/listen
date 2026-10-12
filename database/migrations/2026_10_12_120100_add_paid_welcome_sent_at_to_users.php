<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // When the welcome email for paid members was last sent to this user.
        if (! Schema::hasColumn('users', 'paid_welcome_sent_at')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->timestamp('paid_welcome_sent_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'paid_welcome_sent_at')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('paid_welcome_sent_at');
            });
        }
    }
};
