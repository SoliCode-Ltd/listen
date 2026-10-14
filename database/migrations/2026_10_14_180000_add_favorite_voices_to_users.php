<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // The speakers a member starred in the studio's voice library.
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('favorite_voices')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('favorite_voices');
        });
    }
};
