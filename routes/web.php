<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExtensionController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\MediaController;
use App\Models\SiteSetting;
use App\Support\SeoHelper;
use Illuminate\Support\Facades\Route;
use Mcamara\LaravelLocalization\Facades\LaravelLocalization;
use Spatie\Sitemap\Sitemap;
use Spatie\Sitemap\Tags\Url;

Route::get('/media/{path}', [MediaController::class, 'show'])
    ->where('path', '.*')
    ->name('media.file');

// SEO: Sitemap
Route::get('/sitemap.xml', function () {
    $sitemap = Sitemap::create();

    foreach (SeoHelper::sitemapEntries() as $entry) {
        $sitemap->add(
            Url::create($entry['path'])
                ->setPriority((float) $entry['priority'])
                ->setChangeFrequency($entry['change_frequency'])
        );
    }

    return $sitemap->toResponse(request());
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

Route::group([
    'prefix' => LaravelLocalization::setLocale(),
    'middleware' => ['localize', 'localeSessionRedirect', 'localizationRedirect'],
], function (): void {
    Route::get('/', [HomeController::class, 'index'])->name('home');
    Route::get('/pricing', [HomeController::class, 'pricingPage'])->name('pricing');
    Route::view('/privacy-policy', 'legal.privacy')->name('legal.privacy');
    Route::view('/terms-of-service', 'legal.terms')->name('legal.terms');
    Route::view('/cookie-policy', 'legal.cookies')->name('legal.cookies');

    Route::get('/contact-us', [\App\Http\Controllers\ContactController::class, 'show'])->name('contact');
    Route::post('/contact-us', [\App\Http\Controllers\ContactController::class, 'submit'])->name('contact.submit');
    Route::get('/contact', fn () => redirect()->route('contact', status: 301));
    Route::post('/contact', [\App\Http\Controllers\ContactController::class, 'submit']);

    Route::middleware('guest')->group(function () {
        Route::get('/demo', [HomeController::class, 'demoPage'])->name('demo.page');
        Route::post('/demo', [HomeController::class, 'demo'])->name('demo.run');
        Route::post('/preview', [HomeController::class, 'preview'])->name('demo.preview');

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

    Route::middleware(['auth', 'subscription.sync'])->group(function () {
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
        Route::post('/dashboard/queue', [DashboardController::class, 'queue'])->name('dashboard.queue');
        Route::delete('/dashboard/jobs/{job}', [DashboardController::class, 'destroy'])->name('dashboard.jobs.destroy');

        Route::get('/billing', [BillingController::class, 'index'])->name('billing.page');
        Route::get('/manage-subscription', [BillingController::class, 'manage'])->name('manage-subscription.page');
        Route::post('/billing/subscribe', [BillingController::class, 'subscribe'])->name('billing.subscribe');
        Route::post('/billing/payment-method', [BillingController::class, 'updatePaymentMethod'])->name('billing.payment-method');
        Route::post('/billing/cancel', [BillingController::class, 'cancel'])->name('billing.cancel');
        Route::post('/billing/sync', [BillingController::class, 'sync'])->name('billing.sync');
        Route::get('/billing/invoices/{invoiceId}/view', [BillingController::class, 'viewInvoice'])->name('billing.invoice.view');
        Route::get('/billing/invoices/{invoiceId}/download', [BillingController::class, 'downloadInvoice'])->name('billing.invoice.download');

        Route::get('/browsers-extension', [ExtensionController::class, 'show'])->name('browsers-extension.page');
        Route::post('/browsers-extension/api-key/regenerate', [ExtensionController::class, 'regenerateApiKey'])->name('browsers-extension.key.regenerate');
        Route::post('/browsers-extension/api-key/revoke', [ExtensionController::class, 'revokeApiKey'])->name('browsers-extension.key.revoke');
        Route::get('/browsers-extension/download', [ExtensionController::class, 'download'])->name('browsers-extension.download');
    });
});
