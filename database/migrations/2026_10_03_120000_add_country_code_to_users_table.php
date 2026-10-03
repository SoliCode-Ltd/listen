<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'country_code')) {
                // ISO 3166-1 alpha-2, from the sign-in request. Only the country is kept, never a location.
                $table->char('country_code', 2)->nullable()->after('last_login_ip')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'country_code')) {
                $table->dropIndex(['country_code']);
                $table->dropColumn('country_code');
            }
        });
    }
};
