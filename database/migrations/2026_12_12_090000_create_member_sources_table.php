<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How each new member found the site (App\Models\MemberSource, Admin → Where members come from):
 * kept once, when the account is made, from the visits the site remembered for that browser
 * (App\Services\Acquisition\VisitSources).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('member_sources')) {
            return;
        }

        Schema::create('member_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            // Where the account was made: web, android or ios (the phone app).
            $table->string('signed_up_in', 10)->default('web');
            // The last place the visitor came from before signing up, or their first visit when that is all there is.
            $table->string('channel', 20)->index();
            $table->string('detail', 80)->nullable();
            $table->string('campaign', 120)->nullable();
            $table->string('landing_path', 200)->nullable();
            $table->string('referrer_host', 120)->nullable();
            $table->timestamp('came_at')->nullable();
            // Where they came from the first time.
            $table->string('first_channel', 20)->nullable()->index();
            $table->string('first_detail', 80)->nullable();
            $table->string('first_campaign', 120)->nullable();
            $table->string('first_landing_path', 200)->nullable();
            $table->string('first_referrer_host', 120)->nullable();
            $table->timestamp('first_came_at')->nullable();
            // The two visits as kept (App\Support\Acquisition\Touch), so they can be sorted again if the groups change.
            $table->json('visits')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_sources');
    }
};
