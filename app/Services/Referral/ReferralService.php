<?php

namespace App\Services\Referral;

use App\Models\ReferralCampaign;
use App\Models\ReferralConversion;
use App\Models\ReferralVisit;
use App\Models\SocialShareLog;
use App\Models\User;
use App\Models\UserReferralProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ReferralService
{
    public const VISIT_COOKIE = 'listen_ref_visit';
    public const VISIT_COOKIE_TTL_MIN = 60 * 24 * 45; // 45 days cap; campaign window may be shorter

    public function __construct(
        private readonly DemoRewardLedgerService $ledger,
        private readonly ReferralAbuseGuard $abuseGuard,
    ) {}

    /**
     * Returns the user's referral profile, creating it if absent.
     */
    public function profileFor(User $user): UserReferralProfile
    {
        $profile = UserReferralProfile::query()->where('user_id', $user->id)->first();
        if ($profile) {
            return $profile;
        }

        return $this->ensureProfile($user);
    }

    public function ensureProfile(User $user): UserReferralProfile
    {
        return DB::transaction(function () use ($user): UserReferralProfile {
            $existing = UserReferralProfile::query()->where('user_id', $user->id)->lockForUpdate()->first();
            if ($existing) {
                return $existing;
            }

            $code = $this->generateUniqueCode($user);

            return UserReferralProfile::query()->create([
                'user_id' => $user->id,
                'referral_code' => $code,
            ]);
        });
    }

    private function generateUniqueCode(User $user): string
    {
        // Deterministic prefix from user id, plus random suffix for uniqueness.
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $candidate = strtoupper(Str::slug(substr((string) $user->id, -4), ''))
                .strtoupper(Str::random(6));
            $candidate = preg_replace('/[^A-Z0-9]/', '', $candidate);
            if ($candidate === null || strlen($candidate) < 6) {
                $candidate = strtoupper(Str::random(8));
            }

            if (! UserReferralProfile::query()->where('referral_code', $candidate)->exists()) {
                return $candidate;
            }
        }

        return strtoupper(Str::random(10));
    }

    public function buildReferralLink(User $user, string $locale = 'en'): string
    {
        $profile = $this->profileFor($user);
        $locale = $locale === 'ar' ? 'ar' : 'en';

        return url('/'.$locale.'/demo').'?ref='.$profile->referral_code;
    }

    /**
     * Track a visit to the demo page carrying a ?ref=CODE query string.
     * Idempotent by (code, session_id, day) — won't double-log refresh storms.
     */
    public function trackVisit(Request $request, string $referralCode): ?ReferralVisit
    {
        $referralCode = strtoupper(trim($referralCode));
        if ($referralCode === '') {
            return null;
        }

        $campaign = ReferralCampaign::active();
        if (! $campaign) {
            return null;
        }

        $profile = UserReferralProfile::query()
            ->where('referral_code', $referralCode)
            ->first();

        if (! $profile) {
            return null;
        }

        $sessionId = $request->hasSession() ? $request->session()->getId() : null;
        $deviceId = $this->extractDeviceId($request);
        $ip = $request->ip();

        // Don't self-track: if the authenticated visitor is the referrer itself.
        if ($request->user() && (int) $request->user()->id === (int) $profile->user_id) {
            return null;
        }

        $existing = ReferralVisit::query()
            ->where('referral_code', $referralCode)
            ->when($sessionId, fn ($q) => $q->where('visitor_session_id', $sessionId))
            ->where('created_at', '>=', now()->subHours(6))
            ->first();

        if ($existing) {
            return $existing;
        }

        $locale = str_starts_with(strtolower((string) app()->getLocale()), 'ar') ? 'ar' : 'en';

        return ReferralVisit::query()->create([
            'campaign_id' => $campaign->id,
            'referrer_user_id' => $profile->user_id,
            'referral_code' => $referralCode,
            'landed_locale' => $locale,
            'visitor_ip' => $ip,
            'visitor_device_id' => $deviceId,
            'visitor_session_id' => $sessionId,
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
            'http_referer' => mb_substr((string) $request->headers->get('referer'), 0, 1000) ?: null,
        ]);
    }

    /**
     * Bind the referred_by relationship immediately after a new user registers.
     * Called with the raw referral code from the cookie/session.
     */
    public function attachReferrerOnRegister(User $newUser, ?string $referralCode, Request $request): ?UserReferralProfile
    {
        $referralCode = strtoupper(trim((string) $referralCode));

        $campaign = ReferralCampaign::active();
        if (! $campaign) {
            return $this->ensureProfile($newUser);
        }

        $windowDays = max(1, (int) ($campaign->visit_window_days ?? 30));

        // Cookie may be lost between the demo-page visit and the register POST
        // (locale redirects strip query strings, third-party cookie blocks,
        // session regeneration on login flows). Fall back to matching an
        // unconverted ReferralVisit by session id, then by IP, so the binding
        // can still be established within the campaign visit window.
        if ($referralCode === '') {
            $fallbackVisit = $this->findFallbackVisit($request, $windowDays, (int) $newUser->id);
            if (! $fallbackVisit) {
                return $this->ensureProfile($newUser);
            }
            $referralCode = strtoupper((string) $fallbackVisit->referral_code);
        }

        $referrerProfile = UserReferralProfile::query()
            ->where('referral_code', $referralCode)
            ->first();

        if (! $referrerProfile || (int) $referrerProfile->user_id === (int) $newUser->id) {
            return $this->ensureProfile($newUser);
        }

        // Make sure the visit is fresh enough (within the campaign window).
        $visit = ReferralVisit::query()
            ->where('referral_code', $referralCode)
            ->where('referrer_user_id', $referrerProfile->user_id)
            ->where('created_at', '>=', now()->subDays($windowDays))
            ->orderByDesc('id')
            ->first();

        $profile = $this->ensureProfile($newUser);
        $profile->forceFill([
            'referred_by_user_id' => $referrerProfile->user_id,
            'referral_source_ip' => $request->ip(),
            'referral_source_device_id' => $this->extractDeviceId($request),
            'referred_at' => now(),
        ])->save();

        if ($visit && ! $visit->converted_user_id) {
            $visit->forceFill([
                'converted_user_id' => $newUser->id,
                'converted_at' => now(),
            ])->save();
        }

        // Create a pending conversion immediately — it will be promoted to
        // approved / flagged / rejected when the qualification event fires.
        try {
            ReferralConversion::query()->firstOrCreate(
                [
                    'campaign_id' => $campaign->id,
                    'referred_user_id' => $newUser->id,
                ],
                [
                    'referrer_user_id' => $referrerProfile->user_id,
                    'visit_id' => $visit?->id,
                    'status' => ReferralConversion::STATUS_PENDING,
                    'qualification_step' => (string) $campaign->qualification_step,
                    'reward_chars' => (int) $campaign->reward_chars,
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('Failed to create pending referral conversion.', [
                'new_user_id' => $newUser->id,
                'referrer_user_id' => $referrerProfile->user_id,
                'exception' => $e->getMessage(),
            ]);
        }

        $this->recomputeProfileCounters($referrerProfile);

        return $profile;
    }

    public function logShare(User $user, string $platform, ?Request $request = null): SocialShareLog
    {
        $platform = strtolower(trim($platform));
        if (! in_array($platform, SocialShareLog::PLATFORMS, true)) {
            $platform = 'copy_link';
        }

        $campaign = ReferralCampaign::active();
        $profile = $this->profileFor($user);
        $locale = str_starts_with(strtolower((string) app()->getLocale()), 'ar') ? 'ar' : 'en';

        return SocialShareLog::query()->create([
            'user_id' => $user->id,
            'campaign_id' => $campaign?->id,
            'platform' => $platform,
            'locale' => $locale,
            'referral_code' => $profile->referral_code,
            'referral_link' => $this->buildReferralLink($user, $locale),
            'visitor_ip' => $request?->ip(),
            'visitor_device_id' => $request ? $this->extractDeviceId($request) : null,
            'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 500) : null,
        ]);
    }

    /**
     * Quick stats payload for the user-facing popup.
     *
     * @return array{
     *   code:string,
     *   link_en:string,
     *   link_ar:string,
     *   pending:int,
     *   approved:int,
     *   reward_chars_earned:int,
     *   reward_chars_balance:int,
     * }
     */
    public function statsPayload(User $user): array
    {
        $profile = $this->profileFor($user);

        $pending = ReferralConversion::query()
            ->where('referrer_user_id', $user->id)
            ->where('status', ReferralConversion::STATUS_PENDING)
            ->count();

        $approved = ReferralConversion::query()
            ->where('referrer_user_id', $user->id)
            ->where('status', ReferralConversion::STATUS_APPROVED)
            ->count();

        return [
            'code' => (string) $profile->referral_code,
            'link_en' => $this->buildReferralLink($user, 'en'),
            'link_ar' => $this->buildReferralLink($user, 'ar'),
            'pending' => $pending,
            'approved' => $approved,
            'reward_chars_earned' => $this->ledger->totalGrantedFor($user),
            'reward_chars_balance' => $this->ledger->balanceFor($user),
        ];
    }

    public function recomputeProfileCounters(UserReferralProfile $referrerProfile): void
    {
        $approved = ReferralConversion::query()
            ->where('referrer_user_id', $referrerProfile->user_id)
            ->where('status', ReferralConversion::STATUS_APPROVED)
            ->count();

        $pending = ReferralConversion::query()
            ->where('referrer_user_id', $referrerProfile->user_id)
            ->where('status', ReferralConversion::STATUS_PENDING)
            ->count();

        $referrerProfile->forceFill([
            'approved_conversions_count' => $approved,
            'pending_conversions_count' => $pending,
        ])->save();
    }

    private function findFallbackVisit(Request $request, int $windowDays, int $excludeUserId): ?ReferralVisit
    {
        $sessionId = $request->hasSession() ? $request->session()->getId() : null;
        $deviceId = $this->extractDeviceId($request);
        $ip = $request->ip();
        $since = now()->subDays($windowDays);

        $base = fn () => ReferralVisit::query()
            ->whereNull('converted_user_id')
            ->where('created_at', '>=', $since)
            ->where('referrer_user_id', '!=', $excludeUserId)
            ->orderByDesc('id');

        if ($sessionId) {
            $bySession = (clone $base())->where('visitor_session_id', $sessionId)->first();
            if ($bySession) {
                return $bySession;
            }
        }

        if ($deviceId) {
            $byDevice = (clone $base())->where('visitor_device_id', $deviceId)->first();
            if ($byDevice) {
                return $byDevice;
            }
        }

        if ($ip) {
            $byIp = (clone $base())->where('visitor_ip', $ip)->first();
            if ($byIp) {
                return $byIp;
            }
        }

        return null;
    }

    private function extractDeviceId(Request $request): ?string
    {
        $cookie = (string) $request->cookie('listen_demo_device', '');
        if ($cookie !== '') {
            return mb_substr($cookie, 0, 80);
        }

        return null;
    }
}
