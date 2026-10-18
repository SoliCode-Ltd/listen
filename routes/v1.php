<?php

use App\Http\Controllers\Api\V1\HistoryController;
use App\Http\Controllers\Api\V1\ModelController;
use App\Http\Controllers\Api\V1\OpenApiController;
use App\Http\Controllers\Api\V1\TextToSpeechController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\VoiceController;
use App\Support\Api\ApiScopes;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Developer API, version 1 (https://<site>/v1/...)
|--------------------------------------------------------------------------
|
| Registered from bootstrap/app.php with the "api" middleware group and
| ApiRequestContext. Documented at /developers and in /v1/openapi.json
| (App\Support\Api\OpenApiSpec): keep the three in step.
|
*/

Route::get('/openapi.json', OpenApiController::class)->name('v1.openapi');

Route::middleware(['api.key', 'throttle:public-api'])->group(function (): void {
    Route::post('/text-to-speech/{voiceId}', [TextToSpeechController::class, 'convert'])
        ->middleware('api.scope:'.ApiScopes::TEXT_TO_SPEECH)->name('v1.tts');
    Route::post('/text-to-speech/{voiceId}/async', [TextToSpeechController::class, 'convertAsync'])
        ->middleware('api.scope:'.ApiScopes::TEXT_TO_SPEECH)->name('v1.tts.async');

    Route::middleware('api.scope:'.ApiScopes::VOICES_READ)->group(function (): void {
        Route::get('/voices', [VoiceController::class, 'index'])->name('v1.voices');
        Route::get('/voices/{voiceId}', [VoiceController::class, 'show'])->name('v1.voices.show');
        Route::get('/voice-options', [VoiceController::class, 'options'])->name('v1.voice-options');
        Route::get('/models', [ModelController::class, 'index'])->name('v1.models');
    });

    Route::middleware('api.scope:'.ApiScopes::HISTORY_READ)->group(function (): void {
        Route::get('/history', [HistoryController::class, 'index'])->name('v1.history');
        Route::get('/history/{historyItemId}', [HistoryController::class, 'show'])->name('v1.history.show');
        Route::get('/history/{historyItemId}/audio', [HistoryController::class, 'audio'])->name('v1.history.audio');
    });
    Route::delete('/history/{historyItemId}', [HistoryController::class, 'destroy'])
        ->middleware('api.scope:'.ApiScopes::HISTORY_WRITE)->name('v1.history.destroy');

    Route::middleware('api.scope:'.ApiScopes::USER_READ)->group(function (): void {
        Route::get('/user', [UserController::class, 'show'])->name('v1.user');
        Route::get('/user/subscription', [UserController::class, 'subscription'])->name('v1.user.subscription');
    });
});
