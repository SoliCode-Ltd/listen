<?php

/*
 * Read-only: the emails the site itself sends a member who has just paid by card, as this site
 * builds them now (their wording is edited in the admin), for a made-up member, so the owner can
 * see them. Sends nothing and saves nothing. On MySQL the session is READ ONLY before any query.
 *
 *   1. The welcome email for paid members (Newsletter → Paid welcome email), Arabic and English.
 *   2. "Subscription activated", from the Stripe webhook (Billing settings → Webhook Email Notifications).
 *
 * Each email's HTML is printed base64-encoded between BEGIN/END lines, to be turned back into a
 * page. Stripe's own receipt is Stripe's and is not here.
 *
 *   ssh user@host 'cd ~/public_html && php' < scripts/support/purchase_emails_preview.php
 */

use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\Newsletter\NewsletterRenderer;
use App\Services\Newsletter\PaidWelcomeEmail;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
    DB::statement('SET SESSION TRANSACTION READ ONLY');
}

$print = function (string $name, string $html): void {
    echo "BEGIN {$name}\n".chunk_split(base64_encode($html), 4000, "\n")."END {$name}\n";
};

$welcome = app(PaidWelcomeEmail::class);
echo 'Welcome email for paid members: '.($welcome->enabled() ? 'ON' : 'OFF')."\n";
$webhook = trim((string) SiteSetting::get('billing_webhook_emails', ''));
$webhookOn = $webhook !== '' ? $webhook === '1' : (bool) config('listen.billing.webhook_email_enabled', true);
echo '"Subscription activated" email (webhook emails): '.($webhookOn ? 'ON' : 'OFF')."\n";

// 1. The welcome email, with its subject as a member sees it.
$plan = Plan::query()->subscriptionPlans()->where('is_active', true)->where('price_cents', '>', 0)->orderBy('price_cents')->first();
$as = new ReflectionMethod($welcome, 'asNewsletter');
$newsletter = $as->invoke($welcome, $welcome->settings(), $plan);
$renderer = app(NewsletterRenderer::class);
echo 'Welcome subject: '.PaidWelcomeEmail::withName($renderer->subject($newsletter, 'ar'), 'Sara')."\n";
$print('welcome', $welcome->previewHtml());

// 2. "Subscription activated", for a made-up member and invoice.
$member = new User(['name' => 'Sara Ahmed', 'email' => 'sara@example.com']);
$service = app(\App\Services\StripeWebhookEmailService::class);
$render = new ReflectionMethod($service, 'renderEmail');
$planName = (string) ($plan?->name ?? 'Starter');
$html = $render->invoke($service, $member, 'Subscription activated', ['Your subscription is now active.'], [
    'Plan' => $planName,
    'Amount' => '$'.number_format(((int) ($plan?->price_cents ?? 900)) / 100, 2).' USD',
    'Status' => 'Paid',
    'Billing Reason' => 'New subscription',
    'Renewal Date' => now()->addMonth()->format('M j, Y'),
    'Invoice Number' => 'ABCD1234-0001',
    'Paid At' => now()->format('M j, Y g:i A'),
], 'https://invoice.stripe.com/i/example', 'View Invoice');
echo "Subscription activated subject: Subscription activated\n";
$print('activated', $html);

echo "\nNothing was sent or saved.\n";
