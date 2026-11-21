<?php

use App\Http\Controllers\Api\V1\AttachmentController;
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

        Route::post('/messages', [MessageController::class, 'store'])
            ->middleware(['client.ability:messages:send', 'client.rate:messages']);

        Route::get('/messages/{uuid}', [MessageController::class, 'show'])
            ->whereUuid('uuid')
            ->middleware(['client.ability:messages:read', 'client.rate:messages']);

        Route::post('/number-checks', [NumberCheckController::class, 'store'])
            ->middleware(['client.ability:numbers:check', 'client.rate:number-checks']);
    });
