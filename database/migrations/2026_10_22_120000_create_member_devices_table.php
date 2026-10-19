<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every browser (the device cookie) and every device (the fingerprint of
     * its hardware, which an incognito window keeps) a member has used the
     * demo from, so accounts made on one device share one free demo
     * (App\Services\DemoLinkedAccounts). users.registration_device_id and
     * last_seen_device_id keep only two browsers per member.
     */
    public function up(): void
    {
        if (Schema::hasTable('member_devices')) {
            return;
        }

        Schema::create('member_devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // browser: the device cookie's value; device: the fingerprint, already a hash.
            $table->string('kind', 16);
            $table->string('signal', 128);
            // A phone or tablet: identical models report the same fingerprint.
            $table->boolean('touch')->default(false);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'kind', 'signal']);
            $table->index(['kind', 'signal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_devices');
    }
};
