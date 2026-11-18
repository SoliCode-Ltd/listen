<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a member agreed, in the iPhone app, to their text and voice being sent
 * to the AI provider that makes their audio (App\Support\InApp\AiConsent).
 * Apple asks for that agreement before anything is sent. Empty for everyone
 * until they agree; taking it back empties it again.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'ai_consent_at')) {
            return;
        }
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('ai_consent_at')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'ai_consent_at')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('ai_consent_at');
            });
        }
    }
};
