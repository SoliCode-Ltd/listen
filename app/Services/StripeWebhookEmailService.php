<?php

namespace App\Services;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Laravel\Cashier\Cashier;

class StripeWebhookEmailService
{
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
        if ($mode !== 'subscription' && $mode !== 'payment') {
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

        $priceId = $this->extractPriceIdFromSession($session);
        $planLabel = $this->planLabelFromPriceId($priceId);

        $subject = 'Purchase confirmed';
        $body = "Hi {$this->displayName($user)},\n\n"
            ."Thanks for your purchase. Your {$planLabel} is now active.\n"
            ."Amount: {$amount}\n\n"
            ."You can manage your subscription anytime from your billing page.\n\n"
            ."Thanks,\nListen";

        $this->sendMail($user, $subject, $body);
    }

    private function handleInvoicePaid(object $invoice): void
    {
        $billingReason = strtolower((string) ($invoice->billing_reason ?? ''));
        if ($billingReason === 'subscription_create') {
            // Initial purchase is handled by customer.subscription.created.
            return;
        }

        $customerId = trim((string) ($invoice->customer ?? ''));
        $user = $this->findUser($customerId);

        if (! $user) {
            return;
        }

        $amount = $this->formatAmount(
            isset($invoice->amount_paid) ? (int) $invoice->amount_paid : (isset($invoice->total) ? (int) $invoice->total : null),
            (string) ($invoice->currency ?? 'usd')
        );

        $priceId = $this->extractPriceIdFromInvoice($invoice);
        $planLabel = $this->planLabelFromPriceId($priceId);

        $subject = 'Subscription payment received';
        $body = "Hi {$this->displayName($user)},\n\n"
            ."Your subscription payment was successful.\n"
            ."Plan: {$planLabel}\n"
            ."Amount: {$amount}\n\n"
            ."Thanks,\nListen";

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
        if ($status === 'incomplete' || $status === 'incomplete_expired') {
            return;
        }

        $planLabel = $this->planLabelFromPriceId($this->extractPriceIdFromSubscription($subscription));
        $periodEnd = isset($subscription->current_period_end) && (int) $subscription->current_period_end > 0
            ? now()->setTimestamp((int) $subscription->current_period_end)->format('M j, Y')
            : 'N/A';

        $subject = 'Subscription activated';
        $body = "Hi {$this->displayName($user)},\n\n"
            ."Your subscription is now active.\n"
            ."Plan: {$planLabel}\n"
            ."Status: {$status}\n"
            ."Current period ends: {$periodEnd}\n\n"
            ."Thanks,\nListen";

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
        $periodEnd = isset($subscription->current_period_end) && (int) $subscription->current_period_end > 0
            ? now()->setTimestamp((int) $subscription->current_period_end)->format('M j, Y')
            : 'N/A';
        $planLabel = $this->planLabelFromPriceId($this->extractPriceIdFromSubscription($subscription));

        if (
            $changedStatus
            && in_array($previousStatus, ['incomplete', 'incomplete_expired'], true)
            && in_array($status, ['active', 'trialing'], true)
            && ! $changedItems
            && ! $changedCancelAtPeriodEnd
        ) {
            $subject = 'Subscription activated';
            $body = "Hi {$this->displayName($user)},\n\n"
                ."Your subscription is now active.\n"
                ."Plan: {$planLabel}\n"
                ."Status: {$status}\n"
                ."Current period ends: {$periodEnd}\n\n"
                ."Thanks,\nListen";
            $this->sendMail($user, $subject, $body);
            return;
        }

        // Payment failure and grace period messaging are handled by BillingLifecycleService.
        if ($changedStatus && in_array($status, ['past_due', 'unpaid'], true) && ! $changedItems && ! $changedCancelAtPeriodEnd) {
            return;
        }

        if ($changedCancelAtPeriodEnd && $cancelAtPeriodEnd) {
            $subject = 'Cancellation scheduled';
            $body = "Hi {$this->displayName($user)},\n\n"
                ."Your subscription is set to cancel at the end of the current period.\n"
                ."Plan: {$planLabel}\n"
                ."Access ends on: {$periodEnd}\n\n"
                ."Thanks,\nListen";
            $this->sendMail($user, $subject, $body);
            return;
        }

        if ($changedCancelAtPeriodEnd && ! $cancelAtPeriodEnd) {
            $subject = 'Cancellation removed';
            $body = "Hi {$this->displayName($user)},\n\n"
                ."Your scheduled cancellation was removed.\n"
                ."Plan: {$planLabel}\n"
                ."Status: {$status}\n\n"
                ."Thanks,\nListen";
            $this->sendMail($user, $subject, $body);
            return;
        }

        if ($changedItems) {
            $subject = 'Subscription updated';
            $body = "Hi {$this->displayName($user)},\n\n"
                ."Your subscription plan has been updated.\n"
                ."Current plan: {$planLabel}\n"
                ."Status: {$status}\n\n"
                ."Thanks,\nListen";
            $this->sendMail($user, $subject, $body);
            return;
        }

        if ($changedStatus) {
            $subject = 'Subscription status updated';
            $body = "Hi {$this->displayName($user)},\n\n"
                ."Your subscription status is now: {$status}.\n"
                ."Plan: {$planLabel}\n\n"
                ."Thanks,\nListen";
            $this->sendMail($user, $subject, $body);
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

        $planLabel = $this->planLabelFromPriceId($this->extractPriceIdFromSubscription($subscription));

        $subject = 'Subscription canceled';
        $body = "Hi {$this->displayName($user)},\n\n"
            ."Your subscription has been canceled.\n"
            ."Plan: {$planLabel}\n\n"
            ."You can resubscribe at any time from your billing page.\n\n"
            ."Thanks,\nListen";

        $this->sendMail($user, $subject, $body);
    }

    private function handleTrialWillEnd(object $subscription): void
    {
        $customerId = trim((string) ($subscription->customer ?? ''));
        $user = $this->findUser($customerId);

        if (! $user) {
            return;
        }

        $planLabel = $this->planLabelFromPriceId($this->extractPriceIdFromSubscription($subscription));
        $trialEnd = isset($subscription->trial_end) && (int) $subscription->trial_end > 0
            ? now()->setTimestamp((int) $subscription->trial_end)->format('M j, Y')
            : 'soon';

        $subject = 'Trial ending soon';
        $body = "Hi {$this->displayName($user)},\n\n"
            ."Your {$planLabel} trial ends on {$trialEnd}.\n"
            ."To keep access uninterrupted, make sure your payment method is ready in Billing.\n\n"
            ."Thanks,\nListen";

        $this->sendMail($user, $subject, $body);
    }

    private function handleCustomerDeleted(object $customer): void
    {
        $customerId = trim((string) ($customer->id ?? ''));
        $customerEmail = trim((string) ($customer->email ?? ''));
        $user = $this->findUser($customerId, $customerEmail);

        if (! $user) {
            return;
        }

        $subject = 'Billing profile removed';
        $body = "Hi {$this->displayName($user)},\n\n"
            ."Your Stripe customer profile was removed.\n"
            ."If this was not expected, contact support and we can help restore billing access.\n\n"
            ."Thanks,\nListen";

        $this->sendMail($user, $subject, $body);
    }

    private function handleChargeRefunded(object $charge): void
    {
        $customerId = trim((string) ($charge->customer ?? ''));
        $billingDetailsEmail = trim((string) ($charge->billing_details->email ?? ''));
        $user = $this->findUser($customerId, $billingDetailsEmail);

        if (! $user) {
            return;
        }

        $amount = $this->formatAmount(
            isset($charge->amount_refunded) ? (int) $charge->amount_refunded : (isset($charge->amount) ? (int) $charge->amount : null),
            (string) ($charge->currency ?? 'usd')
        );

        $subject = 'Refund processed';
        $body = "Hi {$this->displayName($user)},\n\n"
            ."A refund has been processed for your recent charge.\n"
            ."Refund amount: {$amount}\n\n"
            ."Thanks,\nListen";

        $this->sendMail($user, $subject, $body);
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

        return 'subscription';
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

    private function displayName(User $user): string
    {
        return trim((string) $user->name) !== '' ? (string) $user->name : 'there';
    }
}
