<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('demo_guest_usages', function (Blueprint $table) {
            $table->id();
            $table->string('identity_hash', 80)->unique();
            $table->unsignedBigInteger('used_chars')->default(0);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->index('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_guest_usages');
    }
};

