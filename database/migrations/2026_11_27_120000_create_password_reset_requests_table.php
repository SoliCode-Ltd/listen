<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who asked to reset a password from the sign-in page, and what came of it (the admin's
 * Newsletter → Password resets): the address typed, the account it belongs to if any, the
 * language of the page, whether the email went out, and when the password was changed.
 * A new table only. The reset itself works before this is run; only the list waits for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('password_reset_requests')) {
            return;
        }

        Schema::create('password_reset_requests', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('email', 190);
            $t->string('locale', 5)->default('en');
            // App\Models\PasswordResetRequest: sent, failed, no_account, no_mailbox, too_soon.
            $t->string('status', 20);
            $t->string('ip', 45)->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->string('completed_ip', 45)->nullable();
            $t->timestamps();
            // The list, newest first; one member's requests; one address's requests.
            $t->index('created_at');
            $t->index(['user_id', 'created_at']);
            $t->index(['email', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_requests');
    }
};
