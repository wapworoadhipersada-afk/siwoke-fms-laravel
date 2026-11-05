<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Document\Http\Api\V1\Controllers\AttachmentDownloadController;

Route::middleware(['auth:sanctum', 'mobile.session', 'throttle:mobile'])->group(function () {
    Route::get('attachments/{attachment}', [AttachmentDownloadController::class, 'show'])->name('attachments.show');
    Route::get('attachments/{attachment}/thumbnail', [AttachmentDownloadController::class, 'thumbnail'])->name('attachments.thumbnail');
});
