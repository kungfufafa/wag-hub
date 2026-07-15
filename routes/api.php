<?php

use App\Http\Controllers\Api\V1\MessageController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')
    ->middleware('client.auth')
    ->group(function (): void {
        Route::post('/messages', [MessageController::class, 'store'])
            ->middleware('client.ability:messages:send');

        Route::get('/messages/{uuid}', [MessageController::class, 'show'])
            ->whereUuid('uuid')
            ->middleware('client.ability:messages:read');
    });
