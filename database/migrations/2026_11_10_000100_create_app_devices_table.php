<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The phones a member uses the Listen app on, so "your voice is ready" can
 * reach them (App\Services\Push\AppPush). A token belongs to one member at a
 * time: signing in as another member on the same phone moves it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 16)->default('android');
            // The messaging token is long; its hash is what is looked up.
            $table->char('token_hash', 64)->unique();
            $table->text('token');
            $table->string('locale', 5)->default('ar');
            $table->string('app_version', 24)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_devices');
    }
};
