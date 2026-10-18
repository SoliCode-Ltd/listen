<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every developer API request, for the admin (System → API requests):
     * who sent it, which endpoint, how it ended, what it cost. Kept 90 days
     * (routes/console.php prunes older rows).
     */
    public function up(): void
    {
        if (Schema::hasTable('api_request_logs')) {
            return;
        }

        Schema::create('api_request_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('request_id', 40)->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->unsignedBigInteger('api_key_id')->nullable()->index();
            $table->string('method', 8);
            $table->string('path', 255);
            $table->string('endpoint', 60)->nullable();
            $table->unsignedSmallInteger('status');
            $table->string('error_code', 60)->nullable()->index();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->unsignedBigInteger('history_item_id')->nullable();
            $table->unsignedInteger('credits')->nullable();
            $table->unsignedInteger('characters')->nullable();
            $table->boolean('replayed')->default(false);
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_request_logs');
    }
};
