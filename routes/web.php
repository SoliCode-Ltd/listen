<?php

use App\Http\Controllers\AdminClonedVoiceAudioController;
use App\Http\Controllers\AdminManualPaymentProofController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExtensionController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ManualPaymentController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\NewsletterUnsubscribeController;
use App\Http\Controllers\VoiceCloningController;
use App\Models\SiteSetting;
use App\Support\SeoHelper;
use Carbon\Carbon;
use Illuminate\Support\Facades\Route;
use Mcamara\LaravelLocalization\Facades\LaravelLocalization;

Route::get('/media/{path}', [MediaController::class, 'show'])
    ->where('path', '.*')
    ->name('media.file');

Route::middleware('auth')->get('/manual-payment-proof/{email}/{proof_token}', AdminManualPaymentProofController::class)
    ->name('admin.manual-payments.proof');

Route::middleware('auth')->get('/admin-audio/cloned-voices/{voice}/{kind}', AdminClonedVoiceAudioController::class)
    ->whereNumber('voice')
    ->whereIn('kind', ['sample', 'consent'])
    ->name('admin.cloned-voices.audio');

// SEO: Sitemap
Route::get('/sitemap.xml', function () {
    $dom = new \DOMDocument('1.0', 'UTF-8');
    $dom->formatOutput = true;

    $xsl = $dom->createProcessingInstruction('xml-stylesheet', 'type="text/xsl" href="/sitemap.xsl"');
    $dom->appendChild($xsl);

    $urlset = $dom->createElementNS('http://www.sitemaps.org/schemas/sitemap/0.9', 'urlset');
    $urlset->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xhtml', 'http://www.w3.org/1999/xhtml');
    $dom->appendChild($urlset);

    foreach (SeoHelper::publicSitemapEntries() as $entry) {
        $urlNode = $dom->createElement('url');
        $urlset->appendChild($urlNode);

        $locNode = $dom->createElement('loc');
        $locNode->appendChild($dom->createTextNode((string) $entry['url']));
        $urlNode->appendChild($locNode);

        foreach ((array) ($entry['alternates'] ?? []) as $locale => $alternateUrl) {
            $alternateNode = $dom->createElementNS('http://www.w3.org/1999/xhtml', 'xhtml:link');
            $alternateNode->setAttribute('rel', 'alternate');
            $alternateNode->setAttribute('hreflang', (string) $locale);
            $alternateNode->setAttribute('href', (string) $alternateUrl);
            $urlNode->appendChild($alternateNode);
        }

        $lastmodNode = $dom->createElement('lastmod');
        $lastmodValue = $entry['lastmod'] instanceof Carbon
            ? $entry['lastmod']->toAtomString()
            : Carbon::parse((string) $entry['lastmod'])->toAtomString();
        $lastmodNode->appendChild($dom->createTextNode($lastmodValue));
        $urlNode->appendChild($lastmodNode);

        $changeFreqNode = $dom->createElement('changefreq');
        $changeFreqNode->appendChild($dom->createTextNode((string) $entry['change_frequency']));
        $urlNode->appendChild($changeFreqNode);

        $priorityNode = $dom->createElement('priority');
        $priorityNode->appendChild($dom->createTextNode(number_format((float) $entry['priority'], 1, '.', '')));
        $urlNode->appendChild($priorityNode);
    }

    return response($dom->saveXML(), 200, [
        'Content-Type' => 'application/xml; charset=UTF-8',
        'X-Content-Type-Options' => 'nosniff',
    ]);
})->name('sitemap');

// SEO: robots.txt
Route::get('/robots.txt', function () {
    $content = (string) SiteSetting::get('robots_txt', SeoHelper::defaultRobotsTxt());
    return response($content, 200)->header('Content-Type', 'text/plain');
})->name('robots');

// SEO: llms.txt
Route::get('/llms.txt', function () {
    if (!SeoHelper::llmsTxtEnabled()) {
        abort(404);
    }

    return response(SeoHelper::llmsTxtContent() . "\n", 200)
        ->header('Content-Type', 'text/plain; charset=UTF-8');
})->name('llms');

Route::get('/site.webmanifest', function () {
    return response()->json(
        SeoHelper::siteWebmanifest(),
        200,
        ['Content-Type' => 'application/manifest+json; charset=UTF-8']
    );
})->name('site.webmanifest');

Route::get('/favicon.ico', function () {
    $target = SeoHelper::versionedPrimaryFaviconUrl();

    abort_if($target === '', 404);

    return redirect()->away($target, 301);
})->name('favicon');

Route::get('/newsletter/unsubscribe', NewsletterUnsubscribeController::class)
    ->middleware('signed')
    ->name('newsletter.unsubscribe');

Route::group([
    'prefix' => LaravelLocalization::setLocale(),
    'middleware' => ['localize', 'localeSessionRedirect', 'localizationRedirect'],
], function (): void {
    Route::get('/', [HomeController::class, 'index'])->name('home');
    Route::get('/pricing', [HomeController::class, 'pricingPage'])->name('pricing');
    Route::get('/free-trial', [HomeController::class, 'startFreeTrial'])->name('trial.start');
    Route::get('/ai-text-to-speech', [HomeController::class, 'seoLandingPage'])->defaults('page', 'ai_text_to_speech')->name('seo.ai-text-to-speech');
    Route::get('/ai-voice-generator', [HomeController::class, 'seoLandingPage'])->defaults('page', 'ai_voice_generator')->name('seo.ai-voice-generator');
    Route::get('/text-to-speech-arabic', [HomeController::class, 'seoLandingPage'])->defaults('page', 'text_to_speech_arabic')->name('seo.text-to-speech-arabic');
    Route::get('/text-to-speech', [HomeController::class, 'seoLandingPage'])->defaults('page', 'text_to_speech')->name('seo.text-to-speech');
    Route::get('/arabic-dialects', [HomeController::class, 'dialectHubPage'])->name('seo.arabic-dialects');
    Route::get('/arabic-dialects/{dialect}', [HomeController::class, 'dialectPage'])
        ->whereIn('dialect', \App\Support\SeoDialectPages::slugs())
        ->name('seo.dialect');
    Route::get('/english-accents', [HomeController::class, 'accentHubPage'])->name('seo.english-accents');
    Route::get('/english-accents/{accent}', [HomeController::class, 'accentPage'])
        ->whereIn('accent', \App\Support\SeoEnglishPages::slugs())
        ->name('seo.accent');
    Route::view('/privacy-policy', 'legal.privacy')->name('legal.privacy');
    Route::view('/terms-of-service', 'legal.terms')->name('legal.terms');
    Route::view('/cookie-policy', 'legal.cookies')->name('legal.cookies');
    Route::get('/browsers-extension', [ExtensionController::class, 'show'])->name('browsers-extension.page');

    Route::get('/contact-us', [\App\Http\Controllers\ContactController::class, 'show'])->name('contact');
    Route::post('/contact-us', [\App\Http\Controllers\ContactController::class, 'submit'])->middleware('throttle:contact')->name('contact.submit');
    Route::get('/contact', fn () => redirect()->route('contact', status: 301));
    Route::post('/contact', [\App\Http\Controllers\ContactController::class, 'submit'])->middleware('throttle:contact');

    Route::middleware('guest')->group(function () {
        Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
        Route::post('/login', [AuthController::class, 'login'])
            ->middleware(['auth.method:email', 'throttle:login'])
            ->name('login.submit');

        Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
        Route::post('/register', [AuthController::class, 'register'])
            ->middleware(['auth.method:email', 'throttle:register'])
            ->name('register.submit');

        Route::get('/auth/social/redirect', [AuthController::class, 'redirectToGoogle'])
            ->middleware('auth.method:google')
            ->name('auth.social.redirect');
        Route::get('/auth/social/callback', [AuthController::class, 'handleGoogleCallback'])
            ->middleware('auth.method:google')
            ->name('auth.social.callback');
        // Backward-compatible aliases for existing OAuth app callback URLs.
        Route::get('/auth/google/redirect', [AuthController::class, 'redirectToGoogle'])
            ->middleware('auth.method:google');
        Route::get('/auth/google/callback', [AuthController::class, 'handleGoogleCallback'])
            ->middleware('auth.method:google');

        Route::post('/auth/phone/verify', [AuthController::class, 'phoneAuthenticate'])
            ->middleware(['auth.method:phone', 'throttle:auth-bridge'])
            ->name('auth.phone.verify');

        Route::post('/auth/firebase-google/verify', [AuthController::class, 'firebaseGoogleAuthenticate'])
            ->middleware(['auth.method:firebase_google', 'throttle:auth-bridge'])
            ->name('auth.firebase-google.verify');
    });

    // Referral capture runs BEFORE auth so guests landing on the demo page
    // with ?ref=CODE still record the visit and get the referral cookie set
    // before the login redirect.
    Route::middleware(['referral.capture'])->group(function () {
        Route::middleware(['auth', 'subscription.sync', 'demo.free'])->group(function () {
            Route::get('/demo', [HomeController::class, 'demoPage'])->name('demo.page');
            Route::post('/demo/quota', [HomeController::class, 'demoQuota'])->name('demo.quota');
            Route::post('/demo', [HomeController::class, 'demo'])->name('demo.run');
            Route::post('/preview', [HomeController::class, 'preview'])->name('demo.preview');
        });
    });

    Route::middleware(['auth'])->group(function () {
        Route::get('/referral/stats', [\App\Http\Controllers\ReferralController::class, 'stats'])->name('referral.stats');
        Route::post('/referral/share', [\App\Http\Controllers\ReferralController::class, 'share'])->name('referral.share');
    });

    Route::middleware(['auth', 'subscription.sync'])->group(function () {
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
        Route::post('/dashboard/queue', [DashboardController::class, 'queue'])->name('dashboard.queue');
        Route::post('/dashboard/generate', [DashboardController::class, 'queue'])->name('dashboard.generate');
        Route::delete('/dashboard/jobs/{job}', [DashboardController::class, 'destroy'])->name('dashboard.jobs.destroy');
        Route::delete('/dashboard/demo-jobs/{generation}', [DashboardController::class, 'destroyDemo'])->name('dashboard.demo-jobs.destroy');

        Route::get('/billing', [BillingController::class, 'index'])->name('billing.page');
        Route::get('/billing/manual', [ManualPaymentController::class, 'instructions'])->name('billing.manual.instructions');
        Route::post('/billing/manual/confirm', [ManualPaymentController::class, 'confirm'])->name('billing.manual.confirm');
        Route::get('/manage-subscription', [BillingController::class, 'manage'])->name('manage-subscription.page');
        Route::post('/billing/subscribe', [BillingController::class, 'subscribe'])->name('billing.subscribe');
        Route::post('/billing/trial/setup', [\App\Http\Controllers\TrialBillingController::class, 'setup'])->name('billing.trial.setup');
        Route::post('/billing/trial/confirm', [\App\Http\Controllers\TrialBillingController::class, 'confirm'])->name('billing.trial.confirm');
        Route::post('/billing/payment-method', [BillingController::class, 'updatePaymentMethod'])->name('billing.payment-method');
        Route::post('/billing/cancel', [BillingController::class, 'cancel'])->name('billing.cancel');
        Route::post('/billing/resume', [BillingController::class, 'resume'])->name('billing.resume');
        Route::post('/billing/sync', [BillingController::class, 'sync'])->name('billing.sync');
        Route::get('/billing/invoices/{invoiceId}/view', [BillingController::class, 'viewInvoice'])->name('billing.invoice.view');
        Route::get('/billing/invoices/{invoiceId}/download', [BillingController::class, 'downloadInvoice'])->name('billing.invoice.download');

        Route::post('/browsers-extension/api-key/regenerate', [ExtensionController::class, 'regenerateApiKey'])->name('browsers-extension.key.regenerate');
        Route::post('/browsers-extension/api-key/revoke', [ExtensionController::class, 'revokeApiKey'])->name('browsers-extension.key.revoke');
        Route::get('/browsers-extension/download', [ExtensionController::class, 'download'])->name('browsers-extension.download');

        // Voice Cloning lives under dashboard for signed-in users.
        Route::prefix('dashboard/voice-cloning')->group(function (): void {
            // Page accessible to all auth users (free users see upgrade prompt).
            Route::get('/', [VoiceCloningController::class, 'index'])->name('voice-cloning.index');
            // Actions require paid plan (enforced in controller).
            Route::post('/', [VoiceCloningController::class, 'store'])->name('voice-cloning.store');
            Route::get('/status/{id}', [VoiceCloningController::class, 'status'])->name('voice-cloning.status');
            Route::post('/{id}/retry', [VoiceCloningController::class, 'retry'])->name('voice-cloning.retry');
            Route::post('/{id}/consent', [VoiceCloningController::class, 'replaceConsent'])->name('voice-cloning.consent');
            Route::delete('/{id}', [VoiceCloningController::class, 'destroy'])->name('voice-cloning.destroy');
        });

        // Legacy user URL kept as redirect to dashboard voice-cloning section.
        Route::get('/voice-cloning', function () {
            $user = auth()->user();
            $plan = app(\App\Services\PlanAccessService::class)->resolveActivePlanForUserId((int) $user->id);

            if (! \App\Support\VoiceCloningAccess::allows($user, $plan) && ! \App\Support\VoiceCloningAccess::showsUpgrade($user, $plan)) {
                return redirect()->route('dashboard');
            }

            return redirect()->to(route('dashboard') . '#voiceCloningSection', 301);
        });
    });
});
