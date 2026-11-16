<?php

namespace App\Services;

use App\Models\BillingSubscription;
use App\Models\BillingTrial;
use App\Models\DemoVoiceGeneration;
use App\Models\Transcription;
use App\Models\User;
use App\Services\Transcription\TranscriptionFlow;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

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
     *
     * What it had used of the free demo is kept first (DemoSamePerson::leaveBehind),
     * so the same person's other accounts do not get it back. With
     * $withDemoHistory its demo voices go too (Suspicious Users: "and all their
     * demo data"); without, they stay for the admin's demo list, as before.
     */
    public function delete(User $user, ?User $admin, bool $withDemoHistory = false): ?string
    {
        $refusal = $this->refusal($user);
        if ($refusal !== null) {
            return $refusal;
        }

        try {
            app(DemoSamePerson::class)->leaveBehind($user, $admin);
        } catch (\Throwable $e) {
            // Never stops the deletion: the admin asked for it.
            Log::warning('What a deleted account used of the demo could not be kept.', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }

        // Voices the member removed themselves too: their recordings can still be on disk.
        $voices = $user->clonedVoices()->withTrashed()->get();
        foreach ($voices as $voice) {
            $this->voiceCloning->purge($voice);
        }

        // Speech to text: each transcript's recording and files (their rows go with the account).
        if (Schema::hasTable('transcriptions')) {
            $disk = Storage::disk(TranscriptionFlow::DISK);
            Transcription::withTrashed()->where('user_id', $user->id)->get(['id', 'code', 'created_at'])
                ->each(fn (Transcription $t) => $disk->deleteDirectory(TranscriptionFlow::folder($t)));
        }

        if ($withDemoHistory) {
            DemoVoiceGeneration::query()->where('user_id', $user->id)->delete();
        }
        if (Schema::hasTable(config('session.table', 'sessions'))) {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        }

        $user->delete();

        Cache::forget("demo:ip_linked_users:{$user->id}");
        Cache::forget('admin:suspicious_users_count');

        Log::info('An admin deleted a member account.', [
            'user_id' => $user->id,
            'admin_id' => $admin?->id,
            'cloned_voices_removed' => $voices->count(),
        ]);

        return null;
    }
}
