<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'registration_request_signature')) {
                $table->string('registration_request_signature', 64)
                    ->nullable()
                    ->after('last_seen_device_id')
                    ->index();
            }

            if (! Schema::hasColumn('users', 'last_seen_request_signature')) {
                $table->string('last_seen_request_signature', 64)
                    ->nullable()
                    ->after('registration_request_signature')
                    ->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach ([
                'users_last_seen_request_signature_index',
                'users_registration_request_signature_index',
            ] as $index) {
                if (Schema::hasIndex('users', $index)) {
                    $table->dropIndex($index);
                }
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'last_seen_request_signature')) {
                $table->dropColumn('last_seen_request_signature');
            }

            if (Schema::hasColumn('users', 'registration_request_signature')) {
                $table->dropColumn('registration_request_signature');
            }
        });
    }
};
