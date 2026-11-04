<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Asset\Http\Api\V1\Controllers\MyAssetsController;
use Modules\Asset\Http\Api\V1\Controllers\MyHandoversController;

Route::middleware(['auth:sanctum', 'mobile.session', 'throttle:mobile'])->group(function () {
    Route::get('me/assets', [MyAssetsController::class, 'index'])->name('me.assets');

    Route::get('handovers', [MyHandoversController::class, 'index'])->name('handovers.index');
    Route::post('handovers/{handover}/accept', [MyHandoversController::class, 'accept'])->name('handovers.accept');
    Route::post('handovers/{handover}/reject', [MyHandoversController::class, 'reject'])->name('handovers.reject');
});
