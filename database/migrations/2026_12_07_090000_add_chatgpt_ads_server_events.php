<?php

use App\Models\SiteSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ChatGPT Ads purchases sent from the server (OpenAI's Conversions API), next to the
 * pixel in the member's browser: a card payment once Stripe says it is paid, also when
 * the browser blocked the pixel, and a payment by hand once the team approves it.
 * - where the member came from at checkout (their ad click, browser and page), kept
 *   with the account for a card payment and with the request for a payment by hand;
 * - each event sent, once (ChatGptAdsEvent);
 * - the browser's event gets the same id as the server's, so OpenAI counts the sale once:
 *   the "Code when a paid plan starts" box gains it, unless the team changed that code.
 */
return new class extends Migration
{
    public const PURCHASE_BEFORE = <<<'JS'
// ChatGPT Ads: a paid plan started.
if (window.oaiq) {
  var openAiPlan = { type: "plan_enrollment", plan_id: purchase.plan_id };
  if (purchase.amount && purchase.currency) { openAiPlan.amount = purchase.amount; openAiPlan.currency = purchase.currency; }
  oaiq("measure", "subscription_created", openAiPlan);
}
JS;

    public const PURCHASE = <<<'JS'
// ChatGPT Ads: a paid plan started. event_id is the one the server sends too, so the sale counts once.
if (window.oaiq) {
  var openAiPlan = { type: "plan_enrollment", plan_id: purchase.plan_id };
  if (purchase.amount && purchase.currency) { openAiPlan.amount = purchase.amount; openAiPlan.currency = purchase.currency; }
  oaiq("measure", "subscription_created", openAiPlan, purchase.event_id ? { event_id: purchase.event_id } : {});
}
JS;

    /** Ads Manager → Conversions → Data Source "Listen Conversion". */
    public const PIXEL_ID = 'X3wiXsHtetMpUTppXyHw5b';

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('chatgpt_ads_context')->nullable();
        });

        Schema::table('manual_payment_requests', function (Blueprint $table) {
            $table->json('chatgpt_ads_context')->nullable();
        });

        Schema::create('chatgpt_ads_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // invoice:in_... or manual:12, so a sale is sent once.
            $table->string('reference', 191)->unique();
            // card or manual
            $table->string('source', 20);
            $table->string('event_id', 100);
            $table->json('payload');
            // pending, sent, failed, expired
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        if (Schema::hasTable('site_settings')) {
            if (trim((string) SiteSetting::query()->where('key', 'chatgpt_ads_pixel_id')->value('value')) === '') {
                SiteSetting::set('chatgpt_ads_pixel_id', self::PIXEL_ID, 'ads');
            }
            $code = (string) SiteSetting::query()->where('key', 'purchase_event_code')->value('value');
            if (trim($code) === trim(self::PURCHASE_BEFORE)) {
                SiteSetting::set('purchase_event_code', self::PURCHASE, 'seo');
            }
            SiteSetting::flushGroupCache('seo');
            SiteSetting::flushGroupCache('ads');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('chatgpt_ads_events');

        Schema::table('manual_payment_requests', function (Blueprint $table) {
            $table->dropColumn('chatgpt_ads_context');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('chatgpt_ads_context');
        });
    }
};
