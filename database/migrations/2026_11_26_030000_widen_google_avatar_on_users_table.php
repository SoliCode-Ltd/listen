<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Google's picture addresses can be longer than 255 characters: a sign-up with
 * one failed ("Data too long for column 'google_avatar'"). The column takes
 * long ones now; until this has run, App\Models\User leaves a long one out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('google_avatar')->nullable()->change();
        });
        Cache::forget(User::AVATAR_LIMIT_CACHE);
    }

    public function down(): void
    {
        // A short column cannot hold the long ones: they are left out first.
        DB::table('users')->whereRaw('length(google_avatar) > 255')->update(['google_avatar' => null]);
        Schema::table('users', function (Blueprint $table) {
            $table->string('google_avatar')->nullable()->change();
        });
        Cache::forget(User::AVATAR_LIMIT_CACHE);
    }
};
