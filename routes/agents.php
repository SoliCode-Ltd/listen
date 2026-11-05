<?php

use App\Http\Controllers\AgentCallController;
use App\Http\Controllers\AgentReportController;
use Illuminate\Support\Facades\Route;

/*
| Listen Agents' calls: https://<site>/agent-api/... (bootstrap/app.php),
| reached from the websites that carry an agent's call button (AgentCors).
*/

Route::options('/{any}', fn () => response('', 204))->where('any', '.*');
Route::get('/widgets/{key}', [AgentCallController::class, 'widget'])->where('key', 'lw_[A-Za-z0-9]{28}')->middleware('throttle:120,1')->name('agent-api.widget');
// A caller waiting for a line tries again every few seconds (AgentCalls::start, WaitingLine).
Route::post('/calls', [AgentCallController::class, 'start'])->middleware('throttle:40,1')->name('agent-api.start');
// Every line busy: the caller leaves a number to be called back.
Route::post('/callbacks', [AgentCallController::class, 'callback'])->middleware('throttle:10,1')->name('agent-api.callback');
Route::post('/calls/{conversation}/ping', [AgentCallController::class, 'ping'])->where('conversation', 'cnv_[a-z0-9]{24}')->middleware('throttle:120,1')->name('agent-api.ping');
Route::post('/calls/{conversation}/tool', [AgentCallController::class, 'tool'])->where('conversation', 'cnv_[a-z0-9]{24}')->middleware('throttle:60,1')->name('agent-api.tool');
// A caller's line written again from their own voice (agent-call.js, when the call offers it).
Route::post('/calls/{conversation}/transcribe', [AgentCallController::class, 'transcribe'])->where('conversation', 'cnv_[a-z0-9]{24}')->middleware('throttle:90,1')->name('agent-api.transcribe');
Route::post('/calls/{conversation}/end', [AgentCallController::class, 'end'])->where('conversation', 'cnv_[a-z0-9]{24}')->middleware('throttle:60,1')->name('agent-api.end');
// "Report this agent" on a link page (one report per network a day, AgentReportController).
Route::post('/reports', [AgentReportController::class, 'store'])->middleware('throttle:10,1')->name('agent-api.report');
