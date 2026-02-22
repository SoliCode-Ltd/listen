<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExtensionController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\MediaController;
use App\Models\SiteSetting;
use Illuminate\Support\Facades\Route;
use Spatie\Sitemap\Sitemap;
use Spatie\Sitemap\Tags\Url;

Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/pricing', [HomeController::class, 'pricingPage'])->name('pricing');
Route::view('/privacy-policy', 'legal.privacy')->name('legal.privacy');
Route::view('/terms-of-service', 'legal.terms')->name('legal.terms');
Route::view('/cookie-policy', 'legal.cookies')->name('legal.cookies');
Route::get('/media/{path}', [MediaController::class, 'show'])
    ->where('path', '.*')
    ->name('media.file');

// SEO: Sitemap
Route::get('/sitemap.xml', function () {
    $sitemap = Sitemap::create()
        ->add(Url::create('/')->setPriority(1.0)->setChangeFrequency('daily'))
        ->add(Url::create('/pricing')->setPriority(0.9)->setChangeFrequency('weekly'))
        ->add(Url::create('/demo')->setPriority(0.8)->setChangeFrequency('weekly'))
        ->add(Url::create('/login')->setPriority(0.5)->setChangeFrequency('monthly'))
        ->add(Url::create('/register')->setPriority(0.6)->setChangeFrequency('monthly'))
        ->add(Url::create('/privacy-policy')->setPriority(0.3)->setChangeFrequency('yearly'))
        ->add(Url::create('/terms-of-service')->setPriority(0.3)->setChangeFrequency('yearly'))
        ->add(Url::create('/cookie-policy')->setPriority(0.3)->setChangeFrequency('yearly'));

    return $sitemap->toResponse(request());
})->name('sitemap');

// SEO: robots.txt
Route::get('/robots.txt', function () {
    $content = SiteSetting::get('robots_txt', "User-agent: *\nAllow: /\n\nSitemap: " . url('/sitemap.xml'));
    return response($content, 200)->header('Content-Type', 'text/plain');
})->name('robots');

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
