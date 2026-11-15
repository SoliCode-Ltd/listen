<?php

namespace App\Http\Controllers;

use App\Models\PurchaseFollowUp;
use App\Services\Billing\PurchaseFollowUps;
use App\Support\SignedLinks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The button in the email after a declined payment (App\Services\Billing\PurchaseFollowUps).
 * While Stripe still holds that payment open the member goes straight to Stripe's page for it
 * and pays there; once it has closed, to the checkout for the same plan (a first payment) or
 * to their own subscription page (a renewal). So the button works whenever the email is read.
 * The link is signed: nothing else opens it.
 */
class PurchaseFollowUpLinkController extends Controller
{
    public function __invoke(Request $request, int $followUp, PurchaseFollowUps $followUps): RedirectResponse
    {
        $lang = $request->query('lang') === 'en' ? 'en' : 'ar';
        $plans = rtrim((string) config('app.url', ''), '/').'/'.$lang.'/pricing';

        $case = SignedLinks::valid($request) && PurchaseFollowUp::ready()
            ? PurchaseFollowUp::query()->find($followUp)
            : null;
        if (! $case) {
            return redirect()->to($plans);
        }

        $payment = $followUps->paymentPageUrl($case);

        return $payment !== null
            ? redirect()->away($payment)
            : redirect()->to($followUps->fallbackUrl($case, $lang));
    }
}
