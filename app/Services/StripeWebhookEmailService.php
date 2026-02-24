<?php

namespace App\Services;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Laravel\Cashier\Cashier;

class StripeWebhookEmailService
{
    private const EMAIL_SIGNATURE = 'Listen Studio Team';

    public function sendForEvent(object $event): void
    {
        if (! (bool) config('listen.billing.webhook_email_enabled', true)) {
            return;
        }

        $type = (string) ($event->type ?? '');
        $object = $event->data->object ?? null;

        if (! is_object($object)) {
            return;
        }

        match ($type) {
            'checkout.session.completed' => $this->handleCheckoutSessionCompleted($object),
            'customer.subscription.created' => $this->handleSubscriptionCreated($object),
            'invoice.paid', 'invoice.payment_succeeded' => $this->handleInvoicePaid($object),
            'customer.subscription.updated' => $this->handleSubscriptionUpdated($object, $event->data->previous_attributes ?? null),
            'customer.subscription.deleted' => $this->handleSubscriptionDeleted($object),
            'customer.subscription.trial_will_end' => $this->handleTrialWillEnd($object),
            'customer.deleted' => $this->handleCustomerDeleted($object),
            'charge.refunded' => $this->handleChargeRefunded($object),
            default => null,
        };
    }

    private function handleCheckoutSessionCompleted(object $session): void
    {
        $mode = strtolower((string) ($session->mode ?? ''));
        if ($mode !== 'payment') {
            // Subscription lifecycle emails are handled from invoice/subscription events.
            return;
        }

        $sessionId = trim((string) ($session->id ?? ''));
        if ($this->shouldSkipEmail("checkout-session:{$sessionId}", 24 * 30)) {
            return;
        }

        $customerId = trim((string) ($session->customer ?? ''));
        $customerEmail = trim((string) ($session->customer_details->email ?? $session->customer_email ?? ''));
        $user = $this->findUser($customerId, $customerEmail);

        if (! $user) {
            return;
        }

        $amount = $this->formatAmount(
            isset($session->amount_total) ? (int) $session->amount_total : null,
            (string) ($session->currency ?? 'usd')
        );

        $planLabel = $this->planLabelFromPriceId($this->extractPriceIdFromSession($session));
        $lineDescription = trim((string) ($session->metadata->description ?? ''));
        if ($planLabel === 'subscription' && $lineDescription !== '') {
            $planLabel = $lineDescription;
        }

        $details = [
            'Plan' => $planLabel,
            'Amount' => $amount,
            'Mode' => ucfirst($mode !== '' ? $mode : 'payment'),
            'Stripe Customer ID' => $customerId,
            'Checkout Session ID' => $sessionId,
            'Purchased At' => $this->formatUnixTimestamp(isset($session->created) ? (int) $session->created : null, true),
        ];

        $subject = 'Purchase confirmed';
        $body = $this->composeBody(
            $user,
            ['Your purchase was successful and is now active.'],
            $details,
            'You can manage billing any time at '.$this->manageBillingUrl()
        );

        $this->sendMail($user, $subject, $body);
    }

    private function handleInvoicePaid(object $invoice): void
    {
        $invoiceId = trim((string) ($invoice->id ?? ''));
        if ($this->shouldSkipEmail("invoice-paid:{$invoiceId}", 24 * 30)) {
            return;
        }

        $billingReason = strtolower((string) ($invoice->billing_reason ?? ''));
        $customerId = trim((string) ($invoice->customer ?? ''));
        $customerEmail = trim((string) ($invoice->customer_email ?? ''));
        $user = $this->findUser($customerId, $customerEmail);

        if (! $user) {
            return;
        }

        $amount = $this->formatAmount(
            isset($invoice->amount_paid) ? (int) $invoice->amount_paid : (isset($invoice->total) ? (int) $invoice->total : null),
            (string) ($invoice->currency ?? 'usd')
        );

        $priceId = $this->extractPriceIdFromInvoice($invoice);
        $planLabel = $this->planLabelFromPriceId($priceId);
        if ($planLabel === 'subscription') {
            $planLabel = $this->extractInvoiceLineDescription($invoice)
                ?? $this->localPlanLabelFromUser($user)
                ?? $planLabel;
        }

        $isInitialPurchase = $billingReason === 'subscription_create';
        $periodEnd = $this->extractInvoicePeriodEnd($invoice);
        $invoiceNumber = trim((string) ($invoice->number ?? ''));
        $subscriptionId = trim((string) ($invoice->subscription ?? ''));
        $invoiceUrl = trim((string) ($invoice->hosted_invoice_url ?? ''));

        $details = [
            'Plan' => $planLabel,
            'Amount' => $amount,
            'Status' => 'Paid',
            'Billing Reason' => $this->normalizeBillingReason($billingReason),
            'Renewal Date' => $periodEnd,
            'Invoice Number' => $invoiceNumber,
            'Invoice ID' => $invoiceId,
            'Stripe Subscription ID' => $subscriptionId,
            'Stripe Customer ID' => $customerId,
            'Paid At' => $this->formatUnixTimestamp(isset($invoice->status_transitions->paid_at) ? (int) $invoice->status_transitions->paid_at : null, true),
        ];

        $subject = $isInitialPurchase ? 'Subscription activated' : 'Subscription payment received';
        $body = $this->composeBody(
            $user,
            [$isInitialPurchase ? 'Your subscription is now active.' : 'Your subscription payment was successful.'],
            $details,
            $invoiceUrl !== '' ? 'Invoice: '.$invoiceUrl : 'Manage billing: '.$this->manageBillingUrl()
        );

        $this->sendMail($user, $subject, $body);
    }

    private function handleSubscriptionCreated(object $subscription): void
    {
        $customerId = trim((string) ($subscription->customer ?? ''));
        $user = $this->findUser($customerId);

        if (! $user) {
            return;
        }

        $status = strtolower((string) ($subscription->status ?? ''));
        if (in_array($status, ['incomplete', 'incomplete_expired', 'active'], true)) {
            return;
        }

        $subscriptionId = trim((string) ($subscription->id ?? ''));
        if ($status === 'trialing' && $this->shouldSkipEmail("trial-started:{$subscriptionId}", 24 * 30)) {
            return;
        }

        $planLabel = $this->planLabelFromPriceId($this->extractPriceIdFromSubscription($subscription));
        if ($planLabel === 'subscription') {
            $planLabel = $this->localPlanLabelFromUser($user) ?? $planLabel;
        }

        $details = [
            'Plan' => $planLabel,
            'Status' => $this->formatStatus($status),
            'Current Period Ends' => $this->formatUnixTimestamp(isset($subscription->current_period_end) ? (int) $subscription->current_period_end : null),
            'Trial Ends' => $this->formatUnixTimestamp(isset($subscription->trial_end) ? (int) $subscription->trial_end : null),
            'Stripe Subscription ID' => $subscriptionId,
            'Stripe Customer ID' => $customerId,
        ];

        $subject = $status === 'trialing' ? 'Trial started' : 'Subscription activated';
        $body = $this->composeBody(
            $user,
            [$status === 'trialing' ? 'Your trial has started.' : 'Your subscription is now active.'],
            $details,
            'Manage billing: '.$this->manageBillingUrl()
        );

        $this->sendMail($user, $subject, $body);
    }

    private function handleSubscriptionUpdated(object $subscription, mixed $previousAttributes): void
    {
        $customerId = trim((string) ($subscription->customer ?? ''));
        $user = $this->findUser($customerId);

        if (! $user) {
            return;
        }

        $hadPrev = is_object($previousAttributes);
        $changedStatus = $hadPrev && property_exists($previousAttributes, 'status');
        $changedCancelAtPeriodEnd = $hadPrev && property_exists($previousAttributes, 'cancel_at_period_end');
        $changedItems = $hadPrev && property_exists($previousAttributes, 'items');

        if (! $changedStatus && ! $changedCancelAtPeriodEnd && ! $changedItems) {
            return;
        }

        $status = strtolower((string) ($subscription->status ?? ''));
        $previousStatus = $changedStatus ? strtolower((string) ($previousAttributes->status ?? '')) : '';
        $cancelAtPeriodEnd = (bool) ($subscription->cancel_at_period_end ?? false);
        $periodEnd = $this->formatUnixTimestamp(isset($subscription->current_period_end) ? (int) $subscription->current_period_end : null);
        $subscriptionId = trim((string) ($subscription->id ?? ''));
        $planLabel = $this->planLabelFromPriceId($this->extractPriceIdFromSubscription($subscription));
        if ($planLabel === 'subscription') {
            $planLabel = $this->localPlanLabelFromUser($user) ?? $planLabel;
        }

        if (
            $changedStatus
            && in_array($previousStatus, ['incomplete', 'incomplete_expired'], true)
            && in_array($status, ['active', 'trialing'], true)
            && ! $changedItems
            && ! $changedCancelAtPeriodEnd
        ) {
            if ($status === 'trialing' && ! $this->shouldSkipEmail("trial-started:{$subscriptionId}", 24 * 30)) {
                $details = [
                    'Plan' => $planLabel,
                    'Status' => $this->formatStatus($status),
                    'Current Period Ends' => $periodEnd,
                    'Stripe Subscription ID' => $subscriptionId,
                    'Stripe Customer ID' => $customerId,
                ];
                $body = $this->composeBody(
                    $user,
                    ['Your trial has started.'],
                    $details,
                    'Manage billing: '.$this->manageBillingUrl()
                );
                $this->sendMail($user, 'Trial started', $body);
            }

            return;
        }

        // Payment failure and grace period messaging are handled by BillingLifecycleService.
        if ($changedStatus && in_array($status, ['past_due', 'unpaid'], true) && ! $changedItems && ! $changedCancelAtPeriodEnd) {
            return;
        }

        if ($changedCancelAtPeriodEnd && $cancelAtPeriodEnd) {
            if ($this->shouldSkipEmail("cancel-scheduled:{$subscriptionId}:{$periodEnd}", 24 * 30)) {
                return;
            }

            $details = [
                'Plan' => $planLabel,
                'Status' => $this->formatStatus($status),
                'Access Ends' => $periodEnd,
                'Stripe Subscription ID' => $subscriptionId,
                'Stripe Customer ID' => $customerId,
            ];
            $body = $this->composeBody(
                $user,
                ['Your subscription is set to cancel at the end of the current period.'],
                $details,
                'Manage billing: '.$this->manageBillingUrl()
            );
            $this->sendMail($user, 'Cancellation scheduled', $body);

            return;
        }

        if ($changedCancelAtPeriodEnd && ! $cancelAtPeriodEnd) {
            if ($this->shouldSkipEmail("cancel-removed:{$subscriptionId}", 24 * 30)) {
                return;
            }

            $details = [
                'Plan' => $planLabel,
                'Status' => $this->formatStatus($status),
                'Stripe Subscription ID' => $subscriptionId,
                'Stripe Customer ID' => $customerId,
            ];
            $body = $this->composeBody(
                $user,
                ['Your scheduled cancellation was removed.'],
                $details,
                'Manage billing: '.$this->manageBillingUrl()
            );
            $this->sendMail($user, 'Cancellation removed', $body);

            return;
        }

        if ($changedItems) {
            if ($this->shouldSkipEmail("plan-updated:{$subscriptionId}:{$planLabel}:{$status}", 24 * 7)) {
                return;
            }

            $details = [
                'Current Plan' => $planLabel,
                'Status' => $this->formatStatus($status),
                'Current Period Ends' => $periodEnd,
                'Stripe Subscription ID' => $subscriptionId,
                'Stripe Customer ID' => $customerId,
            ];
            $body = $this->composeBody(
                $user,
                ['Your subscription plan has been updated.'],
                $details,
                'Manage billing: '.$this->manageBillingUrl()
            );
            $this->sendMail($user, 'Subscription updated', $body);

            return;
        }

        if ($changedStatus) {
            if ($this->shouldSkipEmail("status-updated:{$subscriptionId}:{$status}:{$periodEnd}", 24 * 7)) {
                return;
            }

            $details = [
                'Plan' => $planLabel,
                'Status' => $this->formatStatus($status),
                'Previous Status' => $this->formatStatus($previousStatus),
                'Current Period Ends' => $periodEnd,
                'Stripe Subscription ID' => $subscriptionId,
                'Stripe Customer ID' => $customerId,
            ];
            $body = $this->composeBody(
                $user,
                ['Your subscription status has changed.'],
                $details,
                'Manage billing: '.$this->manageBillingUrl()
            );
            $this->sendMail($user, 'Subscription status updated', $body);
        }
    }

    private function handleSubscriptionDeleted(object $subscription): void
    {
        $customerId = trim((string) ($subscription->customer ?? ''));
        $user = $this->findUser($customerId);

        if (! $user) {
            return;
        }

        $localSubscription = Subscription::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->first();

        if ($localSubscription?->billing_downgraded_at && $localSubscription->billing_downgraded_at->gt(now()->subHours(6))) {
            return;
        }

        $subscriptionId = trim((string) ($subscription->id ?? ''));
        if ($this->shouldSkipEmail("subscription-canceled:{$subscriptionId}", 24 * 30)) {
            return;
        }

        $planLabel = $this->planLabelFromPriceId($this->extractPriceIdFromSubscription($subscription));
        if ($planLabel === 'subscription') {
            $planLabel = $this->localPlanLabelFromUser($user) ?? $planLabel;
        }

        $details = [
            'Plan' => $planLabel,
            'Status' => 'Canceled',
            'Canceled At' => $this->formatUnixTimestamp(isset($subscription->canceled_at) ? (int) $subscription->canceled_at : null, true),
            'Stripe Subscription ID' => $subscriptionId,
            'Stripe Customer ID' => $customerId,
        ];

        $body = $this->composeBody(
            $user,
            ['Your subscription has been canceled.'],
            $details,
            'You can resubscribe any time from '.$this->manageBillingUrl()
        );

        $this->sendMail($user, 'Subscription canceled', $body);
    }

    private function handleTrialWillEnd(object $subscription): void
    {
        $customerId = trim((string) ($subscription->customer ?? ''));
        $user = $this->findUser($customerId);

        if (! $user) {
            return;
        }

        $subscriptionId = trim((string) ($subscription->id ?? ''));
        $trialEnd = $this->formatUnixTimestamp(isset($subscription->trial_end) ? (int) $subscription->trial_end : null);
        if ($this->shouldSkipEmail("trial-will-end:{$subscriptionId}:{$trialEnd}", 24 * 14)) {
            return;
        }

        $planLabel = $this->planLabelFromPriceId($this->extractPriceIdFromSubscription($subscription));
        if ($planLabel === 'subscription') {
            $planLabel = $this->localPlanLabelFromUser($user) ?? $planLabel;
        }

        $details = [
            'Plan' => $planLabel,
            'Trial Ends' => $trialEnd,
            'Stripe Subscription ID' => $subscriptionId,
            'Stripe Customer ID' => $customerId,
        ];

        $body = $this->composeBody(
            $user,
            ["Your {$planLabel} trial ends soon."],
            $details,
            'To avoid interruption, update billing details at '.$this->manageBillingUrl()
        );

        $this->sendMail($user, 'Trial ending soon', $body);
    }

    private function handleCustomerDeleted(object $customer): void
    {
        $customerId = trim((string) ($customer->id ?? ''));
        $customerEmail = trim((string) ($customer->email ?? ''));
        $user = $this->findUser($customerId, $customerEmail);

        if (! $user) {
            return;
        }

        if ($this->shouldSkipEmail("customer-deleted:{$customerId}", 24 * 30)) {
            return;
        }

        $details = [
            'Stripe Customer ID' => $customerId,
            'Customer Email' => $customerEmail,
            'Removed At' => now()->format('M j, Y g:i A'),
        ];

        $body = $this->composeBody(
            $user,
            ['Your Stripe customer profile was removed.'],
            $details,
            'If this was not expected, contact support and we will help restore billing access.'
        );

        $this->sendMail($user, 'Billing profile removed', $body);
    }

    private function handleChargeRefunded(object $charge): void
    {
        $customerId = trim((string) ($charge->customer ?? ''));
        $billingDetailsEmail = trim((string) ($charge->billing_details->email ?? ''));
        $user = $this->findUser($customerId, $billingDetailsEmail);

        if (! $user) {
            return;
        }

        $chargeId = trim((string) ($charge->id ?? ''));
        $amount = $this->formatAmount(
            isset($charge->amount_refunded) ? (int) $charge->amount_refunded : (isset($charge->amount) ? (int) $charge->amount : null),
            (string) ($charge->currency ?? 'usd')
        );
        if ($this->shouldSkipEmail("charge-refunded:{$chargeId}:{$amount}", 24 * 30)) {
            return;
        }

        $details = [
            'Refund Amount' => $amount,
            'Stripe Charge ID' => $chargeId,
            'Stripe Customer ID' => $customerId,
            'Refunded At' => $this->formatUnixTimestamp(isset($charge->created) ? (int) $charge->created : null, true),
        ];

        $body = $this->composeBody(
            $user,
            ['A refund has been processed for your recent charge.'],
            $details,
            'If you have any questions, reply to this email and our team will help.'
        );

        $this->sendMail($user, 'Refund processed', $body);
    }

    private function sendMail(User $user, string $subject, string $body): void
    {
        $email = trim((string) $user->email);
        if ($email === '') {
            return;
        }

        try {
            Mail::raw($body, function ($message) use ($user, $subject): void {
                $message->to($user->email, $user->name ?: null)->subject($subject);
            });
        } catch (\Throwable $exception) {
            Log::warning('Unable to send Stripe webhook email.', [
                'user_id' => $user->id,
                'subject' => $subject,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function findUser(?string $stripeCustomerId, ?string $fallbackEmail = null): ?User
    {
        $stripeCustomerId = trim((string) $stripeCustomerId);
        if ($stripeCustomerId !== '') {
            $user = User::query()->where('stripe_id', $stripeCustomerId)->first();
            if ($user) {
                return $user;
            }
        }

        $fallbackEmail = trim((string) $fallbackEmail);
        if ($fallbackEmail !== '') {
            return User::query()->where('email', $fallbackEmail)->first();
        }

        return null;
    }

    private function planLabelFromPriceId(?string $priceId): string
    {
        $priceId = trim((string) $priceId);
        if ($priceId === '') {
            return 'subscription';
        }

        $prices = (array) config('services.stripe.prices', []);
        foreach ($prices as $planSlug => $cycles) {
            foreach ((array) $cycles as $cycle => $configuredPriceId) {
                if ($priceId === trim((string) $configuredPriceId)) {
                    return ucfirst((string) $planSlug).' ('.ucfirst((string) $cycle).')';
                }
            }
        }

        $stripeLabel = $this->stripePlanLabelFromPriceId($priceId);
        if ($stripeLabel !== null) {
            return $stripeLabel;
        }

        return 'subscription';
    }

    private function stripePlanLabelFromPriceId(string $priceId): ?string
    {
        static $cache = [];

        $priceId = trim($priceId);
        if ($priceId === '') {
            return null;
        }

        if (array_key_exists($priceId, $cache)) {
            return $cache[$priceId];
        }

        try {
            $price = Cashier::stripe()->prices->retrieve($priceId, ['expand' => ['product']]);
        } catch (\Throwable $exception) {
            Log::warning('Unable to resolve Stripe price label for email.', [
                'stripe_price_id' => $priceId,
                'error' => $exception->getMessage(),
            ]);
            $cache[$priceId] = null;

            return null;
        }

        $product = $price->product ?? null;
        $productName = is_object($product) ? trim((string) ($product->name ?? '')) : '';
        $nickname = trim((string) ($price->nickname ?? ''));
        $baseLabel = $productName !== '' ? $productName : $nickname;

        if ($baseLabel === '') {
            $cache[$priceId] = null;

            return null;
        }

        $interval = trim((string) ($price->recurring->interval ?? ''));
        $intervalLabel = $interval !== '' ? ucfirst($interval) : '';

        $cache[$priceId] = $intervalLabel !== '' ? "{$baseLabel} ({$intervalLabel})" : $baseLabel;

        return $cache[$priceId];
    }

    private function extractPriceIdFromSession(object $session): ?string
    {
        $metadataPriceId = trim((string) ($session->metadata->price_id ?? ''));
        if ($metadataPriceId !== '') {
            return $metadataPriceId;
        }

        return trim((string) ($session->metadata->stripe_price ?? '')) ?: null;
    }

    private function extractPriceIdFromInvoice(object $invoice): ?string
    {
        $lines = $invoice->lines->data ?? [];
        if (is_array($lines) && isset($lines[0]) && is_object($lines[0])) {
            $priceId = trim((string) ($lines[0]->price->id ?? ''));
            if ($priceId !== '') {
                return $priceId;
            }
        }

        return null;
    }

    private function extractPriceIdFromSubscription(object $subscription): ?string
    {
        $items = $subscription->items->data ?? [];
        if (is_array($items) && isset($items[0]) && is_object($items[0])) {
            $priceId = trim((string) ($items[0]->price->id ?? ''));
            if ($priceId !== '') {
                return $priceId;
            }
        }

        return null;
    }

    private function extractInvoiceLineDescription(object $invoice): ?string
    {
        $lines = $invoice->lines->data ?? [];
        if (is_array($lines) && isset($lines[0]) && is_object($lines[0])) {
            $description = trim((string) ($lines[0]->description ?? ''));
            if ($description !== '') {
                return $description;
            }
        }

        return null;
    }

    private function extractInvoicePeriodEnd(object $invoice): string
    {
        $lines = $invoice->lines->data ?? [];
        if (is_array($lines) && isset($lines[0]) && is_object($lines[0])) {
            $end = isset($lines[0]->period->end) ? (int) $lines[0]->period->end : 0;
            if ($end > 0) {
                return $this->formatUnixTimestamp($end);
            }
        }

        return 'N/A';
    }

    private function localPlanLabelFromUser(User $user): ?string
    {
        $subscription = Subscription::query()
            ->with('plan')
            ->where('user_id', $user->id)
            ->latest('id')
            ->first();

        $planName = trim((string) ($subscription?->plan?->name ?? ''));

        return $planName !== '' ? $planName : null;
    }

    private function shouldSkipEmail(string $key, int $ttlHours = 72): bool
    {
        $cacheKey = 'stripe_webhook_email:'.sha1($key);

        return ! Cache::add($cacheKey, now()->timestamp, now()->addHours(max(1, $ttlHours)));
    }

    /**
     * @param  array<int, string>  $summaryLines
     * @param  array<string, string|null>  $details
     */
    private function composeBody(User $user, array $summaryLines, array $details = [], ?string $actionLine = null): string
    {
        $lines = [
            'Hi '.$this->displayName($user).',',
            '',
        ];

        foreach ($summaryLines as $line) {
            $line = trim((string) $line);
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        $filtered = [];
        foreach ($details as $label => $value) {
            $value = trim((string) $value);
            if ($value !== '' && $value !== 'N/A') {
                $filtered[$label] = $value;
            }
        }

        if ($filtered !== []) {
            $lines[] = '';
            $lines[] = 'Details:';
            foreach ($filtered as $label => $value) {
                $lines[] = "- {$label}: {$value}";
            }
        }

        if ($actionLine !== null && trim($actionLine) !== '') {
            $lines[] = '';
            $lines[] = trim($actionLine);
        }

        $lines[] = '';
        $lines[] = 'Thanks,';
        $lines[] = self::EMAIL_SIGNATURE;

        return implode("\n", $lines);
    }

    private function formatAmount(?int $amountCents, ?string $currency): string
    {
        if ($amountCents === null) {
            return 'N/A';
        }

        $currency = strtolower(trim((string) $currency));
        if ($currency === '') {
            $currency = 'usd';
        }

        return Cashier::formatAmount($amountCents, $currency);
    }

    private function formatUnixTimestamp(?int $timestamp, bool $withTime = false): string
    {
        if (! $timestamp || $timestamp <= 0) {
            return 'N/A';
        }

        return now()
            ->setTimestamp($timestamp)
            ->format($withTime ? 'M j, Y g:i A' : 'M j, Y');
    }

    private function normalizeBillingReason(string $billingReason): string
    {
        return match ($billingReason) {
            'subscription_create' => 'Initial purchase',
            'subscription_cycle' => 'Recurring renewal',
            'subscription_update' => 'Plan update',
            default => $billingReason !== '' ? ucfirst(str_replace('_', ' ', $billingReason)) : 'N/A',
        };
    }

    private function formatStatus(string $status): string
    {
        $status = trim($status);

        return $status !== '' ? ucfirst(str_replace('_', ' ', $status)) : 'N/A';
    }

    private function manageBillingUrl(): string
    {
        return rtrim((string) config('app.url', ''), '/').'/manage-subscription';
    }

    private function displayName(User $user): string
    {
        return trim((string) $user->name) !== '' ? (string) $user->name : 'there';
    }
}
