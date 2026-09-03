<?php

use App\Http\Controllers\Webhooks\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

Route::match(['GET', 'POST'], '/webhooks/whatsapp/{provider}', WhatsAppWebhookController::class)
    ->whereUuid('provider')
    ->name('webhooks.whatsapp');
