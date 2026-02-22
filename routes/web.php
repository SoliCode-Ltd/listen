<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExtensionController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\MediaController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/pricing', [HomeController::class, 'pricingPage'])->name('pricing');
Route::get('/media/{path}', [MediaController::class, 'show'])
    ->where('path', '.*')
    ->name('media.file');

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
