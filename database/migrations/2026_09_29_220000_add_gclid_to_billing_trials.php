<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_trials', function (Blueprint $table) {
            $table->string('gclid', 255)->nullable()->after('registration_ip');
        });
    }

    public function down(): void
    {
        Schema::table('billing_trials', function (Blueprint $table) {
            $table->dropColumn('gclid');
        });
    }
};
