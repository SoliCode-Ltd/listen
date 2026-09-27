<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('newsletters', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('status')->default('draft');
            $table->string('audience')->default('free');
            $table->foreignId('plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->text('custom_emails')->nullable();
            $table->boolean('exclude_blocked')->default(true);
            $table->boolean('exclude_unsubscribed')->default(true);
            $table->boolean('exclude_already_sent')->default(true);
            $table->boolean('skip_phone_accounts')->default(true);
            $table->boolean('exclude_admins')->default(true);
            $table->string('language')->default('both');
            $table->string('bilingual_order')->default('en_ar');
            $table->string('subject_en')->nullable();
            $table->string('subject_ar')->nullable();
            $table->string('preheader_en')->nullable();
            $table->string('preheader_ar')->nullable();
            $table->string('eyebrow_en')->nullable();
            $table->string('eyebrow_ar')->nullable();
            $table->string('headline_en')->nullable();
            $table->string('headline_ar')->nullable();
            $table->text('intro_en')->nullable();
            $table->text('intro_ar')->nullable();
            $table->text('body_en')->nullable();
            $table->text('body_ar')->nullable();
            $table->json('highlights_en')->nullable();
            $table->json('highlights_ar')->nullable();
            $table->string('cta_label_en')->nullable();
            $table->string('cta_label_ar')->nullable();
            $table->text('cta_url_en')->nullable();
            $table->text('cta_url_ar')->nullable();
            $table->text('footer_en')->nullable();
            $table->text('footer_ar')->nullable();
            $table->string('from_name')->nullable();
            $table->string('from_address')->nullable();
            $table->string('reply_to')->nullable();
            $table->text('internal_notes')->nullable();
            $table->timestamp('last_test_sent_at')->nullable();
            $table->string('last_test_email')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->timestamps();
        });

        Schema::create('newsletter_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('newsletter_id')->constrained('newsletters')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('email');
            $table->string('locale', 16);
            $table->boolean('is_test')->default(false);
            $table->string('status');
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['newsletter_id', 'is_test', 'status']);
            $table->index('email');
        });

        Schema::create('newsletter_suppressions', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('newsletter_suppressions');
        Schema::dropIfExists('newsletter_deliveries');
        Schema::dropIfExists('newsletters');
    }
};
