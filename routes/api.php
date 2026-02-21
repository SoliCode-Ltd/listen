<?php

use App\Http\Controllers\Api\MessageReaderController;
use Illuminate\Support\Facades\Route;

Route::post('/reader/queue', [MessageReaderController::class, 'queue']);
Route::get('/reader/jobs', [MessageReaderController::class, 'list']);
Route::get('/reader/profile', [MessageReaderController::class, 'profile']);
