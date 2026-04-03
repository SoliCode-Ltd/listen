<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('manual_payment_requests', function (Blueprint $table) {
            $table->uuid('proof_token')->nullable()->unique()->after('proof_path');
        });

        // Backfill existing rows with a random token
        foreach (\App\Models\ManualPaymentRequest::whereNull('proof_token')->cursor() as $request) {
            $request->forceFill(['proof_token' => Str::uuid()->toString()])->saveQuietly();
        }
    }

    public function down(): void
    {
        Schema::table('manual_payment_requests', function (Blueprint $table) {
            $table->dropColumn('proof_token');
        });
    }
};
