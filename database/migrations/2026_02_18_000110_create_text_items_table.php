<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('text_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_type', 30); // email, message, file
            $table->string('external_id')->nullable()->index();
            $table->string('title')->nullable();
            $table->longText('content');
            $table->string('language', 10)->nullable();
            $table->string('status', 20)->default('queued');
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('text_items');
    }
};
