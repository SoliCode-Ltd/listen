<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A studio option given to one member for a set time (a trial of dialogue
     * mode, say), on top of whatever their plan includes. Only admins add them.
     */
    public function up(): void
    {
        if (Schema::hasTable('user_feature_grants')) {
            return;
        }

        Schema::create('user_feature_grants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('feature', 32);
            $table->timestamp('expires_at');
            $table->string('note')->nullable();
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['user_id', 'feature', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_feature_grants');
    }
};
