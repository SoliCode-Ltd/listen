<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Auth\EmailVerificationMailer;
use App\Services\PlanAccessService;
use App\Services\Referral\ReferralQualificationService;
use App\Support\EmailVerification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

/**
 * Confirming the email of an account made with an email and a password
 * (App\Support\EmailVerification): the link from the email, and a new link
 * for a signed-in member who asks for one.
 */
class EmailVerificationController extends Controller
{
    public function __construct(
        private readonly EmailVerificationMailer $mailer,
        private readonly PlanAccessService $planAccessService,
    ) {}

    /**
     * The link works whether or not anyone is signed in, and it confirms only
     * the account it was made for, whoever is signed in. It never signs anyone
     * in. A changed or expired link confirms nothing.
     */
    public function verify(Request $request, string $id, string $hash): RedirectResponse
    {
        $member = $this->hasCorrectSignature($request) ? User::query()->find((int) $id) : null;
        if (! $member || ! hash_equals(EmailVerificationMailer::addressHash((string) $member->email), $hash)) {
            return $this->refused($request, 'ui.verify_email.link_invalid');
        }

        if ($member->email_verified_at !== null) {
            return $this->confirmed($request, $member, true);
        }

        if (! URL::signatureHasNotExpired($request)) {
            return $this->refused($request, 'ui.verify_email.link_expired');
        }

        $member->forceFill(['email_verified_at' => now()])->save();

        // A referral campaign can reward the member who invited them once they confirm.
        try {
            app(ReferralQualificationService::class)->evaluate($member, 'email_verified');
        } catch (\Throwable $e) {
            Log::warning('Referral qualification (email verified) evaluation failed.', [
                'user_id' => $member->id,
                'exception' => $e->getMessage(),
            ]);
        }

        return $this->confirmed($request, $member, false);
    }

    /**
     * A new link for the signed-in member, in the language of the page they
     * are on. Limited to a few an hour (the verify-email rate limit).
     */
    public function resend(Request $request): JsonResponse|RedirectResponse
    {
        $user = $request->user();

        if (EmailVerification::isVerified($user)) {
            return $this->resendReply($request, true, __('ui.verify_email.already_verified'));
        }

        if (! $this->mailer->send($user, app()->getLocale())) {
            return $this->resendReply($request, false, __('ui.verify_email.resend_failed'));
        }

        return $this->resendReply($request, true, __('ui.verify_email.resent', [
            'email' => (string) $user->email,
            'days' => EmailVerification::LINK_DAYS,
        ]));
    }

    private function confirmed(Request $request, User $member, bool $already): RedirectResponse
    {
        $viewer = $request->user();
        if (! $viewer) {
            return redirect()->route('login')->with('verify_email_status', __(
                $already ? 'ui.verify_email.already_verified' : 'ui.verify_email.verified_sign_in'
            ));
        }

        if ((int) $viewer->id !== (int) $member->id) {
            return $this->toMemberPage($viewer, 'success', __('ui.verify_email.verified_other_account'));
        }

        $demo = $this->usesTheDemo($viewer);
        $message = match (true) {
            $already => __('ui.verify_email.already_verified'),
            $demo => __('ui.verify_email.verified'),
            default => __('ui.verify_email.verified_plain'),
        };

        return $this->toMemberPage($viewer, 'success', $message, $demo);
    }

    private function refused(Request $request, string $messageKey): RedirectResponse
    {
        $viewer = $request->user();
        if (! $viewer) {
            return redirect()->route('login')->withErrors([
                'auth' => __($messageKey).' '.__('ui.verify_email.sign_in_for_new_link'),
            ]);
        }

        return $this->toMemberPage($viewer, 'error', __($messageKey));
    }

    /**
     * The demo page, where a member who still has to confirm finds the button
     * for a new link; members with a plan, who never use the demo, the dashboard.
     */
    private function toMemberPage(User $viewer, string $type, string $message, ?bool $demo = null): RedirectResponse
    {
        if ($demo ?? $this->usesTheDemo($viewer)) {
            return redirect()->route('demo.page')->with('verify_email_notice', ['type' => $type, 'message' => $message]);
        }

        return redirect()->route('dashboard')->with($type === 'error' ? 'error' : 'ok', $message);
    }

    /** The demo.free rule: the demo is for members on the free plan. */
    private function usesTheDemo(User $user): bool
    {
        $plan = $this->planAccessService->resolveActivePlanForUserId((int) $user->id);

        return strtolower(trim((string) ($plan?->slug ?? 'free'))) === 'free';
    }

    private function resendReply(Request $request, bool $ok, string $message): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['ok' => $ok, 'message' => $message], $ok ? 200 : 503);
        }

        return redirect()->back(fallback: route('demo.page'))
            ->with('verify_email_notice', ['type' => $ok ? 'success' : 'error', 'message' => $message]);
    }

    /**
     * As for the newsletter's unsubscribe links: the links are signed for the
     * https address, but behind the host's proxy PHP can see the request as
     * plain http, where that signature would not match. So a signature counts
     * for the address as it came in or for the same address over https; a link
     * with anything changed fails both. Whether it has expired is checked apart.
     */
    private function hasCorrectSignature(Request $request): bool
    {
        if (URL::hasCorrectSignature($request)) {
            return true;
        }

        if ($request->secure()) {
            return false;
        }

        $query = (string) $request->server->get('QUERY_STRING');
        $overHttps = Request::create('https://'.$request->getHost().$request->getBaseUrl().$request->getPathInfo().($query !== '' ? '?'.$query : ''));

        return URL::hasCorrectSignature($overHttps);
    }
}
