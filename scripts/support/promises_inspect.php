<?php

/*
 * For the "Server deploy" workflow's promises mode, on either site: what the
 * site promises its members, as this server's own data says it. Reads, in a
 * READ ONLY database session: every plan's limits and switches and the lines
 * its pricing card shows in English and Arabic (built the way the pricing page
 * builds them), the site settings members read or that shape what they get
 * (named ones only, so no key or password is ever printed), and the config
 * the promises depend on. Prints no member data, and writes nothing.
 *
 *   ssh user@host 'cd ~/public_html && php' < scripts/support/promises_inspect.php
 */

use App\Models\Plan;
use App\Support\Credits;
use App\Support\PlanPricingLines;
use Illuminate\Support\Facades\DB;

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
    DB::statement('SET SESSION TRANSACTION READ ONLY');
}
$j = fn ($v): string => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
echo 'Now (UTC): ', now()->utc()->toDateTimeString(), "\n";

echo "== Plans ==\n";
foreach (Plan::query()->orderBy('kind')->orderBy('price_cents')->get() as $plan) {
    $credits = (int) $plan->monthly_chars;
    echo "\n#{$plan->id} {$plan->slug} (", $plan->kind ?: 'subscription', ', ', $plan->is_active ? 'ACTIVE' : 'hidden', $plan->is_featured ? ', featured' : '', ")\n";
    echo '  name: ', $j([$plan->name_en ?: $plan->name, $plan->name_ar]), "\n";
    echo '  price: $', number_format((int) $plan->price_cents / 100, 2), ' a month',
        (int) $plan->discount_percentage > 0 ? ', discount '.$plan->discount_percentage.'% ('.$plan->discount_duration.', ends '.($plan->discount_ends_at?->toDateString() ?? 'never').')' : '', "\n";
    echo '  credits: ', number_format($credits), ' (', number_format(Credits::minutesFor($credits)), ' min), max per request: ', number_format((int) $plan->max_chars_per_request),
        ', daily requests: ', (int) ($plan->daily_requests ?? 0), ', monthly requests: ', (int) $plan->monthly_requests, "\n";
    $fields = ['voice_tier', 'quality_tier', 'tts_provider_policy', 'processing_priority', 'queue_priority_weight', 'dialogue_enabled', 'custom_prompt_enabled',
        'sound_effects_enabled', 'voice_cloning_enabled', 'voice_cloning_max_voices', 'download_formats', 'commercial_use_allowed', 'recent_voices_days',
        'api_access_enabled', 'api_requests_per_minute', 'api_concurrency', 'pack_days', 'topup_char_price_cents', 'allow_overage', 'pricing_use_custom_text', 'pricing_lines'];
    $out = [];
    foreach ($fields as $f) {
        $out[] = $f.'='.$j($plan->{$f});
    }
    echo '  ', implode(', ', $out), "\n";
    foreach (['en', 'ar'] as $locale) {
        echo "  card ($locale):\n";
        foreach (PlanPricingLines::forPlan($plan, $locale) as $line) {
            echo '    - ', $line['label'] ?? '', ': ', $line['value'] ?? '', "\n";
        }
        $description = $locale === 'ar' ? $plan->description_ar : $plan->description_en;
        if (trim((string) $description) !== '') {
            echo "  description ($locale): ", trim((string) $description), "\n";
        }
        $button = $locale === 'ar' ? $plan->button_text_ar : $plan->button_text_en;
        if (trim((string) $button) !== '') {
            echo "  button ($locale): ", trim((string) $button), "\n";
        }
    }
    foreach (['features_en', 'features_ar'] as $f) {
        if (! empty($plan->{$f})) {
            echo "  $f (shown only when the card uses custom text): ", $j($plan->{$f}), "\n";
        }
    }
}

echo "\n== Site settings members read, or that shape what they get ==\n";
$keys = [
    'billing_grace_days', 'billing_trial_enabled', 'billing_trial_days', 'billing_trial_cycles', 'billing_trial_plan_slug', 'billing_trial_plan_slugs',
    'billing_trial_quota_plan_slug', 'billing_require_saved_payment_method', 'billing_yearly_discount_percentage', 'billing_webhook_emails',
    'voice_cloning_enabled', 'voice_cloning_audience', 'voice_cloning_max_per_user', 'voice_cloning_max_upload_mb', 'voice_cloning_min_duration',
    'voice_cloning_recording_enabled', 'tts_mode', 'credit_periods_from', 'demo_char_allowance', 'max_accounts_per_ip', 'contact_form_enabled',
    'landing_voice_samples_limit', 'manual_instapay_enabled', 'manual_vodafone_cash_enabled', 'manual_payment_instructions_en', 'manual_payment_instructions_ar',
    'site_name', 'brand_name_ar', 'site_description', 'site_seo_description_en', 'site_seo_description_ar', 'meta_description', 'meta_description_en', 'meta_description_ar',
    'extension_chrome_version', 'extension_chrome_name', 'extension_chrome_description', 'extension_chrome_store_url', 'extension_chrome_store_enabled',
    'extension_download_enabled', 'extension_custom_api_base', 'extension_remote_config_url', 'extension_announcement', 'extension_page_title', 'extension_page_subtitle',
    'extension_feature_1_title', 'extension_feature_1_desc', 'extension_feature_2_title', 'extension_feature_2_desc', 'extension_feature_3_title', 'extension_feature_3_desc',
    'extension_install_step_1', 'extension_install_step_2', 'extension_install_step_3', 'extension_install_step_4', 'extension_install_step_5',
    'extension_how_to_use_step_1', 'extension_how_to_use_step_2', 'extension_how_to_use_step_3', 'extension_how_to_use_step_4', 'extension_how_to_use_step_5',
    'extension_firefox_enabled', 'extension_safari_enabled',
];
$settings = DB::table('site_settings')->whereIn('key', $keys)->pluck('value', 'key');
foreach ($keys as $key) {
    if ($settings->has($key)) {
        $value = (string) $settings[$key];
        echo '  ', str_pad($key, 40), $value === '' ? '(empty)' : mb_strimwidth(preg_replace('/\s+/u', ' ', $value) ?? '', 0, 400, '…'), "\n";
    }
}

echo "\n== Config the promises depend on ==\n";
foreach ([
    'listen.demo.require_verified_email', 'listen.demo.guest_char_allowance', 'listen.demo.user_char_allowance', 'listen.audio.processing',
    'listen.audio.force_async_chars', 'listen.billing.grace_days', 'listen.billing.creator_trial', 'listen.billing.pre_renewal_reminder_days',
    'listen.billing.daily_limit_timezone', 'listen.tts.mode', 'listen.tts.fallback_enabled', 'listen.api.requests_per_minute', 'listen.api.concurrency',
    'listen.api.daily_share', 'listen.cost_guardrails', 'services.gemini_tts.max_chars_per_request', 'services.gemini_tts.max_dialogue_chars_per_request',
    'app.url', 'app.locale',
] as $key) {
    echo '  ', str_pad($key, 48), $j(config($key)), "\n";
}
foreach (['App\Support\DemoAllowance' => 'chars', 'App\Support\FreeAllowance' => 'freePlanGivesMonthlyChars'] as $class => $method) {
    if (class_exists($class) && method_exists($class, $method)) {
        echo '  ', str_pad($class.'::'.$method.'()', 48), $j($class::$method()), "\n";
    }
}
