<?php

use App\Http\Controllers\Api\V1\AttachmentController;
use App\Http\Controllers\Api\V1\ConnectionController;
use App\Http\Controllers\Api\V1\MessageController;
use App\Http\Controllers\Api\V1\NumberCheckController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')
    ->middleware('client.auth')
    ->group(function (): void {
        Route::post('/attachments', [AttachmentController::class, 'store'])
            ->middleware(['client.ability:messages:send', 'client.rate:attachments']);

        Route::get('/attachments/{uuid}', [AttachmentController::class, 'show'])
            ->whereUuid('uuid')
            ->middleware(['client.ability:messages:read', 'client.rate:messages']);

        Route::get('/connections', [ConnectionController::class, 'index'])
            ->middleware(['client.ability:messages:read', 'client.rate:messages']);

        Route::post('/connections', [ConnectionController::class, 'store'])
            ->middleware(['client.ability:engine:use', 'client.rate:engine']);

        Route::get('/connections/{connectionId}', [ConnectionController::class, 'show'])
            ->whereUuid('connectionId')
            ->middleware(['client.ability:messages:read', 'client.rate:messages']);

        Route::post('/connections/{connectionId}/setup', [ConnectionController::class, 'setup'])
            ->whereUuid('connectionId')
            ->middleware(['client.ability:engine:use', 'client.rate:engine']);

        Route::post('/connections/{connectionId}/test', [ConnectionController::class, 'test'])
            ->whereUuid('connectionId')
            ->middleware(['client.ability:messages:send', 'client.rate:messages']);

        Route::get('/connections/{connectionId}/integration', [ConnectionController::class, 'integration'])
            ->whereUuid('connectionId')
            ->middleware(['client.ability:messages:read', 'client.rate:messages']);

        Route::get('/connections/{connectionId}/fallbacks', [ConnectionController::class, 'fallbacks'])
            ->whereUuid('connectionId')
            ->middleware(['client.ability:messages:read', 'client.rate:messages']);

        Route::post('/connections/{connectionId}/fallbacks', [ConnectionController::class, 'addFallback'])
            ->whereUuid('connectionId')
            ->middleware(['client.ability:engine:use', 'client.rate:engine']);

        Route::post('/messages', [MessageController::class, 'store'])
            ->middleware(['client.ability:messages:send', 'client.rate:messages']);

        Route::get('/messages/{uuid}', [MessageController::class, 'show'])
            ->whereUuid('uuid')
            ->middleware(['client.ability:messages:read', 'client.rate:messages']);

        Route::post('/number-checks', [NumberCheckController::class, 'store'])
            ->middleware(['client.ability:numbers:check', 'client.rate:number-checks']);
    });
