<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\AdminManualPaymentProofController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExtensionController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ManualPaymentController;
use App\Http\Controllers\MediaController;
use App\Models\SiteSetting;
use App\Support\SeoHelper;
use Carbon\Carbon;
use Illuminate\Support\Facades\Route;
use Mcamara\LaravelLocalization\Facades\LaravelLocalization;

Route::get('/media/{path}', [MediaController::class, 'show'])
    ->where('path', '.*')
    ->name('media.file');

Route::middleware('auth')->get('/admin/manual-payments/{manualPaymentRequest}/proof', AdminManualPaymentProofController::class)
    ->name('admin.manual-payments.proof');

// SEO: Sitemap
Route::get('/sitemap.xml', function () {
    $dom = new \DOMDocument('1.0', 'UTF-8');
    $dom->formatOutput = true;

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

Route::group([
    'prefix' => LaravelLocalization::setLocale(),
    'middleware' => ['localize', 'localeSessionRedirect', 'localizationRedirect'],
], function (): void {
    Route::get('/', [HomeController::class, 'index'])->name('home');
    Route::get('/pricing', [HomeController::class, 'pricingPage'])->name('pricing');
    Route::get('/ai-text-to-speech', [HomeController::class, 'seoLandingPage'])->defaults('page', 'ai_text_to_speech')->name('seo.ai-text-to-speech');
    Route::get('/ai-voice-generator', [HomeController::class, 'seoLandingPage'])->defaults('page', 'ai_voice_generator')->name('seo.ai-voice-generator');
    Route::get('/text-to-speech-arabic', [HomeController::class, 'seoLandingPage'])->defaults('page', 'text_to_speech_arabic')->name('seo.text-to-speech-arabic');
    Route::view('/privacy-policy', 'legal.privacy')->name('legal.privacy');
    Route::view('/terms-of-service', 'legal.terms')->name('legal.terms');
    Route::view('/cookie-policy', 'legal.cookies')->name('legal.cookies');
    Route::get('/browsers-extension', [ExtensionController::class, 'show'])->name('browsers-extension.page');

    Route::get('/contact-us', [\App\Http\Controllers\ContactController::class, 'show'])->name('contact');
    Route::post('/contact-us', [\App\Http\Controllers\ContactController::class, 'submit'])->name('contact.submit');
    Route::get('/contact', fn () => redirect()->route('contact', status: 301));
    Route::post('/contact', [\App\Http\Controllers\ContactController::class, 'submit']);

    Route::middleware('guest')->group(function () {
        Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
        Route::post('/login', [AuthController::class, 'login'])->name('login.submit');

        Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
        Route::post('/register', [AuthController::class, 'register'])->name('register.submit');

        Route::get('/auth/social/redirect', [AuthController::class, 'redirectToGoogle'])->name('auth.social.redirect');
        Route::get('/auth/social/callback', [AuthController::class, 'handleGoogleCallback'])->name('auth.social.callback');
        // Backward-compatible aliases for existing OAuth app callback URLs.
        Route::get('/auth/google/redirect', [AuthController::class, 'redirectToGoogle']);
        Route::get('/auth/google/callback', [AuthController::class, 'handleGoogleCallback']);
    });

    Route::middleware(['auth', 'subscription.sync', 'demo.free'])->group(function () {
        Route::get('/demo', [HomeController::class, 'demoPage'])->name('demo.page');
        Route::post('/demo', [HomeController::class, 'demo'])->name('demo.run');
        Route::post('/preview', [HomeController::class, 'preview'])->name('demo.preview');
    });

    Route::middleware(['auth', 'subscription.sync'])->group(function () {
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
        Route::post('/dashboard/queue', [DashboardController::class, 'queue'])->name('dashboard.queue');
        Route::post('/dashboard/generate', [DashboardController::class, 'queue'])->name('dashboard.generate');
        Route::delete('/dashboard/jobs/{job}', [DashboardController::class, 'destroy'])->name('dashboard.jobs.destroy');

        Route::get('/billing', [BillingController::class, 'index'])->name('billing.page');
        Route::get('/billing/manual', [ManualPaymentController::class, 'instructions'])->name('billing.manual.instructions');
        Route::post('/billing/manual/confirm', [ManualPaymentController::class, 'confirm'])->name('billing.manual.confirm');
        Route::get('/manage-subscription', [BillingController::class, 'manage'])->name('manage-subscription.page');
        Route::post('/billing/subscribe', [BillingController::class, 'subscribe'])->name('billing.subscribe');
        Route::post('/billing/payment-method', [BillingController::class, 'updatePaymentMethod'])->name('billing.payment-method');
        Route::post('/billing/cancel', [BillingController::class, 'cancel'])->name('billing.cancel');
        Route::post('/billing/resume', [BillingController::class, 'resume'])->name('billing.resume');
        Route::post('/billing/sync', [BillingController::class, 'sync'])->name('billing.sync');
        Route::get('/billing/invoices/{invoiceId}/view', [BillingController::class, 'viewInvoice'])->name('billing.invoice.view');
        Route::get('/billing/invoices/{invoiceId}/download', [BillingController::class, 'downloadInvoice'])->name('billing.invoice.download');

        Route::post('/browsers-extension/api-key/regenerate', [ExtensionController::class, 'regenerateApiKey'])->name('browsers-extension.key.regenerate');
        Route::post('/browsers-extension/api-key/revoke', [ExtensionController::class, 'revokeApiKey'])->name('browsers-extension.key.revoke');
        Route::get('/browsers-extension/download', [ExtensionController::class, 'download'])->name('browsers-extension.download');
    });
});
