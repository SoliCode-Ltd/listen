<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('billing_payment_state', 20)->default('ok')->after('status');
            $table->timestamp('billing_grace_ends_at')->nullable()->after('current_period_end');
            $table->timestamp('billing_reminder_7d_sent_at')->nullable()->after('billing_grace_ends_at');
            $table->timestamp('billing_reminder_1d_sent_at')->nullable()->after('billing_reminder_7d_sent_at');
            $table->timestamp('billing_failed_notice_sent_at')->nullable()->after('billing_reminder_1d_sent_at');
            $table->timestamp('billing_downgraded_at')->nullable()->after('billing_failed_notice_sent_at');

            $table->index(['billing_payment_state', 'billing_grace_ends_at'], 'subs_billing_state_grace_idx');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex('subs_billing_state_grace_idx');
            $table->dropColumn([
                'billing_payment_state',
                'billing_grace_ends_at',
                'billing_reminder_7d_sent_at',
                'billing_reminder_1d_sent_at',
                'billing_failed_notice_sent_at',
                'billing_downgraded_at',
            ]);
        });
    }
};
