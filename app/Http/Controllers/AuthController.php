<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class AuthController extends Controller
{
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
                'email' => 'Invalid credentials.',
            ])->onlyInput('email');
        }

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
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

        return redirect()->route('dashboard');
    }

    public function redirectToGoogle()
    {
        if (! config('services.google.client_id') || ! config('services.google.client_secret')) {
            return redirect()->route('login')->withErrors([
                'email' => 'Google login is not configured yet. Add GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET.',
            ]);
        }

        return Socialite::driver('google')
            ->scopes(['openid', 'profile', 'email'])
            ->redirect();
    }

    public function handleGoogleCallback(Request $request)
    {
        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (\Throwable) {
            return redirect()->route('login')->withErrors([
                'email' => 'Google login failed. Please try again.',
            ]);
        }

        $email = trim((string) $googleUser->getEmail());
        if ($email === '') {
            return redirect()->route('login')->withErrors([
                'email' => 'Google account did not return an email address.',
            ]);
        }

        $googleId = (string) $googleUser->getId();
        $avatar = $googleUser->getAvatar();

        $user = User::query()
            ->where('google_id', $googleId)
            ->orWhere('email', $email)
            ->first();

        if (! $user) {
            $name = trim((string) ($googleUser->getName() ?: $googleUser->getNickname() ?: 'Listen User'));

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

        Auth::login($user, true);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
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
        $hasActive = Subscription::query()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->exists();

        if ($hasActive) {
            return;
        }

        $freePlan = Plan::query()->where('slug', 'free')->first();
        if (! $freePlan) {
            return;
        }

        Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $freePlan->id,
            'status' => 'active',
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);
    }
}
