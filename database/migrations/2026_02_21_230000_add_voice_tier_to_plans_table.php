<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->string('voice_tier', 20)->default('standard')->after('processing_priority');
        });

        DB::table('plans')->where('slug', 'free')->update(['voice_tier' => 'standard']);
        DB::table('plans')->where('slug', 'creator')->update(['voice_tier' => 'hd']);
        DB::table('plans')->where('slug', 'pro')->update(['voice_tier' => 'hd_plus']);
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('voice_tier');
        });
    }
};
