<?php

namespace App\Console\Commands;

use App\Models\ReferralCampaign;
use App\Models\ReferralConversion;
use App\Models\ReferralVisit;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UserReferralProfile;
use App\Services\Referral\ReferralQualificationService;
use App\Services\Referral\ReferralService;
use Illuminate\Console\Command;

class RepairReferralBindingsCommand extends Command
{
    protected $signature = 'referral:repair-bindings
        {--user= : Repair a single user by id or email}
        {--dry : Preview only, do not write}';

    protected $description = 'Retroactively bind referred users whose cookie was lost at signup. Matches users to unconverted ReferralVisit rows by session/device/IP within the active campaign window.';

    public function handle(ReferralService $referrals, ReferralQualificationService $qualifier): int
    {
        $campaign = ReferralCampaign::active();
        if (! $campaign) {
            $this->error('No active referral campaign.');

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry');
        $targetOpt = $this->option('user');
        $windowDays = max(1, (int) ($campaign->visit_window_days ?? 30));
        $since = now()->subDays($windowDays);

        $query = User::query()->orderBy('id');

        if ($targetOpt) {
            if (is_numeric($targetOpt)) {
                $query->where('id', (int) $targetOpt);
            } else {
                $query->where('email', $targetOpt);
            }
        } else {
            $query->whereDoesntHave('referralProfile', function ($q) {
                $q->whereNotNull('referred_by_user_id');
            });
        }

        $repaired = 0;
        $skipped = 0;

        $query->chunkById(200, function ($users) use (&$repaired, &$skipped, $referrals, $qualifier, $campaign, $since, $dry) {
            foreach ($users as $user) {
                $profile = UserReferralProfile::query()->where('user_id', $user->id)->first();
                if ($profile && $profile->referred_by_user_id) {
                    $skipped++;

                    continue;
                }

                $visit = $this->findCandidateVisit($user, $since);
                if (! $visit) {
                    $skipped++;

                    continue;
                }

                $referrerProfile = UserReferralProfile::query()
                    ->where('referral_code', $visit->referral_code)
                    ->first();
                if (! $referrerProfile || (int) $referrerProfile->user_id === (int) $user->id) {
                    $skipped++;

                    continue;
                }

                $this->info(sprintf(
                    'Binding user #%d (%s) -> referrer #%d via visit #%d (%s)',
                    $user->id,
                    $user->email,
                    $referrerProfile->user_id,
                    $visit->id,
                    $visit->referral_code
                ));

                if ($dry) {
                    $repaired++;

                    continue;
                }

                $profile = $referrals->ensureProfile($user);
                $profile->forceFill([
                    'referred_by_user_id' => $referrerProfile->user_id,
                    'referral_source_ip' => $visit->visitor_ip,
                    'referral_source_device_id' => $visit->visitor_device_id,
                    'referred_at' => $visit->created_at,
                ])->save();

                if (! $visit->converted_user_id) {
                    $visit->forceFill([
                        'converted_user_id' => $user->id,
                        'converted_at' => now(),
                    ])->save();
                }

                ReferralConversion::query()->firstOrCreate(
                    [
                        'campaign_id' => $campaign->id,
                        'referred_user_id' => $user->id,
                    ],
                    [
                        'referrer_user_id' => $referrerProfile->user_id,
                        'visit_id' => $visit->id,
                        'status' => ReferralConversion::STATUS_PENDING,
                        'qualification_step' => (string) $campaign->qualification_step,
                        'reward_chars' => (int) $campaign->reward_chars,
                    ]
                );

                $referrals->recomputeProfileCounters($referrerProfile);

                // If the user already qualifies (e.g. active paid plan), promote now.
                if ($this->userHasActivePaidPlan($user)) {
                    $qualifier->evaluate($user, 'paid_plan');
                    $this->info("  → evaluated qualification (paid_plan) for user #{$user->id}");
                }

                $repaired++;
            }
        });

        $this->newLine();
        $this->info("Repaired: {$repaired} | Skipped: {$skipped}".($dry ? ' (dry run)' : ''));

        return self::SUCCESS;
    }

    private function findCandidateVisit(User $user, \DateTimeInterface $since): ?ReferralVisit
    {
        $base = fn () => ReferralVisit::query()
            ->whereNull('converted_user_id')
            ->where('created_at', '>=', $since)
            ->where('referrer_user_id', '!=', $user->id)
            ->orderByDesc('id');

        $deviceId = (string) ($user->registration_device_id ?? $user->last_seen_device_id ?? '');
        if ($deviceId !== '') {
            $hit = (clone $base())->where('visitor_device_id', $deviceId)->first();
            if ($hit) {
                return $hit;
            }
        }

        $ip = (string) ($user->registration_ip ?? $user->last_login_ip ?? '');
        if ($ip !== '') {
            $hit = (clone $base())->where('visitor_ip', $ip)->first();
            if ($hit) {
                return $hit;
            }
        }

        // Last resort: any visit whose created_at is within 24h before the user
        // registered — useful when the only anchor is timing.
        if ($user->created_at) {
            return (clone $base())
                ->where('created_at', '<=', $user->created_at)
                ->where('created_at', '>=', (clone $user->created_at)->subHours(24))
                ->first();
        }

        return null;
    }

    private function userHasActivePaidPlan(User $user): bool
    {
        return Subscription::query()
            ->where('subscriptions.user_id', $user->id)
            ->where('subscriptions.status', 'active')
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->where('plans.price_cents', '>', 0)
            ->exists();
    }
}
