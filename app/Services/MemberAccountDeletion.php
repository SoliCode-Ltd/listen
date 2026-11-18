<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\AudioGeneration;
use App\Models\BillingSubscription;
use App\Models\BillingTrial;
use App\Models\DemoVoiceGeneration;
use App\Models\DesignedVoice;
use App\Models\User;
use App\Models\VideoDub;
use App\Services\Billing\StripeTrialGateway;
use App\Services\Billing\TrialSubscriptionService;
use App\Services\Dubbing\VideoDubRunner;
use App\Support\AudioFiles;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * A member deleting their own account ("Delete my account" in the app and on
 * the site). Apple asks that a member can do this alone, without writing to
 * anyone (App Review guideline 5.1.1(v)).
 *
 * In order, and stopping at the first thing that cannot be done:
 *
 * 1. A running card subscription or an open free trial is ended in Stripe at
 *    once: no further charge, and nothing is refunded for the time left (the
 *    owner's decision). If Stripe will not end it, nothing is deleted.
 * 2. What the member made is removed where rows alone would leave it behind:
 *    the audio files of their voices, their designed voices at the engine,
 *    their dubbed videos, their agents' files.
 * 3. The account goes, as when an admin deletes it (UserDeletionService):
 *    cloned voices and their recordings, transcripts, sessions, and with the
 *    account every row that belongs to it.
 *
 * What stays: payment records (the law asks for them; they no longer name the
 * account), and the note that this person used the free demo, so a new
 * account does not get it again.
 *
 * Staging uses the live Stripe keys with a copy of live's members, so ending
 * a subscription there would end a real one. It is done only where Stripe
 * actions are switched on (LISTEN_STRIPE_TRIAL_ACTIONS, live only); elsewhere
 * an account with a running subscription is refused.
 */
class MemberAccountDeletion
{
    // Card subscriptions that are still charged, or still retried.
    private const LIVE_SUBSCRIPTION_STATUSES = ['active', 'trialing', 'past_due', 'unpaid'];

    public function __construct(
        private readonly UserDeletionService $accounts,
        private readonly StripeTrialGateway $stripe,
    ) {}

    /** Why this member cannot delete the account here, or null when they can. */
    public function refusal(User $user): ?string
    {
        if ($user->is_admin) {
            return __('account.delete.refused_admin');
        }
        if ($this->billingToEnd($user) !== null && ! TrialSubscriptionService::stripeTrialActionsEnabled()) {
            return __('account.delete.refused_here');
        }

        return null;
    }

    /** What deleting will end: "subscription", "trial", or null when nothing is being charged. */
    public function billingToEnd(User $user): ?string
    {
        if ($this->liveSubscriptions($user)->isNotEmpty()) {
            $onlyTrials = $this->openTrials($user)->isNotEmpty()
                && $this->liveSubscriptions($user)->every(fn (BillingSubscription $s): bool => $s->stripe_status === 'trialing');

            return $onlyTrials ? 'trial' : 'subscription';
        }

        return $this->openTrials($user)->isNotEmpty() ? 'trial' : null;
    }

    /**
     * Deletes the account. Returns null once it is gone, or what to tell the
     * member when it could not be done (then nothing was deleted).
     */
    public function delete(User $user): ?string
    {
        $refusal = $this->refusal($user);
        if ($refusal !== null) {
            return $refusal;
        }

        if (! $this->endBilling($user)) {
            return __('account.delete.failed_billing');
        }

        $userId = (int) $user->id;
        $this->removeWhatRowsWouldLeaveBehind($user);

        // A copy of the row is what gets deleted, never the object the session holds: signing
        // out afterwards saves that object once more (a new "remember me" token), and saving a
        // deleted object would put the account back.
        $account = User::query()->find($userId);
        $refusal = $account ? $this->accounts->delete($account, null, true) : null;
        if ($refusal !== null) {
            // The billing is ended and the files are gone, but the account still stands: said
            // loudly, so it is finished by hand.
            Log::error('A member\'s account could not be deleted after its billing was ended.', ['user_id' => $userId, 'reason' => $refusal]);

            return __('account.delete.failed');
        }

        Log::info('A member deleted their own account.', ['user_id' => $userId]);

        return null;
    }

    /** Ends every running subscription and open trial in Stripe now. False when one of them would not end. */
    private function endBilling(User $user): bool
    {
        foreach ($this->openTrials($user) as $trial) {
            if (! app(TrialSubscriptionService::class)->cancelTrial($trial, 'account_deleted')) {
                return false;
            }
        }

        foreach ($this->liveSubscriptions($user) as $subscription) {
            $stripeId = trim((string) $subscription->stripe_id);
            try {
                // Ended now, without a credit for the time left and without a last invoice.
                $this->stripe->cancelSubscription($stripeId);
            } catch (\Throwable $e) {
                Log::error('A subscription could not be ended for an account being deleted; nothing was deleted.', [
                    'user_id' => $user->id, 'stripe_subscription_id' => $stripeId, 'error' => $e->getMessage(),
                ]);

                return false;
            }
            $subscription->forceFill(['stripe_status' => 'canceled', 'ends_at' => now()])->save();

            // An unpaid renewal must not be collected for an account that no longer exists.
            try {
                $this->stripe->voidOpenInvoices($stripeId);
            } catch (\Throwable $e) {
                Log::warning('The open invoices of a deleted account\'s subscription could not be voided.', [
                    'user_id' => $user->id, 'stripe_subscription_id' => $stripeId, 'error' => $e->getMessage(),
                ]);
            }
        }

        return true;
    }

    /** Files and remote copies that deleting the rows alone would leave. None of it may stop the deletion. */
    private function removeWhatRowsWouldLeaveBehind(User $user): void
    {
        $userId = (int) $user->id;

        $this->quietly($userId, 'voices', function () use ($userId): void {
            AudioGeneration::query()->where('user_id', $userId)->select(['id', 'audio_path', 'settings_snapshot'])
                ->chunkById(200, function ($generations): void {
                    $files = [];
                    foreach ($generations as $generation) {
                        $files[] = $generation->audio_path;
                        $files[] = ($generation->settings_snapshot ?? [])['cache_copy'] ?? null;
                    }
                    AudioGeneration::query()->whereKey($generations->modelKeys())->delete();
                    AudioFiles::deleteUnused($files);
                });
        });

        $this->quietly($userId, 'demo voices', function () use ($userId): void {
            DemoVoiceGeneration::query()->where('user_id', $userId)->select(['id', 'audio_path', 'metadata'])
                ->chunkById(200, function ($generations): void {
                    $files = [];
                    foreach ($generations as $generation) {
                        $files[] = $generation->audio_path;
                        $files[] = (is_array($generation->metadata) ? $generation->metadata : [])['cache_copy'] ?? null;
                    }
                    DemoVoiceGeneration::query()->whereKey($generations->modelKeys())->update(['audio_path' => null]);
                    AudioFiles::deleteUnused($files);
                });
        });

        if (Schema::hasTable('designed_voices')) {
            $this->quietly($userId, 'designed voices', function () use ($userId): void {
                $service = app(VoiceDesignService::class);
                DesignedVoice::withTrashed()->where('user_id', $userId)->get()
                    ->each(fn (DesignedVoice $voice) => $this->quietly($userId, 'a designed voice', fn () => $service->purge($voice)));
            });
        }

        if (Schema::hasTable('video_dubs')) {
            $this->quietly($userId, 'dubbed videos', function () use ($userId): void {
                $runner = app(VideoDubRunner::class);
                VideoDub::query()->where('user_id', $userId)->get()->each(function (VideoDub $dub) use ($runner): void {
                    if ($dub->folder) {
                        Storage::disk('local')->deleteDirectory($dub->folder);
                    }
                    if ($dub->drive_file_id) {
                        $runner->deleteFromDrive($dub);
                    }
                });
            });
        }

        if (Schema::hasTable('agents')) {
            // One by one, so each agent's files leave the disk (AgentSource).
            $this->quietly($userId, 'agents', function () use ($userId): void {
                Agent::query()->where('user_id', $userId)->get()->each(function (Agent $agent): void {
                    $agent->sources()->get()->each->delete();
                    $agent->delete();
                });
            });
        }
    }

    /** @return \Illuminate\Support\Collection<int, BillingSubscription> */
    private function liveSubscriptions(User $user)
    {
        return BillingSubscription::query()
            ->where('user_id', $user->id)
            ->whereIn('stripe_status', self::LIVE_SUBSCRIPTION_STATUSES)
            ->get();
    }

    /** @return \Illuminate\Support\Collection<int, BillingTrial> */
    private function openTrials(User $user)
    {
        return BillingTrial::query()
            ->where('user_id', $user->id)
            ->where('status', BillingTrial::STATUS_TRIALING)
            ->whereNull('paid_at')
            ->get();
    }

    private function quietly(int $userId, string $what, callable $work): void
    {
        try {
            $work();
        } catch (\Throwable $e) {
            Log::warning('Part of a deleted account could not be removed: '.$what.'.', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }
    }
}
