<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'phone_number')) {
                $table->string('phone_number')->nullable()->unique()->after('email');
            }

            if (! Schema::hasColumn('users', 'phone_verified_at')) {
                $table->timestamp('phone_verified_at')->nullable()->after('phone_number');
            }

            if (! Schema::hasColumn('users', 'firebase_uid')) {
                $table->string('firebase_uid')->nullable()->unique()->after('phone_verified_at');
            }

            if (! Schema::hasColumn('users', 'auth_provider')) {
                $table->string('auth_provider', 32)->nullable()->after('firebase_uid');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'firebase_uid')) {
                $table->dropUnique(['firebase_uid']);
            }

            if (Schema::hasColumn('users', 'phone_number')) {
                $table->dropUnique(['phone_number']);
            }

            $columns = array_values(array_filter([
                Schema::hasColumn('users', 'phone_number') ? 'phone_number' : null,
                Schema::hasColumn('users', 'phone_verified_at') ? 'phone_verified_at' : null,
                Schema::hasColumn('users', 'firebase_uid') ? 'firebase_uid' : null,
                Schema::hasColumn('users', 'auth_provider') ? 'auth_provider' : null,
            ]));

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};

