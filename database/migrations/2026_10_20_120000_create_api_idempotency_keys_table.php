<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Idempotency-Key header of developer API generation requests: one row
     * for each member and key, tied to the generation as soon as it exists, so
     * a client that retries gets the first result instead of a second charge.
     * Kept in the database rather than the cache, so a deploy (which clears
     * the cache) never forgets one. Rows expire after 24 hours.
     */
    public function up(): void
    {
        if (Schema::hasTable('api_idempotency_keys')) {
            return;
        }

        Schema::create('api_idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // SHA-256 of the header value, and of the request it was first sent with.
            $table->char('key_hash', 64);
            $table->char('fingerprint', 64);
            $table->foreignId('audio_generation_id')->nullable()->constrained('audio_generations')->nullOnDelete();
            $table->timestamp('expires_at')->index();
            $table->timestamps();

            $table->unique(['user_id', 'key_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_idempotency_keys');
    }
};
