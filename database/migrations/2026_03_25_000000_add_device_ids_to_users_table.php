<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'registration_device_id')) {
                $table->string('registration_device_id', 128)->nullable()->after('last_login_ip')->index();
            }

            if (! Schema::hasColumn('users', 'last_seen_device_id')) {
                $table->string('last_seen_device_id', 128)->nullable()->after('registration_device_id')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'last_seen_device_id')) {
                $table->dropIndex(['last_seen_device_id']);
                $table->dropColumn('last_seen_device_id');
            }

            if (Schema::hasColumn('users', 'registration_device_id')) {
                $table->dropIndex(['registration_device_id']);
                $table->dropColumn('registration_device_id');
            }
        });
    }
};

