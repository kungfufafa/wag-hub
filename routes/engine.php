<?php

use App\Http\Controllers\Api\Engine\CesaEngineController;
use Illuminate\Support\Facades\Route;

$engine = static function (): void {
    Route::get('/health', [CesaEngineController::class, 'health']);
    Route::post('/sessions', [CesaEngineController::class, 'startSession'])
        ->middleware('client.rate:engine');
    Route::get('/sessions/{session}', [CesaEngineController::class, 'session'])
        ->middleware('client.rate:engine');
    Route::delete('/sessions/{session}', [CesaEngineController::class, 'logout'])
        ->middleware('client.rate:engine');
    Route::post('/sessions/{session}/send', [CesaEngineController::class, 'send'])
        ->middleware(['client.ability:messages:send', 'client.rate:engine']);
    Route::get('/sessions/{session}/messages/{key}', [CesaEngineController::class, 'message'])
        ->middleware('client.rate:engine');
};

Route::prefix('engine')
    ->middleware('client.auth')
    ->group($engine);

Route::prefix('engine/t/{engineToken}')
    ->middleware(['engine.path-token', 'client.auth'])
    ->group($engine);
