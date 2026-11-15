<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Newsletter → Purchase follow-ups now lists the members whose renewal was declined, with
 * the warning each got. Members who are in their grace days today were warned before the
 * list existed (a plain-text email): they are added, so the list starts complete.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('purchase_follow_ups') || ! Schema::hasColumn('subscriptions', 'billing_failed_notice_sent_at')) {
            return;
        }

        $warned = DB::table('subscriptions')
            ->leftJoin('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->where('subscriptions.billing_payment_state', 'past_due')
            ->whereNotNull('subscriptions.billing_failed_notice_sent_at')
            ->orderBy('subscriptions.id')
            ->get(['subscriptions.user_id', 'subscriptions.billing_failed_notice_sent_at as sent_at', 'plans.slug as plan_slug', 'plans.price_cents']);

        foreach ($warned as $plan) {
            if (! $plan->user_id || DB::table('purchase_follow_ups')->where('user_id', $plan->user_id)->where('kind', 'renewal_failed')->whereNull('paid_at')->exists()) {
                continue;
            }

            DB::table('purchase_follow_ups')->insert([
                'user_id' => $plan->user_id,
                'kind' => 'renewal_failed',
                'plan_slug' => $plan->plan_slug,
                'billing_cycle' => 'monthly',
                'locale' => 'ar',
                'amount_cents' => $plan->price_cents !== null ? max(0, (int) $plan->price_cents) : null,
                'currency' => 'usd',
                'happened_at' => $plan->sent_at,
                'opens' => 1,
                'email_status' => 'sent',
                'email_note' => 'Sent as a plain email',
                'email_sent_at' => $plan->sent_at,
                'created_at' => $plan->sent_at,
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // The cases stay: they are what happened.
    }
};
