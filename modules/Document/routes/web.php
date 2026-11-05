<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Document\Http\Web\Controllers\AttachmentController;

Route::middleware(['web', 'auth', 'verified'])->prefix('attachments')->name('attachments.')->group(function () {
    Route::get('{attachment}', [AttachmentController::class, 'show'])->name('show');
    Route::get('{attachment}/thumbnail', [AttachmentController::class, 'thumbnail'])->name('thumbnail');
});
