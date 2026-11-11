<?php

namespace App\Http\Controllers;

use App\Models\PasswordResetRequest;
use App\Models\User;
use App\Services\Auth\PasswordResetMailer;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * "Forgot your password?" from the sign-in page: the page that asks for the account's email,
 * and the page the emailed link opens to choose a new password
 * (App\Services\Auth\PasswordResetMailer). Changing the password never signs anyone in: the
 * member signs in with it afterwards, through the usual sign-in and its checks.
 */
class PasswordResetController extends Controller
{
    public function __construct(private readonly PasswordResetMailer $mailer) {}

    public function showForgot(): Response
    {
        return $this->page('auth.forgot-password');
    }

    /**
     * The page answers the same whether or not the address has an account, so it never tells
     * a stranger who is a member. Limited to a few an hour (the password-reset rate limit).
     */
    public function sendLink(Request $request): RedirectResponse
    {
        $email = (string) $request->validate(['email' => ['required', 'string', 'email', 'max:255']])['email'];

        $this->mailer->request($email, app()->getLocale(), $request->ip(), $request->userAgent());

        return redirect()->route('password.request')->with('password_reset_notice', __('ui.reset_password.sent', [
            'minutes' => PasswordResetRequest::linkMinutes(),
        ]));
    }

    /** A link that has run out or was used goes back to the first page, to ask for a new one. */
    public function showReset(Request $request, string $token): Response|RedirectResponse
    {
        $email = trim((string) $request->query('email', ''));
        $user = $this->mailer->account($email);

        if (! $user || ! Password::broker()->tokenExists($user, $token)) {
            return $this->linkRefused();
        }

        return $this->page('auth.reset-password', ['token' => $token, 'email' => (string) $user->email]);
    }

    public function reset(Request $request): RedirectResponse
    {
        $input = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'password.min' => __('ui.reset_password.password_too_short'),
            'password.confirmed' => __('ui.reset_password.password_mismatch'),
        ]);

        $changed = null;
        $status = Password::broker()->reset($input + ['password_confirmation' => $input['password']], function (User $user, string $password) use (&$changed): void {
            // The model hashes it; a new remember token signs out every "remember me" elsewhere.
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            $changed = $user;
            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET || ! $changed) {
            return $this->linkRefused();
        }

        $this->mailer->completed($changed, $request->ip());

        // Whoever was signed in on this browser signs in again, with the new password.
        if (Auth::check()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return redirect()->route('login')
            ->with('password_reset_status', __('ui.reset_password.done'))
            ->withInput(['email' => (string) $changed->email]);
    }

    private function linkRefused(): RedirectResponse
    {
        return redirect()->route('password.request')->withErrors(['email' => __('ui.reset_password.link_invalid')]);
    }

    /** Never kept by a browser or a proxy: the pages hold an address and a one-time link. */
    private function page(string $view, array $data = []): Response
    {
        $response = response()->view($view, $data);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
