<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Keys for the public developer API (/v1). Separate from the browser
     * extension's single key on the users table: a member can hold several
     * named keys, each with its own permissions and an optional monthly
     * credit budget. Only a SHA-256 hash of each key is kept.
     */
    public function up(): void
    {
        if (! Schema::hasTable('api_keys')) {
            Schema::create('api_keys', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('name', 80);
                $table->string('key_hash', 64)->unique();
                $table->string('last_four', 4);
                // Null: every permission. Otherwise a list of App\Support\Api\ApiScopes values.
                $table->json('scopes')->nullable();
                // Null: no budget of its own (the plan's credits still apply).
                $table->unsignedBigInteger('monthly_credit_limit')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->string('last_used_ip', 45)->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'revoked_at']);
            });
        }

        if (! Schema::hasTable('api_key_usages')) {
            Schema::create('api_key_usages', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('api_key_id')->constrained('api_keys')->cascadeOnDelete();
                $table->string('period_ym', 7);
                $table->unsignedInteger('requests')->default(0);
                $table->unsignedBigInteger('credits')->default(0);
                $table->timestamps();

                $table->unique(['api_key_id', 'period_ym']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('api_key_usages');
        Schema::dropIfExists('api_keys');
    }
};
