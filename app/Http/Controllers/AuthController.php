<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\PhoneAuthBridgeRequest;
use App\Models\User;
use App\Services\BlockedUserService;
use App\Services\MultiAccountEnforcementService;
use App\Services\Auth\AuthSettingsService;
use App\Services\Auth\PhoneAuthBridgeService;
use App\Services\PlanAccessService;
use App\Services\Referral\ReferralService;
use App\Http\Middleware\CaptureReferralVisit;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;

class AuthController extends Controller
{
    private const DEVICE_COOKIE = 'listen_demo_device';

    public function __construct(
        private readonly PlanAccessService $planAccessService,
        private readonly BlockedUserService $blockedUserService,
        private readonly AuthSettingsService $authSettings,
        private readonly PhoneAuthBridgeService $phoneAuthBridge,
        private readonly MultiAccountEnforcementService $multiAccountEnforcement,
        private readonly ReferralService $referralService,
    ) {
    }

    private function bindReferralFromRequest(Request $request, User $user): void
    {
        try {
            $code = trim((string) $request->cookie(CaptureReferralVisit::COOKIE_NAME, ''));
            if ($code === '') {
                $this->referralService->ensureProfile($user);

                return;
            }

            $this->referralService->attachReferrerOnRegister($user, $code, $request);
            \Illuminate\Support\Facades\Cookie::queue(
                \Illuminate\Support\Facades\Cookie::forget(CaptureReferralVisit::COOKIE_NAME)
            );
        } catch (\Throwable $e) {
            Log::warning('Failed to bind referral on registration.', [
                'user_id' => $user->id,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Where a member lands after signing in or up: the page they were heading
     * to, else the dashboard. A member without a paid plan has no credits
     * there, so they start on the free demo instead.
     */
    private function landingAfterSignIn(Request $request, User $user): string
    {
        $dashboard = route('dashboard');
        $intended = (string) $request->session()->pull('url.intended', $dashboard);
        if (str_contains($intended, '/admin') && ! $user->is_admin) {
            $intended = $dashboard;
        }

        if ($this->isDashboardUrl($intended) && ! $user->is_admin && ! $this->hasPaidPlan($user)) {
            return route('demo.page');
        }

        return $intended;
    }

    private function isDashboardUrl(string $url): bool
    {
        $path = rtrim((string) parse_url($url, PHP_URL_PATH), '/');
        $path = (string) preg_replace('#^/[a-z]{2}(?=/|$)#', '', $path);

        return $path === '/dashboard';
    }

    private function hasPaidPlan(User $user): bool
    {
        $plan = $this->planAccessService->resolveAccessPlanForUserId((int) $user->id);

        return $plan !== null && ($plan->isPack() || (int) $plan->price_cents > 0);
    }

    public function showLogin()
    {
        $request = request();
        $response = response()->view('auth.login', [
            'authSettings' => $this->authSettings->authUiPayload(),
        ]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $this->attachDeviceCookie($request, $response);
    }

    public function login(Request $request)
    {
        if (! $this->authSettings->emailEnabled()) {
            return back()->withErrors([
                'auth' => __('ui.backend.auth_email_disabled'),
            ]);
        }

        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['nullable', 'boolean'],
        ]);

        $remember = (bool) ($credentials['remember'] ?? false);
        unset($credentials['remember']);

        if (! Auth::attempt($credentials, $remember)) {
            return back()->withErrors([
                'email' => __('ui.backend.auth_invalid_credentials'),
            ])->onlyInput('email');
        }

        $user = Auth::user();
        if ($this->blockedUserService->isBlocked($user)) {
            Auth::logout();

            return back()
                ->with('blocked_notice', $this->blockedUserService->noticePayload($user))
                ->onlyInput('email');
        }

        $this->stampLoginIp($user, $request);

        $user->refresh();
        $this->multiAccountEnforcement->enforceAfterAuth($user);

        if ($user->isBlocked()) {
            Auth::logout();

            return back()
                ->with('blocked_notice', $this->blockedUserService->noticePayload($user))
                ->onlyInput('email');
        }

        $request->session()->regenerate();

        $intended = $this->landingAfterSignIn($request, Auth::user());

        return $this->attachDeviceCookie($request, redirect()->to($intended));
    }

    public function showRegister()
    {
        $request = request();
        $response = response()->view('auth.register', [
            'authSettings' => $this->authSettings->authUiPayload(),
        ]);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $this->attachDeviceCookie($request, $response);
    }

    public function register(Request $request)
    {
        if (! $this->authSettings->emailEnabled()) {
            return back()->withErrors([
                'auth' => __('ui.backend.auth_email_disabled'),
            ]);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $deviceId = $this->deviceIdFromRequest($request);
        $payload = [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'registration_ip' => $request->ip(),
            'registration_device_id' => $deviceId,
            'last_seen_device_id' => $deviceId,
        ];

        $user = User::create($payload);

        $this->attachFreePlanIfMissing($user);
        $this->bindReferralFromRequest($request, $user);

        Auth::login($user, true);
        $this->stampLoginIp($user, $request);
        $request->session()->regenerate();

        $user->refresh();
        $this->multiAccountEnforcement->enforceAfterAuth($user);

        if ($user->isBlocked()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return $this->attachDeviceCookie(
                $request,
                redirect()->route('login')
                    ->with('blocked_notice', $this->blockedUserService->noticePayload($user))
            );
        }

        $intended = $this->landingAfterSignIn($request, $user);

        return $this->attachDeviceCookie($request, redirect()->to($intended));
    }

    public function redirectToGoogle()
    {
        if (! $this->authSettings->googleEnabled()) {
            return redirect()->route('login')->withErrors([
                'auth' => __('ui.backend.auth_google_disabled'),
            ]);
        }

        if (! config('services.google.client_id') || ! config('services.google.client_secret')) {
            return redirect()->route('login')->withErrors([
                'email' => __('ui.backend.auth_social_not_configured'),
            ]);
        }

        return $this->googleProvider()
            ->scopes(['openid', 'profile', 'email'])
            ->redirect();
    }

    public function handleGoogleCallback(Request $request)
    {
        if (! $this->authSettings->googleEnabled()) {
            return redirect()->route('login')->withErrors([
                'auth' => __('ui.backend.auth_google_disabled'),
            ]);
        }

        $deviceId = $this->deviceIdFromRequest($request);

        try {
            $googleUser = $this->googleProvider()->user();
        } catch (InvalidStateException) {
            return redirect()->route('login')->withErrors([
                'email' => __('ui.backend.auth_social_session_expired'),
            ]);
        } catch (\Throwable $exception) {
            Log::warning('Social OAuth callback failed.', [
                'message' => $exception->getMessage(),
                'exception' => get_class($exception),
            ]);

            return redirect()->route('login')->withErrors([
                'email' => __('ui.backend.auth_social_failed'),
            ]);
        }

        $email = trim((string) $googleUser->getEmail());
        // Google reports whether it verified the address; never match an account by an unverified one.
        $emailVerified = $googleUser->user['email_verified'] ?? $googleUser->user['verified_email'] ?? true;
        if ($email === '' || $emailVerified === false || $emailVerified === 'false') {
            return redirect()->route('login')->withErrors([
                'email' => __('ui.backend.auth_social_no_email'),
            ]);
        }

        $googleId = (string) $googleUser->getId();
        $avatar = $googleUser->getAvatar();

        $user = User::query()
            ->where('google_id', $googleId)
            ->orWhere('email', $email)
            ->first();

        if (! $user) {
            $name = trim((string) ($googleUser->getName() ?: $googleUser->getNickname() ?: __('ui.backend.auth_social_default_name')));
            $payload = [
                'name' => $name,
                'email' => $email,
                'password' => Hash::make(Str::password(32)),
                'google_id' => $googleId,
                'google_avatar' => is_string($avatar) ? $avatar : null,
                'email_verified_at' => now(),
                'registration_ip' => $request->ip(),
                'registration_device_id' => $deviceId,
                'last_seen_device_id' => $deviceId,
            ];

            $user = User::create($payload);
            $justCreated = true;
        } else {
            $user->update([
                'google_id' => $user->google_id ?: $googleId,
                'google_avatar' => is_string($avatar) ? $avatar : $user->google_avatar,
                'email_verified_at' => $user->email_verified_at ?? now(),
            ]);
            $justCreated = false;
        }

        $this->attachFreePlanIfMissing($user);

        if ($justCreated) {
            $this->bindReferralFromRequest($request, $user);
        }

        if ($this->blockedUserService->isBlocked($user)) {
            return $this->attachDeviceCookie(
                $request,
                redirect()
                    ->route('login')
                    ->with('blocked_notice', $this->blockedUserService->noticePayload($user))
            );
        }

        $this->stampLoginIp($user, $request);

        $user->refresh();
        $this->multiAccountEnforcement->enforceAfterAuth($user);

        if ($user->isBlocked()) {
            return $this->attachDeviceCookie(
                $request,
                redirect()
                    ->route('login')
                    ->with('blocked_notice', $this->blockedUserService->noticePayload($user))
            );
        }

        Auth::login($user, true);
        $request->session()->regenerate();

        $intended = $this->landingAfterSignIn($request, $user);

        return $this->attachDeviceCookie($request, redirect()->to($intended));
    }

    public function firebaseGoogleAuthenticate(PhoneAuthBridgeRequest $request)
    {
        if (! $this->authSettings->firebaseGoogleEnabled()) {
            return response()->json([
                'ok' => false,
                'message' => __('ui.backend.auth_firebase_google_disabled'),
            ], 403);
        }

        try {
            $user = $this->phoneAuthBridge->resolveUserFromGoogleIdToken((string) $request->string('id_token'), $request->ip());
        } catch (\Throwable $exception) {
            Log::warning('Firebase Google auth bridge failed.', [
                'message' => $exception->getMessage(),
                'exception' => get_class($exception),
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'ok' => false,
                'message' => __('ui.backend.auth_firebase_google_failed'),
            ], 422);
        }

        if ($this->blockedUserService->isBlocked($user)) {
            return response()->json([
                'ok' => false,
                'message' => $this->blockedUserService->messageFor($user),
                'blocked_notice' => $this->blockedUserService->noticePayload($user),
            ], 403);
        }

        $this->stampLoginIp($user, $request);

        $user->refresh();
        $this->multiAccountEnforcement->enforceAfterAuth($user);

        if ($user->isBlocked()) {
            return response()->json([
                'ok' => false,
                'message' => $this->blockedUserService->messageFor($user),
                'blocked_notice' => $this->blockedUserService->noticePayload($user),
            ], 403);
        }

        Auth::login($user, true);
        $request->session()->regenerate();

        $intended = $this->landingAfterSignIn($request, $user);

        return $this->attachDeviceCookie($request, response()->json([
            'ok' => true,
            'redirect' => $intended,
        ]));
    }

    public function phoneAuthenticate(PhoneAuthBridgeRequest $request)
    {
        if (! $this->authSettings->phoneEnabled()) {
            return response()->json([
                'ok' => false,
                'message' => __('ui.backend.auth_phone_disabled'),
            ], 403);
        }

        try {
            $user = $this->phoneAuthBridge->resolveUserFromIdToken((string) $request->string('id_token'), $request->ip());
        } catch (\Throwable $exception) {
            Log::warning('Firebase phone auth bridge failed.', [
                'message' => $exception->getMessage(),
                'exception' => get_class($exception),
                'ip' => $request->ip(),
                'intent' => (string) $request->string('intent'),
            ]);

            return response()->json([
                'ok' => false,
                'message' => __('ui.backend.auth_phone_verification_failed'),
            ], 422);
        }

        if ($this->blockedUserService->isBlocked($user)) {
            return response()->json([
                'ok' => false,
                'message' => $this->blockedUserService->messageFor($user),
                'blocked_notice' => $this->blockedUserService->noticePayload($user),
            ], 403);
        }

        $this->stampLoginIp($user, $request);

        $user->refresh();
        $this->multiAccountEnforcement->enforceAfterAuth($user);

        if ($user->isBlocked()) {
            return response()->json([
                'ok' => false,
                'message' => $this->blockedUserService->messageFor($user),
                'blocked_notice' => $this->blockedUserService->noticePayload($user),
            ], 403);
        }

        Auth::login($user, true);
        $request->session()->regenerate();

        $intended = $this->landingAfterSignIn($request, $user);

        return $this->attachDeviceCookie($request, response()->json([
            'ok' => true,
            'redirect' => $intended,
        ]));
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }

    private function attachFreePlanIfMissing(User $user): void
    {
        $this->planAccessService->ensureFreePlanForUser($user);
    }

    private function stampLoginIp($user, Request $request): void
    {
        if (! $user instanceof User) {
            return;
        }

        $updates = [];
        $ip = trim((string) $request->ip());

        if ($ip !== '' && (string) $user->last_login_ip !== $ip) {
            $updates['last_login_ip'] = $ip;
        }

        if ($ip !== '' && ! filled($user->registration_ip)) {
            $updates['registration_ip'] = $ip;
        }

        $deviceId = $this->deviceIdFromRequest($request);
        if ($deviceId !== null && (string) $user->last_seen_device_id !== $deviceId) {
            $updates['last_seen_device_id'] = $deviceId;
        }

        if ($deviceId !== null && ! filled($user->registration_device_id)) {
            $updates['registration_device_id'] = $deviceId;
        }

        if ($updates === []) {
            return;
        }

        $user->forceFill($updates)->saveQuietly();
        Cache::forget("demo:ip_linked_users:{$user->id}");
        Cache::forget('admin:suspicious_users_count');
    }

    private function deviceIdFromRequest(Request $request): ?string
    {
        $raw = trim((string) $request->cookie(self::DEVICE_COOKIE, ''));

        if ($raw !== '' && preg_match('/^[A-Za-z0-9_-]{24,128}$/', $raw) === 1) {
            return $raw;
        }

        return null;
    }

    /**
     * @template T of \Symfony\Component\HttpFoundation\Response
     * @param  T  $response
     * @return T
     */
    private function attachDeviceCookie(Request $request, $response)
    {
        if (! method_exists($response, 'cookie')) {
            return $response;
        }

        if ($this->deviceIdFromRequest($request) !== null) {
            return $response;
        }

        return $response->cookie(
            cookie(
                self::DEVICE_COOKIE,
                Str::random(48),
                60 * 24 * 730,
                '/',
                null,
                $request->isSecure(),
                true,
                false,
                'lax'
            )
        );
    }

    private function googleProvider()
    {
        $provider = Socialite::driver('google');
        $verifySsl = (bool) config('services.google.verify_ssl', true);

        if (! $verifySsl) {
            $provider->setHttpClient(new GuzzleClient(['verify' => false]));
        }

        return $provider;
    }
}
