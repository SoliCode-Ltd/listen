<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Characters Listen gives a member on top of their plan for one month.
        if (! Schema::hasColumn('usage_counters', 'bonus_chars')) {
            Schema::table('usage_counters', function (Blueprint $table): void {
                $table->unsignedInteger('bonus_chars')->default(0)->after('total_chars');
            });
        }

        if (! Schema::hasTable('char_gifts')) {
            Schema::create('char_gifts', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
                $table->string('period_ym', 7);
                $table->unsignedInteger('chars');
                $table->string('note', 500)->nullable();
                $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('revoked_at')->nullable();
                $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['user_id', 'period_ym']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('char_gifts');

        if (Schema::hasColumn('usage_counters', 'bonus_chars')) {
            Schema::table('usage_counters', function (Blueprint $table): void {
                $table->dropColumn('bonus_chars');
            });
        }
    }
};
