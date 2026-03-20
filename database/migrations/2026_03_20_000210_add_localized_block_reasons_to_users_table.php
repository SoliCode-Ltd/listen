<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'blocked_reason_ar')) {
                $table->text('blocked_reason_ar')
                    ->nullable()
                    ->after('blocked_reason');
            }

            if (! Schema::hasColumn('users', 'blocked_reason_en')) {
                $table->text('blocked_reason_en')
                    ->nullable()
                    ->after('blocked_reason_ar');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $columns = [];

            if (Schema::hasColumn('users', 'blocked_reason_ar')) {
                $columns[] = 'blocked_reason_ar';
            }

            if (Schema::hasColumn('users', 'blocked_reason_en')) {
                $columns[] = 'blocked_reason_en';
            }

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
