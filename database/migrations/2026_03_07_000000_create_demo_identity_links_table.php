<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Table for linking related identities together (same device = same group)
        Schema::create('demo_identity_links', function (Blueprint $table) {
            $table->id();
            $table->string('identity_hash', 80)->index();
            $table->string('link_group', 80)->index(); // shared group ID for linked identities
            $table->string('identity_type', 20)->index(); // fp, dv, ua, ip, canvas, webgl, audio
            $table->float('confidence')->default(1.0); // how confident we are in this link
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->unique(['identity_hash', 'link_group']);
        });

        // Table for tracking suspicious patterns and fraud signals
        Schema::create('demo_fraud_signals', function (Blueprint $table) {
            $table->id();
            $table->string('identity_hash', 80)->index();
            $table->string('signal_type', 50)->index(); // vpn_detected, bot_suspected, header_mismatch, etc
            $table->text('signal_data')->nullable();
            $table->timestamps();

            $table->index(['identity_hash', 'signal_type']);
        });

        // Add new columns to demo_guest_usages for enhanced tracking
        Schema::table('demo_guest_usages', function (Blueprint $table) {
            $table->string('link_group', 80)->nullable()->index()->after('identity_hash');
            $table->unsignedInteger('abuse_score')->default(0)->after('used_chars');
            $table->boolean('is_blocked')->default(false)->after('abuse_score');
            $table->json('metadata')->nullable()->after('is_blocked');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_identity_links');
        Schema::dropIfExists('demo_fraud_signals');
        Schema::table('demo_guest_usages', function (Blueprint $table) {
            $table->dropColumn(['link_group', 'abuse_score', 'is_blocked', 'metadata']);
        });
    }
};
