<?php

use App\Http\Controllers\Api\Engine\CesaEngineController;
use Illuminate\Support\Facades\Route;

$engine = static function (): void {
    Route::middleware(['client.ability:engine:use', 'client.rate:engine'])->group(function (): void {
        Route::get('/health', [CesaEngineController::class, 'health']);
        Route::post('/sessions', [CesaEngineController::class, 'startSession']);
        Route::get('/sessions/{session}', [CesaEngineController::class, 'session']);
        Route::delete('/sessions/{session}', [CesaEngineController::class, 'logout']);
        Route::post('/sessions/{session}/send', [CesaEngineController::class, 'send']);
        Route::get('/sessions/{session}/messages/{key}', [CesaEngineController::class, 'message']);
    });
};

Route::prefix('engine')
    ->middleware('client.auth')
    ->group($engine);

Route::prefix('engine/t/{engineToken}')
    ->middleware(['engine.path-token', 'client.auth'])
    ->group($engine);
