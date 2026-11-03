<?php

use App\Http\Controllers\AgentCallController;
use Illuminate\Support\Facades\Route;

/*
| Listen Agents' calls: https://<site>/agent-api/... (bootstrap/app.php),
| reached from the websites that carry an agent's call button (AgentCors).
*/

Route::options('/{any}', fn () => response('', 204))->where('any', '.*');
Route::get('/widgets/{key}', [AgentCallController::class, 'widget'])->where('key', 'lw_[A-Za-z0-9]{28}')->middleware('throttle:120,1')->name('agent-api.widget');
Route::post('/calls', [AgentCallController::class, 'start'])->middleware('throttle:20,1')->name('agent-api.start');
Route::post('/calls/{conversation}/ping', [AgentCallController::class, 'ping'])->where('conversation', 'cnv_[a-z0-9]{24}')->middleware('throttle:120,1')->name('agent-api.ping');
Route::post('/calls/{conversation}/tool', [AgentCallController::class, 'tool'])->where('conversation', 'cnv_[a-z0-9]{24}')->middleware('throttle:60,1')->name('agent-api.tool');
Route::post('/calls/{conversation}/end', [AgentCallController::class, 'end'])->where('conversation', 'cnv_[a-z0-9]{24}')->middleware('throttle:60,1')->name('agent-api.end');
