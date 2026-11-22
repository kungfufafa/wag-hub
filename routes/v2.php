<?php

use App\Http\Controllers\Api\V2\ConnectionController;
use App\Http\Controllers\Api\V2\IntegrationController;
use App\Http\Controllers\Api\V2\SessionController;
use App\Http\Controllers\Api\V2\TransportController;
use Illuminate\Support\Facades\Route;

Route::get('/health/live', [IntegrationController::class, 'live']);
Route::get('/internal/engine/desired-sessions', [IntegrationController::class, 'desiredSessions']);
Route::get('/health/ready', [IntegrationController::class, 'ready'])->middleware(['client.auth', 'client.ability:integration:read']);

Route::prefix('api/v2')->middleware(['client.auth', 'client.rate:engine'])->name('engine.v2.')->group(function (): void {
    Route::get('integration', [IntegrationController::class, 'show'])->middleware('client.ability:integration:read');
    Route::patch('integration', [IntegrationController::class, 'update'])->name('integration.update')->middleware('client.ability:capacity:manage');
    Route::put('integration/webhook', [IntegrationController::class, 'webhook'])->name('webhook')->middleware('client.ability:webhooks:manage');
    Route::get('sessions', [SessionController::class, 'index'])->middleware('client.ability:sessions:read');
    Route::post('sessions', [SessionController::class, 'store'])->name('sessions.create')->middleware('client.ability:sessions:manage');
    Route::get('sessions/{session}', [SessionController::class, 'show'])->middleware('client.ability:sessions:read');
    Route::patch('sessions/{session}', [SessionController::class, 'rename'])->name('sessions.rename')->middleware('client.ability:sessions:manage');
    Route::post('sessions/{session}/{action}', [SessionController::class, 'lifecycle'])->whereIn('action', ['start', 'stop', 'logout'])->middleware('client.ability:sessions:manage');
    Route::delete('sessions/{session}', [SessionController::class, 'lifecycle'])->middleware('client.ability:sessions:manage');
    Route::get('sessions/{session}/auth/qr', [SessionController::class, 'qr'])->middleware('client.ability:sessions:manage');
    Route::post('sessions/{session}/auth/pairing-code', [SessionController::class, 'pairing'])->name('sessions.pairing')->middleware('client.ability:sessions:manage');
    Route::get('operations/{operation}', [SessionController::class, 'operation'])->middleware('client.ability:sessions:read');
    Route::post('sessions/{session}/messages', [TransportController::class, 'send'])->middleware('client.ability:messages:send');
    Route::post('messages', [ConnectionController::class, 'sendDefault'])->name('messages.send')->middleware('client.ability:messages:send');
    Route::get('messages/{message}', [TransportController::class, 'message'])->middleware('client.ability:messages:read');
    Route::post('media', [TransportController::class, 'upload'])->middleware('client.ability:media:write');
    Route::get('media/{media}', [TransportController::class, 'download'])->middleware('client.ability:media:read');
    Route::get('events', [TransportController::class, 'events'])->middleware('client.ability:messages:read');
    Route::get('connections', [ConnectionController::class, 'index'])->name('connections.index')->middleware('client.ability:sessions:read');
    Route::post('connections', [ConnectionController::class, 'store'])->name('connections.create')->middleware('client.ability:sessions:manage');
    Route::get('connections/{uuid}', [ConnectionController::class, 'show'])->whereUuid('uuid')->name('connections.show')->middleware('client.ability:sessions:read');
    Route::post('connections/{uuid}/connect', [ConnectionController::class, 'connect'])->whereUuid('uuid')->name('connections.connect')->middleware('client.ability:sessions:manage');
    Route::post('connections/{uuid}/retry', [ConnectionController::class, 'retry'])->whereUuid('uuid')->name('connections.retry')->middleware('client.ability:sessions:manage');
    Route::post('connections/{uuid}/messages', [ConnectionController::class, 'send'])->whereUuid('uuid')->name('connections.messages')->middleware('client.ability:messages:send');
});
