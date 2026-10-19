<?php

use App\Http\Controllers\Api\ExtensionConfigController;
use App\Http\Controllers\Api\MessageReaderController;
use App\Http\Controllers\StripeWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/stripe/webhook', StripeWebhookController::class);

Route::middleware('throttle:extension-api')->group(function (): void {
    Route::post('/reader/queue', [MessageReaderController::class, 'queue'])->name('api.reader.queue');
    Route::get('/reader/jobs', [MessageReaderController::class, 'list'])->name('api.reader.jobs');
    Route::get('/reader/profile', [MessageReaderController::class, 'profile'])->name('api.reader.profile');
});

// Public endpoint for extension remote config (no auth required).
Route::get('/extension/config', [ExtensionConfigController::class, 'config']);
