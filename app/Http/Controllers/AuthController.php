<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\BlockedUserService;
use App\Services\PlanAccessService;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;

class AuthController extends Controller
{
    public function __construct(
        private readonly PlanAccessService $planAccessService,
        private readonly BlockedUserService $blockedUserService,
    ) {
    }

    public function showLogin()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
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

        $request->session()->regenerate();

        $intended = $request->session()->pull('url.intended', route('dashboard'));
        if (str_contains($intended, '/admin') && ! Auth::user()->is_admin) {
            $intended = route('dashboard');
        }

        return redirect()->to($intended);
    }

    public function showRegister()
    {
        return view('auth.register');
    }

    public function register(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);

        $this->attachFreePlanIfMissing($user);

        Auth::login($user);
        $request->session()->regenerate();

        $intended = $request->session()->pull('url.intended', route('dashboard'));

        return redirect()->to($intended);
    }

    public function redirectToGoogle()
    {
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
        if ($email === '') {
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

            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make(Str::password(32)),
                'google_id' => $googleId,
                'google_avatar' => is_string($avatar) ? $avatar : null,
                'email_verified_at' => now(),
            ]);
        } else {
            $user->update([
                'google_id' => $user->google_id ?: $googleId,
                'google_avatar' => is_string($avatar) ? $avatar : $user->google_avatar,
                'email_verified_at' => $user->email_verified_at ?? now(),
            ]);
        }

        $this->attachFreePlanIfMissing($user);

        if ($this->blockedUserService->isBlocked($user)) {
            return redirect()
                ->route('login')
                ->with('blocked_notice', $this->blockedUserService->noticePayload($user));
        }

        Auth::login($user, true);
        $request->session()->regenerate();

        $intended = $request->session()->pull('url.intended', route('dashboard'));
        if (str_contains($intended, '/admin') && ! $user->is_admin) {
            $intended = route('dashboard');
        }

        return redirect()->to($intended);
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
