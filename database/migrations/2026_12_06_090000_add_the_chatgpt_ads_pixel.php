<?php

use App\Models\SiteSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * The ChatGPT Ads pixel (Ads Manager → Conversions → "Listen Conversion") and its
 * "subscription_created" event, put in SEO Settings → Ad pixels and tracking code. Only
 * into empty boxes: code the team pasted there is left as it is. Edit or clear them there.
 */
return new class extends Migration
{
    public const HEAD = '<script>!function(w,d,s,u){if(w.oaiq)return;var q=function(){q.q.push(arguments)};q.q=[];w.oaiq=q;var j=d.createElement(s);j.async=1;j.src=u;var f=d.getElementsByTagName(s)[0];f.parentNode.insertBefore(j,f)}(window,document,"script","https://bzrcdn.openai.com/sdk/oaiq.min.js");oaiq("init",{pixelId:"X3wiXsHtetMpUTppXyHw5b"});</script>';

    public const PURCHASE = <<<'JS'
// ChatGPT Ads: a paid plan started.
if (window.oaiq) {
  var openAiPlan = { type: "plan_enrollment", plan_id: purchase.plan_id };
  if (purchase.amount && purchase.currency) { openAiPlan.amount = purchase.amount; openAiPlan.currency = purchase.currency; }
  oaiq("measure", "subscription_created", openAiPlan);
}
JS;

    public function up(): void
    {
        if (! Schema::hasTable('site_settings')) {
            return;
        }

        foreach (['custom_head_code' => self::HEAD, 'purchase_event_code' => self::PURCHASE] as $key => $code) {
            if (trim((string) SiteSetting::query()->where('key', $key)->value('value')) === '') {
                SiteSetting::set($key, $code, 'seo');
            }
        }
        SiteSetting::flushGroupCache('seo');
    }

    public function down(): void
    {
        // The boxes stay as they are; clear them in SEO Settings.
    }
};
