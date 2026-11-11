<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What an account had used of the free demo when an admin deleted it, so that
 * the same person's other accounts still count it (App\Services\DemoSamePerson).
 * Its browsers, devices, sign-up network and inbox are kept only as one-way
 * hashes, enough to recognise them again and nothing more. Two new tables;
 * nothing else changes. Safe to run again.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('demo_left_behind')) {
            Schema::create('demo_left_behind', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('former_user_id')->index();
                $table->timestamp('account_created_at')->nullable();
                $table->unsignedInteger('demo_chars')->default(0);
                $table->boolean('design_used')->default(false);
                $table->boolean('free_dub_used')->default(false);
                $table->unsignedInteger('free_call_seconds')->default(0);
                $table->unsignedBigInteger('deleted_by')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('demo_left_behind_signals')) {
            Schema::create('demo_left_behind_signals', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('demo_left_behind_id')->constrained('demo_left_behind')->cascadeOnDelete();
                // browser, device, network, mailbox or fingerprint; the value as a sha256 hash.
                $table->string('kind', 16);
                $table->string('signal', 64);
                $table->boolean('touch')->default(false);
                $table->timestamp('account_created_at')->nullable();

                $table->index(['kind', 'signal']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_left_behind_signals');
        Schema::dropIfExists('demo_left_behind');
    }
};
