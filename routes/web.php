<?php

use App\Http\Controllers\AttachmentDownloadController;
use App\Http\Middleware\EnsureAttachmentAccess;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/panel/login');

Route::get('/attachments/{uuid}/{filename?}', AttachmentDownloadController::class)
    ->whereUuid('uuid')
    ->where('filename', '[^/]+')
    ->middleware(['signed', EnsureAttachmentAccess::class])
    ->name('attachments.fetch');
