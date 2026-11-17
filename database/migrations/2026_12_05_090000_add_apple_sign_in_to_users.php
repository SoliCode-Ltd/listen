<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Sign in with Apple" in the iPhone app (App\Services\Auth\AppleSignIn): the
 * id Apple gives for a person, and the token that lets the site tell Apple to
 * forget the link when the member deletes their account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'apple_id')) {
                $table->string('apple_id', 191)->nullable()->unique();
            }
            if (! Schema::hasColumn('users', 'apple_refresh_token')) {
                $table->text('apple_refresh_token')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'apple_id')) {
                $table->dropUnique(['apple_id']);
                $table->dropColumn('apple_id');
            }
            if (Schema::hasColumn('users', 'apple_refresh_token')) {
                $table->dropColumn('apple_refresh_token');
            }
        });
    }
};
