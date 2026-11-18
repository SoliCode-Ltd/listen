<?php

namespace App\Http\Controllers;

use App\Services\MemberAccountDeletion;
use App\Support\InApp\InApp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * "Delete my account": a member deletes their own account, on the site and in
 * the apps, without writing to anyone (App\Services\MemberAccountDeletion).
 *
 * One page says what is deleted, what stays, and what happens to a running
 * subscription; one tick and one button do it; a last page says it is done.
 */
class AccountDeletionController extends Controller
{
    public function __construct(private readonly MemberAccountDeletion $deletion) {}

    public function show(Request $request): View
    {
        $user = $request->user();

        return view('account.delete', [
            'member' => $user,
            'refusal' => $this->deletion->refusal($user),
            'billing' => $this->deletion->billingToEnd($user),
            'backUrl' => InApp::active($request) ? route('app.plan') : route('dashboard'),
        ]);
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->validate(['confirm' => ['accepted']], ['confirm.accepted' => __('account.delete.confirm_required')]);

        $user = $request->user();
        $failure = $this->deletion->delete($user);
        if ($failure !== null) {
            return redirect()->route('account.delete')->withErrors(['delete' => $failure]);
        }

        // The account is gone: this phone or browser is signed out, as every other one was.
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('account.deleted');
    }

    /** "Your account has been deleted." Anyone may read it: there is no account to sign in to any more. */
    public function done(Request $request): View
    {
        return view('account.deleted', [
            'nextUrl' => InApp::active($request) ? InApp::homeUrl() : route('home'),
        ]);
    }
}
