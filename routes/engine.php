<?php

use App\Http\Controllers\Api\Engine\WhatsAppEngineController;
use Illuminate\Support\Facades\Route;

$engine = static function (): void {
    Route::middleware(['client.ability:engine:use', 'client.rate:engine'])->group(function (): void {
        Route::get('/health', [WhatsAppEngineController::class, 'health']);
        Route::post('/sessions', [WhatsAppEngineController::class, 'startSession']);
        Route::get('/sessions/{session}', [WhatsAppEngineController::class, 'session']);
        Route::delete('/sessions/{session}', [WhatsAppEngineController::class, 'logout']);
        Route::post('/sessions/{session}/send', [WhatsAppEngineController::class, 'send']);
        Route::get('/sessions/{session}/messages/{key}', [WhatsAppEngineController::class, 'message']);
    });
};

Route::prefix('api/v1/engine')
    ->middleware('client.auth')
    ->group($engine);

Route::prefix('engine')
    ->middleware('client.auth')
    ->group($engine);

Route::prefix('engine/t/{engineToken}')
    ->middleware(['engine.path-token', 'client.auth'])
    ->group($engine);
