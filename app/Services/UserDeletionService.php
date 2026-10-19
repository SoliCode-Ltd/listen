<?php

namespace App\Services;

use App\Models\BillingSubscription;
use App\Models\BillingTrial;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Deleting an account from the admin. Stripe keeps charging a card
 * subscription or an open free trial after the account is gone (and its
 * webhooks then find no one), so those accounts are refused. Everyone else's
 * cloned voices are removed at the provider and on disk first.
 */
class UserDeletionService
{
    // Card subscriptions that are still charged, or still retried.
    private const LIVE_SUBSCRIPTION_STATUSES = ['active', 'trialing', 'past_due', 'unpaid'];

    public function __construct(private readonly VoiceCloningService $voiceCloning) {}

    /**
     * Why this account must not be deleted here, or null when it can be.
     */
    public function refusal(User $user): ?string
    {
        $liveSubscription = BillingSubscription::query()
            ->where('user_id', $user->id)
            ->whereIn('stripe_status', self::LIVE_SUBSCRIPTION_STATUSES)
            ->exists();
        if ($liveSubscription) {
            return __('ui.admin.users.delete_refused_subscription');
        }

        $openTrial = BillingTrial::query()
            ->where('user_id', $user->id)
            ->where('status', BillingTrial::STATUS_TRIALING)
            ->whereNull('paid_at')
            ->exists();
        if ($openTrial) {
            return __('ui.admin.users.delete_refused_trial');
        }

        return null;
    }

    /**
     * Deletes the account unless refusal() says no. Returns that reason, or
     * null once the account is gone.
     */
    public function delete(User $user, ?User $admin): ?string
    {
        $refusal = $this->refusal($user);
        if ($refusal !== null) {
            return $refusal;
        }

        // Voices the member removed themselves too: their recordings can still be on disk.
        $voices = $user->clonedVoices()->withTrashed()->get();
        foreach ($voices as $voice) {
            $this->voiceCloning->purge($voice);
        }

        $user->delete();

        Log::info('An admin deleted a member account.', [
            'user_id' => $user->id,
            'admin_id' => $admin?->id,
            'cloned_voices_removed' => $voices->count(),
        ]);

        return null;
    }
}
